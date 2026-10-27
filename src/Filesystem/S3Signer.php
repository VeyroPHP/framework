<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;
use VeyroPHP\Support\AwsSignatureV4;

/**
 * AWS Signature Version 4 for S3 (and S3-compatible services: Cloudflare R2,
 * DigitalOcean Spaces, MinIO, Backblaze B2), with the module's exception type.
 *
 * @internal used by S3Disk
 */
final class S3Signer
{
    public const UNSIGNED = AwsSignatureV4::UNSIGNED;

    private readonly AwsSignatureV4 $signer;

    public function __construct(
        string $accessKey,
        #[SensitiveParameter]
        string $secretKey,
        string $region,
        string $service = 's3',
    ) {
        $this->signer = new AwsSignatureV4($accessKey, $secretKey, $region, $service);
    }

    /**
     * Adds x-amz-date, x-amz-content-sha256 and Authorization.
     *
     * @param string $payloadHash hex SHA-256 of the body, or UNSIGNED
     */
    public function sign(RequestInterface $request, string $payloadHash, DateTimeImmutable $now): RequestInterface
    {
        return $this->signer->sign($request, $payloadHash, $now);
    }

    /**
     * A presigned URL that works without credentials until it expires.
     *
     * @throws FilesystemException for an expiry outside 1 second to 7 days or an invalid URL
     */
    public function presign(string $method, string $url, int $expiresSeconds, DateTimeImmutable $now): string
    {
        try {
            return $this->signer->presign($method, $url, $expiresSeconds, $now);
        } catch (\InvalidArgumentException $e) {
            throw new FilesystemException($e->getMessage(), 0, $e);
        }
    }
}
