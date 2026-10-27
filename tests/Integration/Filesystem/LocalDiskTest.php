<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Filesystem;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Filesystem\Console\StorageLinkCommand;
use VeyroPHP\Filesystem\Disk;
use VeyroPHP\Filesystem\FileNotFoundException;
use VeyroPHP\Filesystem\FileResponse;
use VeyroPHP\Filesystem\FilesystemException;
use VeyroPHP\Filesystem\FilesystemManager;
use VeyroPHP\Filesystem\FilesystemServiceProvider;
use VeyroPHP\Filesystem\LocalDisk;
use VeyroPHP\Filesystem\PathNormalizer;

#[CoversClass(LocalDisk::class)]
#[CoversClass(PathNormalizer::class)]
#[CoversClass(FileResponse::class)]
#[CoversClass(FilesystemManager::class)]
#[CoversClass(FilesystemServiceProvider::class)]
#[CoversClass(StorageLinkCommand::class)]
#[CoversClass(FilesystemException::class)]
#[CoversClass(FileNotFoundException::class)]
final class LocalDiskTest extends TestCase
{
    private string $root;

    private LocalDisk $disk;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-disk-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->disk = new LocalDisk($this->root . '/files', 'https://cdn.example.com/files');
        // A secret next to the disk root that no path may reach.
        file_put_contents($this->root . '/secret.txt', 'top secret');
    }

    protected function tearDown(): void
    {
        $link = $this->root . '/app/public/storage';
        if (is_link($link) || (PHP_OS_FAMILY === 'Windows' && is_dir($link) && readlink($link) !== false)) {
            // Remove only the link, never what it points to.
            PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testWriteReadAndMetadata(): void
    {
        $this->disk->put('reports/2026/today.csv', "a,b\n1,2\n");

        self::assertTrue($this->disk->exists('reports/2026/today.csv'));
        self::assertTrue($this->disk->exists('/reports/2026/today.csv'), 'a leading slash is allowed');
        self::assertFalse($this->disk->exists('reports/2026/other.csv'));
        self::assertSame("a,b\n1,2\n", $this->disk->get('reports/2026/today.csv'));
        self::assertSame(8, $this->disk->size('reports/2026/today.csv'));
        self::assertEqualsWithDelta(time(), $this->disk->lastModified('reports/2026/today.csv'), 5);
        self::assertSame('text/plain', $this->disk->mimeType('reports/2026/today.csv'), 'detected from the content');

        $this->disk->put('reports/2026/today.csv', 'replaced');
        self::assertSame('replaced', $this->disk->get('reports/2026/today.csv'));
        self::assertSame([], glob($this->root . '/files/reports/2026/.*.tmp') ?: [], 'no temporary files left behind');
    }

    public function testStreams(): void
    {
        $source = fopen('php://memory', 'w+');
        self::assertIsResource($source);
        fwrite($source, str_repeat('x', 100_000));
        rewind($source);

        $this->disk->putStream('big.bin', $source);
        $stream = $this->disk->readStream('big.bin');

        self::assertSame(100_000, strlen((string) stream_get_contents($stream)));
        fclose($stream);
    }

    public function testCopyMoveDelete(): void
    {
        $this->disk->put('a.txt', 'A');

        $this->disk->copy('a.txt', 'copies/b.txt');
        $this->disk->move('copies/b.txt', 'moved/c.txt');

        self::assertSame('A', $this->disk->get('a.txt'));
        self::assertFalse($this->disk->exists('copies/b.txt'));
        self::assertSame('A', $this->disk->get('moved/c.txt'));
        self::assertTrue($this->disk->delete('a.txt'));
        self::assertFalse($this->disk->delete('a.txt'));

        $this->expectException(FileNotFoundException::class);
        $this->disk->copy('missing.txt', 'x.txt');
    }

    public function testListingAndDeletingDirectories(): void
    {
        foreach (['a.txt', 'docs/b.txt', 'docs/deep/c.txt', 'img/d.png'] as $file) {
            $this->disk->put($file, $file);
        }

        self::assertSame(['a.txt'], $this->disk->files());
        self::assertSame(['docs/b.txt'], $this->disk->files('docs'));
        self::assertSame(['docs/b.txt', 'docs/deep/c.txt'], $this->disk->files('docs', true));
        self::assertSame(['docs', 'img'], $this->disk->directories());
        self::assertSame([], $this->disk->files('missing'));

        $this->disk->deleteDirectory('docs');
        self::assertSame(['a.txt', 'img/d.png'], $this->disk->files('', true));

        $this->expectException(FilesystemException::class);
        $this->disk->deleteDirectory('/');
    }

    public function testUrls(): void
    {
        self::assertSame('https://cdn.example.com/files/avatars/Asha%20V%23rma.png', $this->disk->url('avatars/Asha V#rma.png'));

        $this->expectException(FilesystemException::class);
        (new LocalDisk($this->root . '/private'))->url('x.txt');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'parent' => ['../secret.txt'];
        yield 'nested parent' => ['reports/../../secret.txt'];
        yield 'backslash parent' => ['..\\secret.txt'];
        yield 'dot segment' => ['./a.txt'];
        yield 'double slash' => ['a//b.txt'];
        yield 'drive letter' => ['C:/Windows/win.ini'];
        yield 'null byte' => ["a.txt\0.png"];
        yield 'line break' => ["a\n.txt"];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        foreach ([
            fn() => $this->disk->get($path),
            fn() => $this->disk->put($path, 'x'),
            fn() => $this->disk->exists($path),
            fn() => $this->disk->delete($path),
        ] as $call) {
            try {
                $call();
                self::fail('path accepted: ' . json_encode($path));
            } catch (FilesystemException $e) {
                self::assertNotInstanceOf(FileNotFoundException::class, $e, 'rejected as invalid, not looked up');
            }
        }

        self::assertSame('top secret', file_get_contents($this->root . '/secret.txt'));
    }

    public function testMissingFiles(): void
    {
        foreach ([fn() => $this->disk->get('nope.txt'), fn() => $this->disk->size('nope.txt'), fn() => $this->disk->readStream('nope.txt')] as $call) {
            try {
                $call();
                self::fail('expected FileNotFoundException');
            } catch (FileNotFoundException $e) {
                self::assertSame('File "nope.txt" does not exist.', $e->getMessage());
            }
        }
    }

    // Responses

    public function testDownloadAndInlineResponses(): void
    {
        $files = new FileResponse(new Psr17Factory(), new Psr17Factory());
        $this->disk->put('docs/invoice.pdf', "%PDF-1.4\n" . str_repeat('x', 50));
        $this->disk->put('docs/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $download = $files->download($this->disk, 'docs/invoice.pdf', "Invoice \"42\"\r\nSet-Cookie: x=1 ✓.pdf");
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertSame('59', $download->getHeaderLine('Content-Length'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        $disposition = $download->getHeaderLine('Content-Disposition');
        self::assertStringStartsWith('attachment; filename="Invoice _42___Set-Cookie: x=1 _.pdf"; filename*=UTF-8\'\'', $disposition);
        self::assertStringNotContainsString("\n", $disposition);
        self::assertSame("%PDF-1.4\n" . str_repeat('x', 50), (string) $download->getBody());

        self::assertStringStartsWith('inline;', $files->inline($this->disk, 'docs/invoice.pdf')->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('attachment;', $files->inline($this->disk, 'docs/evil.svg')->getHeaderLine('Content-Disposition'), 'SVG could run scripts');
    }

    // Provider and command

    /**
     * @param array<string, mixed> $filesystems
     */
    private function app(array $filesystems): Application
    {
        $app = new Application($this->root);
        $app->container()->instance(Config::class, new Config(['filesystems' => $filesystems]));
        $app->register(new FilesystemServiceProvider());
        $app->register(new ConsoleServiceProvider(FilesystemServiceProvider::COMMANDS));
        $app->boot();

        return $app;
    }

    public function testProviderBuildsConfiguredDisks(): void
    {
        $app = $this->app([
            'default' => 'local',
            'disks' => [
                'local' => ['driver' => 'local', 'root' => 'app/private'],
                'public' => ['driver' => 'local', 'root' => 'app/public', 'url' => 'https://example.com/storage'],
                'odd' => ['driver' => 'ftp'],
                's3' => ['driver' => 's3', 'bucket' => 'files'],
            ],
        ]);
        $manager = $app->container()->make(FilesystemManager::class);

        $app->container()->make(Disk::class)->put('x.txt', 'private');
        self::assertFileExists($this->root . '/app/private/x.txt');
        self::assertSame($manager->disk('public'), $manager->disk('public'));
        self::assertSame('https://example.com/storage/a.png', $manager->disk('public')->url('a.png'));

        foreach ([['missing', 'No disk "missing"'], ['odd', 'unknown driver'], ['s3', 'needs key, secret and bucket']] as [$name, $error]) {
            try {
                $manager->disk($name);
                self::fail('expected FilesystemException for ' . $name);
            } catch (FilesystemException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }
    }

    public function testStorageLinkMakesPublicFilesReachable(): void
    {
        mkdir($this->root . '/public');
        $app = $this->app([]);
        // The command links public/storage to storage/app/public under the base path.
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $console = $app->container()->make(Console::class);

        $code = $console->run(['storage:link'], new Output($out, $err));
        rewind($err);
        self::assertSame(0, $code, (string) stream_get_contents($err));

        file_put_contents($this->root . '/storage/app/public/hello.txt', 'hi');
        self::assertSame('hi', file_get_contents($this->root . '/public/storage/hello.txt'), 'reachable through the link');
        self::assertSame(0, $console->run(['storage:link'], new Output($out, $err)), 'a second run is harmless');

        // Clean up the link before tearDown deletes the tree.
        PHP_OS_FAMILY === 'Windows' ? rmdir($this->root . '/public/storage') : unlink($this->root . '/public/storage');
        self::assertFileExists($this->root . '/storage/app/public/hello.txt', 'removing the link kept the files');
    }

    /**
     * MISC-15: the link points to the configured root of the public disk.
     */
    public function testStorageLinkUsesTheConfiguredPublicRoot(): void
    {
        mkdir($this->root . '/public');
        $app = $this->app(['disks' => ['public' => ['driver' => 'local', 'root' => 'shared/uploads']]]);
        [$code, $error] = self::runCommand($app, 'storage:link');

        self::assertSame(0, $code, $error);
        file_put_contents($this->root . '/shared/uploads/hello.txt', 'hi');
        self::assertSame('hi', file_get_contents($this->root . '/public/storage/hello.txt'));
        self::assertDirectoryDoesNotExist($this->root . '/storage/app/public', 'the default root is not created');

        PHP_OS_FAMILY === 'Windows' ? rmdir($this->root . '/public/storage') : unlink($this->root . '/public/storage');
    }

    public function testStorageLinkRefusesAPublicDiskThatIsNotLocal(): void
    {
        mkdir($this->root . '/public');
        $app = $this->app(['disks' => ['public' => ['driver' => 's3', 'bucket' => 'b']]]);
        [$code, $error] = self::runCommand($app, 'storage:link');

        self::assertSame(1, $code);
        self::assertStringContainsString('not on a local disk', $error);
        self::assertFileDoesNotExist($this->root . '/public/storage');
    }

    /**
     * @return array{int, string} exit code and error output
     */
    private static function runCommand(Application $app, string $command): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = $app->container()->make(Console::class)->run([$command], new Output($out, $err));
        rewind($err);

        return [$code, (string) stream_get_contents($err)];
    }
}
