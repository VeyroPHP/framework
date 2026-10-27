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
use VeyroPHP\Mail\Console\MailTestCommand;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailServiceProvider;
use VeyroPHP\Mail\Transport\ArrayTransport;

#[CoversClass(MailTestCommand::class)]
final class MailTestCommandTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->app->container()->instance(Config::class, new Config(['mail' => [
            'default' => 'array',
            'from' => ['address' => 'shop@example.com'],
            'mailers' => [
                'array' => ['transport' => 'array'],
                // Nothing listens on port 1, so connecting fails at once.
                'down' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'timeout' => 2],
            ],
        ]]));
        $this->app->register(new MailServiceProvider());
        $this->app->register(new ConsoleServiceProvider([MailTestCommand::class]));
        $this->app->boot();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function exec(array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $this->app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testSendsATestMessage(): void
    {
        [$code, $out] = $this->exec(['mail:test', 'asha@example.com']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Sent with mailer "array"', $out);
        self::assertStringContainsString('does not deliver mail', $out);

        $transport = $this->app->container()->make(Mailer::class)->transport();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertSame('VeyroPHP test mail', $transport->last()?->message->getSubject());
        self::assertSame(['asha@example.com'], $transport->last()->recipients);
    }

    public function testConnectionProblemIsAFailure(): void
    {
        [$code, , $err] = $this->exec(['mail:test', 'asha@example.com', '--mailer=down']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Could not connect to the SMTP server 127.0.0.1:1', $err);
    }

    public function testInvalidAddressOrMailerIsAUsageError(): void
    {
        [$code, , $err] = $this->exec(['mail:test', 'not-an-address']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Invalid email address', $err);

        [$code, , $err] = $this->exec(['mail:test', 'asha@example.com', '--mailer=missing']);
        self::assertSame(2, $code);
        self::assertStringContainsString('No mailer "missing"', $err);
    }
}
