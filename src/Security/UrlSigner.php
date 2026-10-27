<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Closure;
use SensitiveParameter;
use VeyroPHP\Support\Base64Url;

/**
 * Signed links: anyone can open them, nobody can change them.
 *
 *     $path = $signer->sign('/email/verify/42/abc', [], time() + 3600);
 *     // "/email/verify/42/abc?expires=...&signature=..."
 *
 *     $signer->verify($routePath, $request->getQueryParams());   // or the ValidateSignature middleware
 *
 * - HMAC-SHA256 over the application path and the sorted query (not scheme or
 *   host, so links work behind proxies and in subdirectories).
 * - The key is derived from APP_KEY (HKDF, purpose "url-signing"), so it differs
 *   from the encryption key. Links signed with a previous key stay valid.
 * - An optional expiry is part of the signed data.
 * - A signed link is a bearer token: whoever has it can use it until it
 *   expires. Keep expiries short and check the user where it matters.
 */
final class UrlSigner
{
    /** @var non-empty-list<string> */
    private readonly array $keys;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string $key raw APP_KEY bytes
     * @param list<string> $previousKeys raw previous keys
     * @param (Closure(): int)|null $clock
     */
    public function __construct(#[SensitiveParameter] string $key, #[SensitiveParameter] array $previousKeys = [], ?Closure $clock = null)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('The URL signing key must be at least 32 bytes.');
        }

        $this->keys = array_map(
            static fn(string $raw): string => hash_hkdf('sha256', $raw, 32, 'veyrophp:url-signing'),
            [$key, ...$previousKeys],
        );
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param string $path application path ("/reset/abc"), without base path
     * @param array<string, scalar> $query
     * @param int|null $expiresAt Unix time, or null for no expiry
     *
     * @return string the path with "expires" (if any) and "signature" added to the query
     */
    public function sign(string $path, array $query = [], ?int $expiresAt = null): string
    {
        unset($query['signature'], $query['expires']);

        if ($expiresAt !== null) {
            $query['expires'] = $expiresAt;
        }

        $query['signature'] = $this->signature($this->keys[0], $path, $query);

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<array-key, mixed> $query the request's query parameters
     */
    public function verify(string $path, array $query): bool
    {
        $signature = $query['signature'] ?? null;

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        unset($query['signature']);

        foreach ($query as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }

        $valid = false;

        foreach ($this->keys as $key) {
            /** @var array<string, scalar> $query */
            $valid = hash_equals($this->signature($key, $path, $query), $signature) || $valid;
        }

        if (!$valid) {
            return false;
        }

        $expires = $query['expires'] ?? null;

        return $expires === null || (is_numeric($expires) && (int) $expires >= ($this->clock)());
    }

    /**
     * @param array<array-key, scalar> $query without "signature"
     */
    private function signature(string $key, string $path, array $query): string
    {
        $query = array_map(static fn(mixed $v): string => (string) $v, $query);
        ksort($query, SORT_STRING);

        return Base64Url::encode(hash_hmac('sha256', $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), $key, true));
    }
}
