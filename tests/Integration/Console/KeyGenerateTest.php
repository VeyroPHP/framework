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
use VeyroPHP\Encryption\Console\KeyGenerateCommand;
use VeyroPHP\Encryption\Encrypter;

#[CoversClass(KeyGenerateCommand::class)]
final class KeyGenerateTest extends TestCase
{
    private string $root;

    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-key-' . bin2hex(random_bytes(4));
        mkdir($this->root);

        $this->app = new Application($this->root);
        $this->app->container()->instance(Config::class, new Config([]));
        $this->app->register(new ConsoleServiceProvider());
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/bootstrap/cache/config.php', $this->root . '/.env'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([$this->root . '/bootstrap/cache', $this->root . '/bootstrap', $this->root] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
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

    private function env(): string
    {
        return (string) file_get_contents($this->root . '/.env');
    }

    private static function value(string $env, string $name): string
    {
        self::assertSame(1, preg_match('/^' . $name . '=([^\r\n]*)/m', $env, $m), $name . ' missing');

        return $m[1];
    }

    public function testFillsEmptyKeyAndKeepsTheRestOfTheFile(): void
    {
        file_put_contents($this->root . '/.env', "APP_NAME=Shop\r\nAPP_KEY=\r\nAPP_DEBUG=true\r\n");

        [$code, $out] = $this->exec(['key:generate']);

        self::assertSame(0, $code);
        self::assertStringContainsString('APP_KEY created', $out);
        $key = self::value($this->env(), 'APP_KEY');
        self::assertSame(32, strlen(Encrypter::parseKey($key)));
        self::assertSame("APP_NAME=Shop\r\nAPP_KEY={$key}\r\nAPP_DEBUG=true\r\n", $this->env(), 'other lines and CRLF endings unchanged');
        self::assertStringNotContainsString('APP_PREVIOUS_KEYS', $this->env());
    }

    public function testAppendsKeyWhenTheLineIsMissing(): void
    {
        file_put_contents($this->root . '/.env', 'APP_NAME=Shop');

        self::assertSame(0, $this->exec(['key:generate'])[0]);
        self::assertStringStartsWith("APP_NAME=Shop\nAPP_KEY=base64:", $this->env());
    }

    public function testRefusesToReplaceAKeyWithoutForce(): void
    {
        $existing = Encrypter::generateKey();
        file_put_contents($this->root . '/.env', "APP_KEY={$existing}\n");

        [$code, , $err] = $this->exec(['key:generate']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--force', $err);
        self::assertSame("APP_KEY={$existing}\n", $this->env());
    }

    public function testForceRotatesAndOldDataStaysReadable(): void
    {
        $first = Encrypter::generateKey();
        $older = Encrypter::generateKey();
        file_put_contents($this->root . '/.env', "APP_KEY=\"{$first}\"\nAPP_PREVIOUS_KEYS={$older}\n");
        $token = (new Encrypter(Encrypter::parseKey($first)))->encrypt('order 42');

        [$code, $out] = $this->exec(['key:generate', '--force']);

        self::assertSame(0, $code);
        self::assertStringContainsString('rotated', $out);
        $env = $this->env();
        $new = self::value($env, 'APP_KEY');
        self::assertNotSame($first, $new);
        self::assertSame($first . ',' . $older, self::value($env, 'APP_PREVIOUS_KEYS'), 'newest old key first, older ones kept');

        $previous = array_map(Encrypter::parseKey(...), explode(',', self::value($env, 'APP_PREVIOUS_KEYS')));
        self::assertSame('order 42', (new Encrypter(Encrypter::parseKey($new), $previous))->decrypt($token));
    }

    public function testShowPrintsAKeyAndWritesNothing(): void
    {
        [$code, $out] = $this->exec(['key:generate', '--show']);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=\R$#', $out);
        self::assertFileDoesNotExist($this->root . '/.env');
    }

    public function testMissingEnvFile(): void
    {
        [$code, , $err] = $this->exec(['key:generate']);

        self::assertSame(1, $code);
        self::assertStringContainsString('.env not found', $err);
    }

    public function testWarnsWhenConfigIsCached(): void
    {
        file_put_contents($this->root . '/.env', "APP_KEY=\n");
        mkdir($this->root . '/bootstrap/cache', 0777, true);
        file_put_contents($this->root . '/bootstrap/cache/config.php', '<?php return [];');

        self::assertStringContainsString('config:cache', $this->exec(['key:generate'])[1]);
    }
}
