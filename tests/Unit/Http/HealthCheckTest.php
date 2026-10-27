<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\Health\HealthCheck;
use VeyroPHP\Http\Health\HealthEndpoint;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\Maintenance\MaintenanceMode;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;

#[CoversClass(HealthCheck::class)]
#[CoversClass(HealthEndpoint::class)]
final class HealthCheckTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;

    protected function setUp(): void
    {
        $this->logged = new \ArrayObject();
    }

    private function check(): HealthCheck
    {
        return new HealthCheck(new class ($this->logged) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $logged */
            public function __construct(private readonly \ArrayObject $logged) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $check = $context['check'] ?? '';
                $error = $context['error'] ?? '';
                $this->logged[] = (is_string($level) ? $level : '') . ' ' . strtr((string) $message, ['{check}' => is_string($check) ? $check : '', '{error}' => is_string($error) ? $error : '']);
            }
        });
    }

    public function testAllChecksPassing(): void
    {
        $health = $this->check();
        $health->add('database', static function (): void {});
        $health->add('cache', static function (): void {});

        $report = $health->run();

        self::assertTrue($report->healthy());
        self::assertSame(['database', 'cache'], array_keys($report->results()));
        self::assertTrue($report->results()['cache']['ok']);
        self::assertNull($report->results()['cache']['error']);
        self::assertGreaterThanOrEqual(0.0, $report->results()['cache']['ms']);
    }

    public function testAFailingCheckIsReportedAndLoggedAndOthersStillRun(): void
    {
        $health = $this->check();
        $ran = false;
        $health->add('database', static fn() => throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused (db.internal:3306)'));
        $health->add('cache', static function () use (&$ran): void {
            $ran = true;
        });

        $report = $health->run();

        self::assertFalse($report->healthy());
        self::assertTrue($ran);
        self::assertFalse($report->results()['database']['ok']);
        self::assertStringContainsString('Connection refused', (string) $report->results()['database']['error']);
        self::assertSame(['error Health check database failed: SQLSTATE[HY000] [2002] Connection refused (db.internal:3306)'], $this->logged->getArrayCopy());
    }

    public function testNamesAreValidatedAndUnique(): void
    {
        $health = $this->check();
        $health->add('queue', static function (): void {});

        try {
            $health->add('queue', static function (): void {});
            self::fail('duplicate accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $health->add('Bad Name', static function (): void {});
    }

    public function testNoChecksIsHealthy(): void
    {
        self::assertTrue($this->check()->run()->healthy());
    }

    /**
     * @return array{int, array<string, mixed>, string}
     */
    private function endpoint(HealthCheck $health, ?MaintenanceMode $mode = null): array
    {
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);
        $container = new Container();
        $container->instance(HealthCheck::class, $health);
        $container->instance(ResponseFactory::class, $responses);

        if ($mode !== null) {
            $container->instance(MaintenanceMode::class, $mode);
        }

        $router = new Router();
        $router->get('/up', HealthEndpoint::class);
        $kernel = new HttpKernel($router, $container, new HandlerInvoker($container, $responses), new HttpErrorResponder($responses), $responses);
        $response = $kernel->handle(new ServerRequest('GET', '/up'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return [$response->getStatusCode(), $body, $response->getHeaderLine('Cache-Control')];
    }

    public function testEndpointReportsOkWithoutDetails(): void
    {
        $health = $this->check();
        $health->add('database', static function (): void {});

        [$status, $body, $cache] = $this->endpoint($health);

        self::assertSame(200, $status);
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'ok']], $body);
        self::assertSame('no-store', $cache);
    }

    public function testEndpointHidesErrorMessages(): void
    {
        $health = $this->check();
        $health->add('database', static fn() => throw new \RuntimeException('password=hunter2 host=db.internal'));

        [$status, $body] = $this->endpoint($health);

        self::assertSame(503, $status);
        self::assertSame(['status' => 'fail', 'checks' => ['database' => 'fail']], $body);
        self::assertStringNotContainsString('hunter2', (string) json_encode($body));
    }

    public function testEndpointSaysWhenTheAppIsDownForMaintenance(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-health-' . bin2hex(random_bytes(4)) . '.json';
        $mode = new MaintenanceMode($file);
        $mode->down();

        try {
            [$status, $body] = $this->endpoint($this->check(), $mode);
        } finally {
            $mode->up();
        }

        self::assertSame(200, $status, 'the application itself is healthy');
        self::assertTrue($body['maintenance'] ?? null);
    }
}
