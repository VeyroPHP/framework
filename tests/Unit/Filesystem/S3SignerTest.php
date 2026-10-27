<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Filesystem;

use DateTimeImmutable;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Filesystem\FilesystemException;
use VeyroPHP\Filesystem\S3Signer;

/**
 * Known-answer tests: the example requests, credentials and signatures from
 * Amazon's Signature Version 4 documentation for S3 ("Examples: Signature
 * Calculations" and "Authenticating Requests: Using Query Parameters").
 */
#[CoversClass(S3Signer::class)]
final class S3SignerTest extends TestCase
{
    private static function signer(): S3Signer
    {
        return new S3Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    }

    private static function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('2013-05-24 00:00:00 UTC');
    }

    private static function emptyHash(): string
    {
        return hash('sha256', '');
    }

    public function testGetObjectExample(): void
    {
        $request = new Request('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9']);

        $signed = self::signer()->sign($request, self::emptyHash(), self::date());

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $signed->getHeaderLine('Authorization'),
        );
    }

    public function testPutObjectExample(): void
    {
        $body = 'Welcome to Amazon S3.';
        $request = new Request('PUT', 'https://examplebucket.s3.amazonaws.com/test%24file.text', [
            'Date' => 'Fri, 24 May 2013 00:00:00 GMT',
            'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
        ], $body);

        $signed = self::signer()->sign($request, hash('sha256', $body), self::date());

        self::assertSame('44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', hash('sha256', $body));
        self::assertStringEndsWith(
            'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            $signed->getHeaderLine('Authorization'),
        );
    }

    public function testGetBucketLifecycleExample(): void
    {
        $request = new Request('GET', 'https://examplebucket.s3.amazonaws.com/?lifecycle');

        $signed = self::signer()->sign($request, self::emptyHash(), self::date());

        self::assertStringEndsWith('Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', $signed->getHeaderLine('Authorization'));
    }

    public function testListObjectsExample(): void
    {
        $request = new Request('GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J');

        $signed = self::signer()->sign($request, self::emptyHash(), self::date());

        self::assertStringEndsWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $signed->getHeaderLine('Authorization'));
    }

    public function testPresignedUrlExample(): void
    {
        $url = self::signer()->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, self::date());

        self::assertSame(
            'https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url,
        );
    }

    public function testPresignLimits(): void
    {
        $this->expectException(FilesystemException::class);
        self::signer()->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 604801, self::date());
    }
}
