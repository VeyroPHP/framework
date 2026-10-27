<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Determines the client IP address.
 *
 * X-Forwarded-For is only honored when the direct peer (REMOTE_ADDR) is a
 * trusted proxy; otherwise any client could claim any address and bypass
 * IP-based rate limits. The forwarded chain is read from right to left and the
 * first address that is not a trusted proxy is the client.
 *
 * Trusted proxies are IPs or CIDR ranges ("10.0.0.0/8", "::1", "2001:db8::/32").
 */
final class ClientIp
{
    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(private readonly array $trustedProxies = []) {}

    public function of(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $remote = is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';

        if (!$this->isTrusted($remote)) {
            return $remote;
        }

        $chain = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = $chain[$i];

            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $remote; // malformed header: do not trust any of it
            }

            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * The address used as a rate-limit key: IPv4 as it is, IPv6 reduced to its first
     * $ipv6Prefix bits. A client usually controls a whole /64, so per-address keys
     * would let it rotate addresses for fresh limits.
     */
    public static function forRateLimit(string $ip, int $ipv6Prefix = 64): string
    {
        if ($ipv6Prefix < 1 || $ipv6Prefix > 128) {
            throw new \InvalidArgumentException('The IPv6 prefix for rate limits must be between 1 and 128.');
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);

        if ($packed === false || $ipv6Prefix === 128) {
            return $ip;
        }

        $masked = '';

        for ($byte = 0; $byte < 16; $byte++) {
            $bits = max(0, min(8, $ipv6Prefix - $byte * 8));
            $masked .= chr(ord($packed[$byte]) & ((0xFF << (8 - $bits)) & 0xFF));
        }

        return inet_ntop($masked) . '/' . $ipv6Prefix;
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $range is an IP address or "address/prefix" with a prefix that fits the
     * address family: the one rule for trusted proxies and maintenance allow lists (HTTP-7).
     */
    public static function isValidRange(string $range): bool
    {
        return self::parseRange($range) !== null;
    }

    /**
     * Whether $ip lies in $range: a plain address (exact match) or "address/prefix".
     * Both sides are written out as strings of '0' and '1' and the first
     * prefix characters are compared. Anything malformed, or a v4/v6 mix, is false.
     */
    private static function inRange(string $ip, string $range): bool
    {
        $ipBits = self::bitString($ip);
        $parsed = self::parseRange($range);

        if ($ipBits === null || $parsed === null || strlen($ipBits) !== strlen($parsed[0])) {
            return false;
        }

        [$networkBits, $length] = $parsed;

        return strncmp($ipBits, $networkBits, $length) === 0;
    }

    /**
     * The network as bits and the prefix length; a plain address has the full length.
     *
     * @return array{string, int}|null null when $range is not a valid address or range
     */
    private static function parseRange(string $range): ?array
    {
        $slash = strpos($range, '/');
        $bits = self::bitString($slash === false ? $range : substr($range, 0, $slash));

        if ($bits === null) {
            return null;
        }

        if ($slash === false) {
            return [$bits, strlen($bits)];
        }

        $prefix = substr($range, $slash + 1);

        return $prefix !== '' && ctype_digit($prefix) && (int) $prefix <= strlen($bits) ? [$bits, (int) $prefix] : null;
    }

    /**
     * "192.0.2.1" becomes 32 characters of '0'/'1', an IPv6 address 128; null if not an address.
     */
    private static function bitString(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return null;
        }

        $bits = '';

        foreach (str_split($packed) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
