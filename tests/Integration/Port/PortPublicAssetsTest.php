<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Port;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Testing\TestApplication;

/**
 * port:convert --only=public in temporary directories: only static files reach
 * the target's web root (VQ-016), and no file is written truncated (VQ-048).
 */
final class PortPublicAssetsTest extends TestCase
{
    private TestApplication $test;

    private string $base;

    private string $source;

    private string $target;

    protected function setUp(): void
    {
        $this->test = TestApplication::boot();
        $this->base = sys_get_temp_dir() . '/veyrophp-port-public-' . bin2hex(random_bytes(4));
        $this->source = $this->base . '/source';
        $this->target = $this->base . '/target';

        foreach (['/public/css', '/public/tools', '/public/sub', '/public/img', '/routes'] as $dir) {
            mkdir($this->source . $dir, 0777, true);
        }
        mkdir($this->target . '/public/css', 0777, true);

        file_put_contents($this->source . '/composer.json', '{"require": {"laravel/framework": "^12.0"}}');
        file_put_contents($this->source . '/artisan', "<?php\n");
        file_put_contents($this->source . '/routes/web.php', "<?php\n");

        $files = [
            'public/css/app.css' => 'body{}',
            'public/css/site.css' => 'p{}',
            'public/robots.txt' => 'User-agent: *',
            'public/tools/adminer.php' => '<?php phpinfo();',
            'public/x.PHTML' => '<?php echo 1;',
            'public/shell.php5' => '<?php echo 1;',
            'public/sub/.htaccess' => 'AddHandler application/x-httpd-php .jpg',
            'public/.user.ini' => 'auto_prepend_file=/tmp/x',
            'public/sub/web.config' => '<configuration/>',
            'public/img/exact.bin' => str_repeat('a', 2_000_000),
            'public/img/big.bin' => str_repeat('b', 2_000_001),
        ];
        foreach ($files as $path => $content) {
            file_put_contents($this->source . '/' . $path, $content);
        }

        file_put_contents($this->target . '/veyro', "<?php\n");
        file_put_contents($this->target . '/composer.json', '{"require": {"veyrophp/core": "0.1.x-dev"}}');
        file_put_contents($this->target . '/public/css/site.css', 'the target\'s own file');
    }

    protected function tearDown(): void
    {
        $this->test->cleanUp();
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $path = $item->getPathname();

            if ($item->isLink()) {
                // Remove the link itself, never what it points to.
                is_dir($path) && PHP_OS_FAMILY === 'Windows' ? rmdir($path) : unlink($path);
            } elseif ($item->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($this->base);
    }

    /**
     * @return array{int, array<mixed>}
     */
    private function convert(): array
    {
        [$code, $out, $err] = $this->test->command(['port:convert', $this->source, $this->target, '--only=public', '--format=json']);
        $report = json_decode($out, true);
        self::assertIsArray($report, $out . $err);

        return [$code, $report];
    }

    /**
     * @param array<mixed> $report
     *
     * @return array<string, string> target => status
     */
    private static function statuses(array $report): array
    {
        $statuses = [];
        self::assertIsArray($report['files'] ?? null);

        foreach ($report['files'] as $file) {
            self::assertIsArray($file);
            self::assertIsString($file['target'] ?? null);
            self::assertIsString($file['status'] ?? null);
            $statuses[$file['target']] = $file['status'];
        }

        ksort($statuses);

        return $statuses;
    }

    public function testOnlyStaticFilesAreCopiedAndNothingIsTruncated(): void
    {
        $hashes = [];
        foreach (['public/css/app.css', 'public/tools/adminer.php', 'public/img/big.bin', 'public/img/exact.bin'] as $file) {
            $hashes[$file] = hash_file('sha256', $this->source . '/' . $file);
        }

        [$code, $report] = $this->convert();

        self::assertSame(0, $code);
        self::assertSame([
            'public/css/app.css' => 'written',
            'public/css/site.css' => 'exists',
            'public/img/exact.bin' => 'written',
            'public/robots.txt' => 'written',
        ], self::statuses($report));

        // VQ-016: nothing that a web server could execute or that configures it.
        foreach (['public/tools/adminer.php', 'public/x.PHTML', 'public/shell.php5', 'public/sub/.htaccess', 'public/.user.ini', 'public/sub/web.config'] as $file) {
            self::assertFileDoesNotExist($this->target . '/' . $file);
        }

        // VQ-048: the file at the limit is copied whole; the larger one is skipped, not written empty.
        self::assertSame(2_000_000, filesize($this->target . '/public/img/exact.bin'));
        self::assertFileDoesNotExist($this->target . '/public/img/big.bin');

        // Skipped files are reported so they can be handled by hand.
        $notes = implode("\n", array_map(static fn(mixed $n): string => is_string($n) ? $n : '', is_array($report['notes'] ?? null) ? $report['notes'] : []));
        self::assertStringContainsString('public/tools/adminer.php', $notes);
        self::assertStringContainsString('public/sub/.htaccess', $notes);
        self::assertStringContainsString('public/img/big.bin', $notes);
        self::assertStringContainsString('too large', $notes);

        // The target's own file is kept, and the source is unchanged.
        self::assertSame('the target\'s own file', file_get_contents($this->target . '/public/css/site.css'));
        foreach ($hashes as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $this->source . '/' . $file), $file);
        }
    }

    public function testLinksInsideTheSourceAreNotFollowed(): void
    {
        $outside = $this->base . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/secret.css', 'outside the project');

        if (!@symlink($outside, $this->source . '/public/linked')) {
            self::markTestSkipped('Creating a symlink is not permitted here (Windows without developer mode).');
        }

        $this->convert();

        self::assertFileDoesNotExist($this->target . '/public/linked/secret.css');
    }
}
