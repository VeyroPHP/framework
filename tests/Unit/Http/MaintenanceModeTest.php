<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\Maintenance\BlockDuringMaintenance;
use VeyroPHP\Http\Maintenance\MaintenanceMode;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\Tests\Fixtures\Http\CountingFileStream;

#[CoversClass(MaintenanceMode::class)]
#[CoversClass(BlockDuringMaintenance::class)]
final class MaintenanceModeTest extends TestCase
{
    private string $file;

    private MaintenanceMode $mode;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-maint-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR . 'maintenance.json';
        $this->mode = new MaintenanceMode($this->file, static fn(): int => 1_800_000_000);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        if (is_dir(dirname($this->file))) {
            rmdir(dirname($this->file));
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function send(string $path, array $headers = [], array $cookies = [], string $ip = '203.0.113.5', string $method = 'GET'): ResponseInterface
    {
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);
        $container = new Container();
        $router = new Router();
        $router->get('/', static fn(): string => 'home');
        $router->get('/up', static fn(): string => 'healthy');
        $router->get('/admin', static fn(): string => 'admin');

        $kernel = new HttpKernel($router, $container, new HandlerInvoker($container, $responses), new HttpErrorResponder($responses), $responses, [
            new BlockDuringMaintenance($this->mode, new ClientIp(), $responses),
        ]);

        return $kernel->handle((new ServerRequest($method, $path, $headers, null, '1.1', ['REMOTE_ADDR' => $ip]))->withCookieParams($cookies));
    }

    public function testUpByDefault(): void
    {
        self::assertFalse($this->mode->isDown());
        self::assertSame('home', (string) $this->send('/')->getBody());
    }

    public function testDownAnswers503WithRetryAfterAndNoStore(): void
    {
        $this->mode->down(retryAfter: 120, message: 'Back at 10 pm.');

        $response = $this->send('/');

        self::assertTrue($this->mode->isDown());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('120', $response->getHeaderLine('Retry-After'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Back at 10 pm.', (string) $response->getBody());

        $json = $this->send('/', ['Accept' => 'application/json']);
        self::assertSame(503, $json->getStatusCode());
        self::assertStringContainsString('"status":503', (string) $json->getBody());
    }

    public function testTheHealthCheckPathStillAnswers(): void
    {
        $this->mode->down();

        self::assertSame('healthy', (string) $this->send('/up')->getBody());
        self::assertSame(503, $this->send('/upx')->getStatusCode());
    }

    public function testAllowedAddressesAndRangesGetThrough(): void
    {
        $this->mode->down(allow: ['198.51.100.7', '10.0.0.0/8']);

        self::assertSame('home', (string) $this->send('/', ip: '198.51.100.7')->getBody());
        self::assertSame('home', (string) $this->send('/', ip: '10.2.3.4')->getBody());
        self::assertSame(503, $this->send('/', ip: '198.51.100.8')->getStatusCode());
    }

    public function testInvalidAllowEntriesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mode->down(allow: ['not-an-ip']);
    }

    /**
     * HTTP-7: the allow list and trusted proxies use one validator, so an entry is valid
     * for both or for neither.
     */
    public function testAllowEntriesAndProxyRangesShareOneValidator(): void
    {
        $valid = ['198.51.100.7', '10.0.0.0/8', '10.0.0.0/0', '10.0.0.0/32', '2001:db8::/32', '::1', '::1/128'];
        $invalid = ['', 'not-an-ip', '10.0.0.0/33', '10.0.0.0/', '10.0.0.0/-1', '10.0.0.0/8 ', ' 10.0.0.1', '2001:db8::/129', '10.0.0.0/8/8', '10.0.0.0/x', '300.1.1.1'];

        foreach ($valid as $range) {
            self::assertTrue(ClientIp::isValidRange($range), $range);
            $this->mode->down(allow: [$range]);
        }

        foreach ($invalid as $range) {
            self::assertFalse(ClientIp::isValidRange($range), var_export($range, true));

            try {
                $this->mode->down(allow: [$range]);
                self::fail('down() accepted ' . var_export($range, true));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    /**
     * HTTP-7: a blocked request reads the maintenance file once, not once per question
     * (allowed? secret? retry and message?).
     */
    public function testABlockedRequestReadsTheStateOnce(): void
    {
        $this->mode->down(retryAfter: 30, message: 'Soon.', allow: ['10.0.0.0/8'], withSecret: true);
        stream_wrapper_register('counting', CountingFileStream::class);

        try {
            CountingFileStream::$files = ['counting://maintenance.json' => (string) file_get_contents($this->file)];
            CountingFileStream::$opens = [];
            $this->mode = new MaintenanceMode('counting://maintenance.json');

            $response = $this->send('/', cookies: [BlockDuringMaintenance::COOKIE => 'not-the-secret']);

            self::assertSame(503, $response->getStatusCode());
            self::assertSame('30', $response->getHeaderLine('Retry-After'));
            self::assertSame(['counting://maintenance.json' => 1], CountingFileStream::$opens);
        } finally {
            stream_wrapper_unregister('counting');
        }
    }

    public function testTheSecretSetsABypassCookieAndIsStoredOnlyAsAHash(): void
    {
        $secret = $this->mode->down(withSecret: true);
        self::assertIsString($secret);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $secret);
        self::assertStringNotContainsString($secret, (string) file_get_contents($this->file));

        $bypass = $this->send('/_maintenance/' . $secret);
        self::assertSame(303, $bypass->getStatusCode());
        self::assertSame('/', $bypass->getHeaderLine('Location'));
        $cookie = $bypass->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith('veyrophp_maintenance=', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);

        self::assertSame('admin', (string) $this->send('/admin', cookies: ['veyrophp_maintenance' => $secret])->getBody());
    }

    public function testAWrongSecretOrCookieGetsTheNormal503(): void
    {
        $this->mode->down(withSecret: true);

        $wrong = $this->send('/_maintenance/' . str_repeat('A', 43));
        self::assertSame(503, $wrong->getStatusCode());
        self::assertSame([], $wrong->getHeader('Set-Cookie'));
        self::assertSame(503, $this->send('/admin', cookies: ['veyrophp_maintenance' => 'guess'])->getStatusCode());
    }

    public function testWithoutASecretTheBypassPathIsJustBlocked(): void
    {
        $this->mode->down();

        self::assertSame(503, $this->send('/_maintenance/' . str_repeat('A', 43))->getStatusCode());
    }

    public function testANewDownReplacesTheOldSecret(): void
    {
        $old = $this->mode->down(withSecret: true);
        $this->mode->down(withSecret: true);

        self::assertIsString($old);
        self::assertSame(503, $this->send('/admin', cookies: ['veyrophp_maintenance' => $old])->getStatusCode());
    }

    public function testUpRemovesTheFile(): void
    {
        $this->mode->down();

        self::assertTrue($this->mode->up());
        self::assertFalse($this->mode->isDown());
        self::assertFalse($this->mode->up(), 'already up');
        self::assertSame('home', (string) $this->send('/')->getBody());
    }

    public function testACorruptFileCountsAsDownSafely(): void
    {
        mkdir(dirname($this->file), 0o777, true);
        file_put_contents($this->file, '{broken');

        self::assertTrue($this->mode->isDown());
        $response = $this->send('/');
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    public function testStateIsReported(): void
    {
        $this->mode->down(retryAfter: 30, message: 'Upgrade', allow: ['127.0.0.1'], withSecret: true);
        $state = $this->mode->state();

        self::assertNotNull($state);
        self::assertSame(1_800_000_000, $state['since']);
        self::assertSame(30, $state['retry']);
        self::assertSame('Upgrade', $state['message']);
        self::assertSame(['127.0.0.1'], $state['allow']);
        self::assertTrue($state['has_secret']);
    }
}
