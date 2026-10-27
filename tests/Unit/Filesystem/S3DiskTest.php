<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Filesystem;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Filesystem\FileNotFoundException;
use VeyroPHP\Filesystem\FilesystemException;
use VeyroPHP\Filesystem\S3Disk;
use VeyroPHP\Filesystem\S3Signer;

/**
 * A small in-memory S3: objects, HEAD/GET/PUT/DELETE, server-side copy and
 * ListObjectsV2 with prefix, delimiter and pages of two keys. Every request
 * must be signed and carry the SHA-256 of its body.
 */
final class FakeS3 implements ClientInterface
{
    /** @var array<string, array{string, string, int}> key => [body, content type, time] */
    public array $objects = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public ?ResponseInterface $forced = null;

    public bool $offline = false;

    public function __construct(private readonly bool $pathStyle) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->offline) {
            throw new class ('connection refused') extends \RuntimeException implements NetworkExceptionInterface {
                public function getRequest(): RequestInterface
                {
                    throw new \LogicException();
                }
            };
        }

        $auth = $request->getHeaderLine('Authorization');
        if (preg_match('#^AWS4-HMAC-SHA256 Credential=KEY/\d{8}/ap-south-1/s3/aws4_request,SignedHeaders=([a-z0-9;-]+),Signature=[0-9a-f]{64}$#', $auth, $m) !== 1
            || array_diff(['host', 'x-amz-content-sha256', 'x-amz-date'], explode(';', $m[1])) !== []) {
            return new Response(403, [], '<Error><Code>SignatureDoesNotMatch</Code></Error>');
        }

        $body = (string) $request->getBody();
        if ($request->getHeaderLine('x-amz-content-sha256') !== hash('sha256', $body)) {
            return new Response(400, [], '<Error><Code>XAmzContentSHA256Mismatch</Code></Error>');
        }

        if ($this->forced !== null) {
            return $this->forced;
        }

        $path = $request->getUri()->getPath();
        if ($this->pathStyle) {
            if (!str_starts_with($path, '/files/')) {
                return new Response(404, [], '<Error><Code>NoSuchBucket</Code></Error>');
            }
            $path = substr($path, strlen('/files'));
        } elseif ($request->getUri()->getHost() !== 'files.s3.ap-south-1.amazonaws.com') {
            return new Response(404, [], '<Error><Code>NoSuchBucket</Code></Error>');
        }

        $key = rawurldecode(ltrim($path, '/'));
        parse_str($request->getUri()->getQuery(), $query);

        return match ($request->getMethod()) {
            'PUT' => $this->put($key, $body, $request),
            'GET' => $key === '' ? $this->list($query) : (isset($this->objects[$key]) ? new Response(200, ['Content-Type' => $this->objects[$key][1]], $this->objects[$key][0]) : new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>')),
            'HEAD' => isset($this->objects[$key])
                ? new Response(200, ['Content-Length' => (string) strlen($this->objects[$key][0]), 'Content-Type' => $this->objects[$key][1], 'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $this->objects[$key][2])])
                : new Response(404),
            'DELETE' => $this->delete($key),
            default => new Response(405),
        };
    }

    private function put(string $key, string $body, RequestInterface $request): ResponseInterface
    {
        $source = $request->getHeaderLine('x-amz-copy-source');

        if ($source !== '') {
            $sourceKey = rawurldecode(substr($source, strlen('/files/')));

            if (!isset($this->objects[$sourceKey])) {
                return new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>');
            }

            $this->objects[$key] = $this->objects[$sourceKey];

            return new Response(200, [], '<CopyObjectResult/>');
        }

        $this->objects[$key] = [$body, $request->getHeaderLine('Content-Type'), 1_793_000_000];

        return new Response(200);
    }

    private function delete(string $key): ResponseInterface
    {
        unset($this->objects[$key]);

        return new Response(204);
    }

    /**
     * @param array<mixed> $query
     */
    private function list(array $query): ResponseInterface
    {
        $prefix = is_string($query['prefix'] ?? null) ? $query['prefix'] : '';
        $delimiter = is_string($query['delimiter'] ?? null) ? $query['delimiter'] : null;
        $after = is_string($query['continuation-token'] ?? null) ? base64_decode($query['continuation-token']) : '';
        $entries = [];

        $keys = array_keys($this->objects);
        sort($keys);

        foreach ($keys as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $rest = substr($key, strlen($prefix));

            if ($delimiter !== null && str_contains($rest, $delimiter)) {
                $entries['p:' . $prefix . substr($rest, 0, (int) strpos($rest, $delimiter) + 1)] = true;
            } else {
                $entries['k:' . $key] = true;
            }
        }

        $entries = array_values(array_filter(array_keys($entries), static fn(string $e): bool => $e > $after));
        $page = array_slice($entries, 0, 2);
        $truncated = count($entries) > 2;
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';

        foreach ($page as $entry) {
            $xml .= str_starts_with($entry, 'k:')
                ? '<Contents><Key>' . htmlspecialchars(substr($entry, 2)) . '</Key></Contents>'
                : '<CommonPrefixes><Prefix>' . htmlspecialchars(substr($entry, 2)) . '</Prefix></CommonPrefixes>';
        }

        $xml .= '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';
        if ($truncated) {
            $xml .= '<NextContinuationToken>' . base64_encode((string) end($page)) . '</NextContinuationToken>';
        }

        return new Response(200, ['Content-Type' => 'application/xml'], $xml . '</ListBucketResult>');
    }
}

#[CoversClass(S3Disk::class)]
final class S3DiskTest extends TestCase
{
    private static function disk(FakeS3 $s3, bool $pathStyle = false, string $prefix = '', ?string $url = null): S3Disk
    {
        $factory = new Psr17Factory();

        return new S3Disk(
            $s3,
            $factory,
            $factory,
            new S3Signer('KEY', 'very-secret-value', 'ap-south-1'),
            'files',
            $pathStyle ? 'http://127.0.0.1:9000' : 'https://s3.ap-south-1.amazonaws.com',
            $pathStyle,
            $prefix,
            $url,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-26 12:00:00 UTC'),
        );
    }

    public function testFileOperations(): void
    {
        $s3 = new FakeS3(false);
        $disk = self::disk($s3);

        $disk->put('invoices/2026/Asha #1.pdf', '%PDF-1.4 data');

        self::assertSame('https://files.s3.ap-south-1.amazonaws.com/invoices/2026/Asha%20%231.pdf', (string) $s3->requests[0]->getUri());
        self::assertSame('application/pdf', $s3->objects['invoices/2026/Asha #1.pdf'][1]);
        self::assertTrue($disk->exists('invoices/2026/Asha #1.pdf'));
        self::assertFalse($disk->exists('invoices/none.pdf'));
        self::assertSame('%PDF-1.4 data', $disk->get('invoices/2026/Asha #1.pdf'));
        self::assertSame(13, $disk->size('invoices/2026/Asha #1.pdf'));
        self::assertSame(1_793_000_000, $disk->lastModified('invoices/2026/Asha #1.pdf'));
        self::assertSame('application/pdf', $disk->mimeType('invoices/2026/Asha #1.pdf'));

        $stream = $disk->readStream('invoices/2026/Asha #1.pdf');
        self::assertSame('%PDF-1.4 data', stream_get_contents($stream));

        $disk->copy('invoices/2026/Asha #1.pdf', 'archive/a.pdf');
        $disk->move('archive/a.pdf', 'archive/b.pdf');
        self::assertSame(['invoices/2026/Asha #1.pdf', 'archive/b.pdf'], array_keys($s3->objects));
        self::assertTrue($disk->delete('archive/b.pdf'));
        self::assertFalse($disk->delete('archive/b.pdf'));

        $this->expectException(FileNotFoundException::class);
        $disk->get('archive/b.pdf');
    }

    public function testListingWithPagesPrefixAndPathStyle(): void
    {
        $s3 = new FakeS3(true);
        $disk = self::disk($s3, pathStyle: true, prefix: 'tenant-7');

        foreach (['a.txt', 'b.txt', 'c.txt', 'docs/d.txt', 'docs/deep/e.txt', 'img/f.png'] as $file) {
            $disk->put($file, $file);
        }

        self::assertStringStartsWith('http://127.0.0.1:9000/files/tenant-7/', (string) $s3->requests[0]->getUri());
        self::assertSame(['a.txt', 'b.txt', 'c.txt'], $disk->files(), 'three pages of two');
        self::assertSame(['docs', 'img'], $disk->directories());
        self::assertSame(['docs/d.txt', 'docs/deep/e.txt'], $disk->files('docs', true));

        $disk->deleteDirectory('docs');
        self::assertSame(['a.txt', 'b.txt', 'c.txt', 'img/f.png'], $disk->files('', true));
        self::assertSame(['tenant-7/a.txt', 'tenant-7/b.txt', 'tenant-7/c.txt', 'tenant-7/img/f.png'], array_keys($s3->objects), 'everything under the prefix');
    }

    public function testUrls(): void
    {
        $disk = self::disk(new FakeS3(false), url: 'https://cdn.example.com');

        self::assertSame('https://cdn.example.com/avatars/7%20b.png', $disk->url('avatars/7 b.png'));
        $temporary = $disk->temporaryUrl('private/report.csv', 600);
        self::assertStringStartsWith('https://files.s3.ap-south-1.amazonaws.com/private/report.csv?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=KEY%2F20261026%2Fap-south-1%2Fs3%2Faws4_request&X-Amz-Date=20261026T120000Z&X-Amz-Expires=600&', $temporary);
        self::assertStringNotContainsString('very-secret-value', $temporary);

        $this->expectException(FilesystemException::class);
        self::disk(new FakeS3(false))->url('a.png');
    }

    public function testErrorsCarryTheS3CodeButNoSecrets(): void
    {
        $s3 = new FakeS3(false);
        $s3->forced = new Response(403, [], '<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>');

        try {
            self::disk($s3)->put('a.txt', 'x');
            self::fail('expected FilesystemException');
        } catch (FilesystemException $e) {
            self::assertSame('Could not write "a.txt": S3 answered 403 AccessDenied.', $e->getMessage());
            self::assertStringNotContainsString('very-secret-value', $e->getMessage());
        }

        $s3->forced = null;
        $s3->offline = true;
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('could not be reached');
        self::disk($s3)->get('a.txt');
    }

    public function testInvalidSettingsAndPaths(): void
    {
        $factory = new Psr17Factory();
        $signer = new S3Signer('KEY', 'secret', 'ap-south-1');

        foreach ([
            static fn() => new S3Disk(new FakeS3(false), $factory, $factory, $signer, 'Bad_Bucket', 'https://s3.amazonaws.com'),
            static fn() => new S3Disk(new FakeS3(false), $factory, $factory, $signer, 'files', 'ftp://s3.amazonaws.com'),
            static fn() => self::disk(new FakeS3(false))->put('../escape.txt', 'x'),
            static fn() => self::disk(new FakeS3(false))->deleteDirectory(''),
        ] as $i => $call) {
            try {
                $call();
                self::fail('expected FilesystemException ' . $i);
            } catch (FilesystemException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
