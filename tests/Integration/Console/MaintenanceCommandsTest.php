<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Http\Console\DownCommand;
use VeyroPHP\Http\Console\UpCommand;
use VeyroPHP\Http\Maintenance\MaintenanceMode;

#[CoversClass(DownCommand::class)]
#[CoversClass(UpCommand::class)]
final class MaintenanceCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-down-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/storage/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root . '/storage');
        rmdir($this->root);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function exec(array $arguments): array
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config(['app' => ['env' => 'local', 'url' => 'https://shop.example']]));
        $app->register(new ConsoleServiceProvider());
        $app->boot();

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = $app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function mode(): MaintenanceMode
    {
        return new MaintenanceMode($this->root . '/' . MaintenanceMode::FILE);
    }

    public function testDownAndUp(): void
    {
        [$code, $out] = $this->exec(['down', '--retry=300', '--message=Database upgrade']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('down for maintenance', $out);
        $state = $this->mode()->state();
        self::assertNotNull($state);
        self::assertSame(300, $state['retry']);
        self::assertSame('Database upgrade', $state['message']);
        self::assertFalse($state['has_secret']);

        [$code, $out] = $this->exec(['up']);
        self::assertSame(0, $code);
        self::assertStringContainsString('live', $out);
        self::assertFalse($this->mode()->isDown());

        [$code, $out] = $this->exec(['up']);
        self::assertSame(0, $code);
        self::assertStringContainsString('was not down', $out);
    }

    public function testSecretIsPrintedOnceAsALinkFromAppUrl(): void
    {
        [$code, $out] = $this->exec(['down', '--secret']);

        self::assertSame(0, $code);
        self::assertSame(1, preg_match('#https://shop\.example/_maintenance/([A-Za-z0-9_-]{43})#', $out, $m), $out);
        self::assertTrue($this->mode()->matchesSecret($m[1] ?? ''));
    }

    public function testAllowListIsValidated(): void
    {
        [$code, , $err] = $this->exec(['down', '--allow=10.0.0.0/8,not-an-ip']);

        self::assertSame(2, $code);
        self::assertStringContainsString('not-an-ip', $err);
        self::assertFalse($this->mode()->isDown());

        [$code] = $this->exec(['down', '--allow=10.0.0.0/8, 2001:db8::/32']);
        self::assertSame(0, $code);
        self::assertSame(['10.0.0.0/8', '2001:db8::/32'], $this->mode()->state()['allow'] ?? null);
    }

    public function testInvalidRetryIsRejected(): void
    {
        [$code] = $this->exec(['down', '--retry=soon']);

        self::assertSame(2, $code);
        self::assertFalse($this->mode()->isDown());
    }
}
