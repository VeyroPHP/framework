<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Security;

use InvalidArgumentException;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Security\MimeType;
use VeyroPHP\Security\UploadStore;
use VeyroPHP\Support\Ulid;
use VeyroPHP\Validation\InvalidRuleException;
use VeyroPHP\Validation\ValidationException;
use VeyroPHP\Validation\Validator;

#[CoversClass(MimeType::class)]
#[CoversClass(UploadStore::class)]
#[CoversClass(Validator::class)]
final class UploadsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-uploads-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    private static function upload(string $content, string $clientName = 'file.bin', string $clientType = 'application/octet-stream', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(Stream::create($content), strlen($content), $error, $clientName, $clientType);
    }

    private static function png(string $clientName = 'photo.png'): UploadedFile
    {
        return self::upload((string) base64_decode(self::PNG, true), $clientName, 'image/png');
    }

    private static function phpDisguisedAsImage(): UploadedFile
    {
        return self::upload('<?php system($_GET["c"]); ?>', 'shell.jpg', 'image/jpeg');
    }

    // MimeType

    public function testDetectsTypeFromContentNotFromClientClaims(): void
    {
        self::assertSame('image/png', MimeType::detect(self::png('innocent.txt')));
        self::assertSame('application/pdf', MimeType::detect(self::upload("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n", 'a.png', 'image/png')));
        self::assertNotSame('image/jpeg', MimeType::detect(self::phpDisguisedAsImage()));
        self::assertSame('application/x-empty', MimeType::detect(self::upload('')));
        self::assertNull(MimeType::detect(self::upload('', 'x', 'x', UPLOAD_ERR_NO_FILE)));
    }

    public function testExtensionForDetectedType(): void
    {
        self::assertSame('png', MimeType::extensionFor('image/png'));
        self::assertSame('jpg', MimeType::extensionFor('image/jpeg'));
        self::assertSame('pdf', MimeType::extensionFor('application/pdf'));
        self::assertSame('bin', MimeType::extensionFor('text/x-php'));
    }

    // Validator rules

    public function testValidImagePassesFileRules(): void
    {
        $data = (new Validator())->validate(['avatar' => self::png()], ['avatar' => 'required|file|image|mimes:png,jpg|max_size:10']);

        self::assertInstanceOf(UploadedFile::class, $data['avatar']);
    }

    public function testPhpRenamedToJpgIsRejected(): void
    {
        $validator = new Validator();
        $file = self::phpDisguisedAsImage();

        self::assertSame(['The avatar must be an image (JPEG, PNG, GIF or WebP).'], $validator->errors(['avatar' => $file], ['avatar' => 'required|file|image'])['avatar'] ?? null);
        self::assertSame(['The avatar must be a file of type: jpg, png.'], $validator->errors(['avatar' => $file], ['avatar' => 'required|file|mimes:jpg,png'])['avatar'] ?? null);
    }

    public function testSizeLimitAndFailedUploads(): void
    {
        $validator = new Validator();

        self::assertSame(['The doc must not be larger than 1 kilobytes.'], $validator->errors(['doc' => self::upload(str_repeat('a', 1025))], ['doc' => 'file|max_size:1'])['doc'] ?? null);
        self::assertSame([], $validator->errors(['doc' => self::upload(str_repeat('a', 1024))], ['doc' => 'file|max_size:1']));

        $partial = self::upload('abc', 'a.txt', 'text/plain', UPLOAD_ERR_PARTIAL);
        self::assertSame(['The doc must be a successfully uploaded file.'], $validator->errors(['doc' => $partial], ['doc' => 'file'])['doc'] ?? null);
        self::assertSame(['The doc must be a successfully uploaded file.'], $validator->errors(['doc' => 'just a string'], ['doc' => 'file'])['doc'] ?? null);
    }

    public function testMissingFileCountsAsEmpty(): void
    {
        $none = self::upload('', '', '', UPLOAD_ERR_NO_FILE);
        $validator = new Validator();

        // A file input left blank arrives as UPLOAD_ERR_NO_FILE: optional uploads need "nullable".
        self::assertSame(['avatar' => null], $validator->validate(['avatar' => $none], ['avatar' => 'nullable|file|image']));
        self::assertSame(['The avatar field must not be empty.'], $validator->errors(['avatar' => $none], ['avatar' => 'file|image'])['avatar'] ?? null);

        $this->expectException(ValidationException::class);
        $validator->validate(['avatar' => $none], ['avatar' => 'required|file']);
    }

    public function testUnknownMimesExtensionIsARuleError(): void
    {
        $this->expectException(InvalidRuleException::class);
        (new Validator())->errors(['f' => self::png()], ['f' => 'mimes:php']);
    }

    // UploadStore

    public function testStoresUnderAGeneratedNameWithDetectedExtension(): void
    {
        $store = new UploadStore($this->dir);
        $path = $store->store(self::png('../../evil.php'), 'avatars/2026');

        self::assertMatchesRegularExpression('#^avatars/2026/[0-9A-HJKMNP-TV-Z]{26}\.png$#', $path);
        self::assertTrue(Ulid::isValid(substr(basename($path), 0, 26)));
        self::assertFileExists($this->dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        self::assertStringNotContainsString('evil', $path);
    }

    public function testUnknownContentIsStoredAsBin(): void
    {
        $path = (new UploadStore($this->dir))->store(self::phpDisguisedAsImage());

        self::assertStringEndsWith('.bin', $path);
        self::assertStringNotContainsString('/', $path);
    }

    public function testRejectsTraversalAndOddSubdirectories(): void
    {
        $store = new UploadStore($this->dir);

        foreach (['../outside', 'a/../b', '/abs', 'a//b', 'A', 'a\\b', 'a/', 'a b', '.'] as $subdirectory) {
            try {
                $store->store(self::png(), $subdirectory);
                self::fail('Expected rejection of ' . $subdirectory);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testRejectsFailedUploads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error code 3');
        (new UploadStore($this->dir))->store(self::upload('abc', 'a.txt', 'text/plain', UPLOAD_ERR_PARTIAL));
    }
}
