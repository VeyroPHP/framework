<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use InvalidArgumentException;

/**
 * Builds a Set-Cookie header value with secure defaults: HttpOnly, SameSite=Lax, Path=/.
 */
final class Cookie
{
    public const SAME_SITE = ['lax', 'strict', 'none'];

    /**
     * @param int|null $maxAge seconds; null for a browser-session cookie, 0 or less to delete
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly ?int $maxAge = null,
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'lax',
    ) {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid cookie name "%s".', $name));
        }

        if (!in_array(strtolower($sameSite), self::SAME_SITE, true)) {
            throw new InvalidArgumentException('SameSite must be lax, strict or none.');
        }

        if (strtolower($sameSite) === 'none' && !$secure) {
            throw new InvalidArgumentException('Cookies with SameSite=None must be Secure.');
        }

        foreach (['path' => $path, 'domain' => $domain ?? ''] as $attribute => $value) {
            if (preg_match('/[;\r\n\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('Invalid cookie %s.', $attribute));
            }
        }
    }

    public static function forget(string $name, string $path = '/', ?string $domain = null, bool $secure = false): self
    {
        return new self($name, '', 0, $path, $domain, $secure);
    }

    public function toHeaderValue(): string
    {
        $parts = [$this->name . '=' . rawurlencode($this->value)];

        if ($this->maxAge !== null) {
            $seconds = max(0, $this->maxAge);
            $parts[] = 'Max-Age=' . $seconds;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $seconds === 0 ? 0 : time() + $seconds);
        }

        $parts[] = 'Path=' . $this->path;

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . ucfirst(strtolower($this->sameSite));

        return implode('; ', $parts);
    }
}
