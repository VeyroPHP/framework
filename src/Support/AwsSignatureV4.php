<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * AWS Signature Version 4: S3 and S3-compatible storage (Cloudflare R2, DigitalOcean
 * Spaces, MinIO, Backblaze B2) and other AWS APIs such as SES.
 *
 * @internal used by S3Signer and the SES mail transport
 */
final class AwsSignatureV4
{
    public const UNSIGNED = 'UNSIGNED-PAYLOAD';

    private const NOT_SIGNED = ['authorization', 'user-agent', 'content-length', 'expect'];

    public function __construct(
        private readonly string $accessKey,
        #[SensitiveParameter]
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {}

    /**
     * Adds x-amz-date, x-amz-content-sha256 and Authorization.
     *
     * @param string $payloadHash hex SHA-256 of the body, or UNSIGNED
     */
    public function sign(RequestInterface $request, string $payloadHash, DateTimeImmutable $now): RequestInterface
    {
        $time = $now->setTimezone(new DateTimeZone('UTC'));
        $request = $request
            ->withHeader('x-amz-date', $time->format('Ymd\THis\Z'))
            ->withHeader('x-amz-content-sha256', $payloadHash);

        if (!$request->hasHeader('Host')) {
            $request = $request->withHeader('Host', self::host($request));
        }

        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);

            if (!in_array($lower, self::NOT_SIGNED, true)) {
                $headers[$lower] = trim((string) preg_replace('/\s+/', ' ', implode(',', $values)));
            }
        }

        ksort($headers, SORT_STRING);
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            self::canonicalPath($request->getUri()->getPath()),
            self::canonicalQuery($request->getUri()->getQuery()),
            implode('', array_map(static fn(string $k, string $v): string => $k . ':' . $v . "\n", array_keys($headers), $headers)),
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $time->format('Ymd') . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $signature = $this->signature($canonical, $time, $scope);

        return $request->withHeader('Authorization', sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        ));
    }

    /**
     * A presigned URL that works without credentials until it expires.
     *
     * @param string $url "https://bucket.s3.region.amazonaws.com/key" (path already encoded)
     */
    public function presign(string $method, string $url, int $expiresSeconds, DateTimeImmutable $now): string
    {
        if ($expiresSeconds < 1 || $expiresSeconds > 604800) {
            throw new \InvalidArgumentException('Presigned URLs expire after 1 second to 7 days.');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            throw new \InvalidArgumentException('Invalid URL to presign.');
        }

        $time = $now->setTimezone(new DateTimeZone('UTC'));
        $scope = $time->format('Ymd') . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';

        parse_str($parts['query'] ?? '', $existing);
        $query = array_merge(array_map('strval', array_filter($existing, 'is_scalar')), [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $time->format('Ymd\THis\Z'),
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ]);
        $queryString = self::canonicalQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $canonical = implode("\n", [strtoupper($method), self::canonicalPath($path), $queryString, 'host:' . $host . "\n", 'host', self::UNSIGNED]);

        return $parts['scheme'] . '://' . $host . $path . '?' . $queryString . '&X-Amz-Signature=' . $this->signature($canonical, $time, $scope);
    }

    private function signature(string $canonicalRequest, DateTimeImmutable $time, string $scope): string
    {
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $time->format('Ymd\THis\Z'), $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $time->format('Ymd'), 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $stringToSign, $key);
    }

    /**
     * S3 paths are signed as sent (already encoded per segment), never encoded twice.
     */
    private static function canonicalPath(string $path): string
    {
        return $path === '' ? '/' : $path;
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $pairs[] = [rawurlencode(rawurldecode($key)), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn(array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    private static function host(RequestInterface $request): string
    {
        $uri = $request->getUri();

        return $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
    }
}
