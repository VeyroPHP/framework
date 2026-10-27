<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Remember;

use Closure;
use SensitiveParameter;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Http\Cookie;
use VeyroPHP\Support\Base64Url;

/**
 * "Remember me" tokens (scoped per request). Auth uses this service; applications
 * normally only pass $remember to Auth::attempt() and add the RememberMe middleware.
 *
 * - The cookie holds "selector:validator". The table keeps the selector and a SHA-256 of
 *   the validator, compared in constant time, so a leaked table logs nobody in.
 * - Each row stores the password fingerprint of the login. After a password change or
 *   reset the row no longer matches and is refused (as sessions are).
 * - A wrong validator for a known selector, an expired row or an unknown selector
 *   deletes the row (if any) and the cookie.
 * - Tokens are not rotated on use: two parallel requests with one cookie would
 *   otherwise race and log the user out.
 */
final class RememberTokens
{
    public const COOKIE = 'veyrophp_remember';

    private const FORMAT = '/^([A-Za-z0-9_-]{22}):([A-Za-z0-9_-]{43})$/D';

    private ?string $selector = null;

    private ?string $validator = null;

    private bool $https = false;

    private ?Cookie $pending = null;

    /**
     * @param ?bool $secure the cookie's Secure flag; null sets it on HTTPS requests (like the session)
     * @param (Closure(): int)|null $clock current Unix time
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = RememberSchema::TABLE,
        private readonly int $lifetimeDays = 30,
        private readonly string $cookieName = self::COOKIE,
        private readonly ?bool $secure = null,
        private readonly string $path = '/',
        private readonly ?string $domain = null,
        private readonly ?Closure $clock = null,
    ) {
        if ($lifetimeDays < 1) {
            throw new \InvalidArgumentException('The remember-me lifetime must be at least one day.');
        }
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * @internal called by the RememberMe middleware with the request's cookie
     */
    public function useRequestCookie(#[SensitiveParameter] ?string $cookie, bool $https): void
    {
        $this->https = $https;
        $this->selector = $this->validator = null;

        if ($cookie !== null && preg_match(self::FORMAT, $cookie, $parts) === 1) {
            [, $this->selector, $this->validator] = $parts;
        } elseif ($cookie !== null) {
            $this->pending = $this->expiredCookie();
        }
    }

    /**
     * Remembers this browser for the user: a new row and a cookie. Replaces this
     * browser's previous token.
     */
    public function issue(int|string $userId, string $passwordFingerprint): void
    {
        $this->deleteCurrent();
        $selector = Base64Url::secret(16);
        $validator = Base64Url::secret(32);
        $now = $this->now();

        $this->connection->table($this->table)->insert([
            'user_id' => (string) $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'password_fingerprint' => $passwordFingerprint,
            'expires_at' => gmdate('Y-m-d H:i:s', $now + $this->lifetimeDays * 86400),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'updated_at' => gmdate('Y-m-d H:i:s', $now),
        ]);

        $this->selector = $selector;
        $this->validator = $validator;
        $this->pending = new Cookie($this->cookieName, $selector . ':' . $validator, $this->lifetimeDays * 86400, $this->path, $this->domain, $this->secure ?? $this->https);
    }

    /**
     * The user id and password fingerprint of this browser's valid token, or null.
     *
     * @return array{user_id: int|string, password_fingerprint: string}|null
     */
    public function resolve(): ?array
    {
        if ($this->selector === null || $this->validator === null) {
            return null;
        }

        $row = $this->connection->table($this->table)->where('selector', $this->selector)->first();
        $hash = $row['validator_hash'] ?? null;
        $expires = $row['expires_at'] ?? null;

        if (
            !is_string($hash) || !hash_equals($hash, hash('sha256', $this->validator))
            || !is_string($expires) || strtotime($expires . ' UTC') <= $this->now()
        ) {
            $this->forget();

            return null;
        }

        $this->connection->table($this->table)->where('selector', $this->selector)->update(['last_used_at' => gmdate('Y-m-d H:i:s', $this->now())]);
        $userId = $row['user_id'] ?? '';
        $userId = is_int($userId) || is_string($userId) ? (string) $userId : '';
        $fingerprint = $row['password_fingerprint'] ?? '';

        return [
            'user_id' => ctype_digit($userId) && strlen($userId) < 19 ? (int) $userId : $userId,
            'password_fingerprint' => is_string($fingerprint) ? $fingerprint : '',
        ];
    }

    /**
     * Forgets this browser: deletes its row and the cookie (logout, invalid token).
     */
    public function forget(): void
    {
        $this->deleteCurrent();
        $this->selector = $this->validator = null;
        $this->pending = $this->expiredCookie();
    }

    /**
     * Deletes every token of the user except this browser's, and stores a new password
     * fingerprint on this browser's token (log out other devices).
     */
    public function forgetOthers(int|string $userId, string $passwordFingerprint): void
    {
        $others = $this->connection->table($this->table)->where('user_id', (string) $userId);

        if ($this->selector !== null) {
            $others->where('selector', '!=', $this->selector);
            $this->connection->table($this->table)
                ->where('selector', $this->selector)
                ->where('user_id', (string) $userId)
                ->update(['password_fingerprint' => $passwordFingerprint, 'updated_at' => gmdate('Y-m-d H:i:s', $this->now())]);
        }

        $others->delete();
    }

    /**
     * Deletes expired tokens (schedule it daily). Returns the number deleted.
     */
    public function prune(): int
    {
        return $this->connection->table($this->table)->where('expires_at', '<=', gmdate('Y-m-d H:i:s', $this->now()))->delete();
    }

    /**
     * @internal the Set-Cookie the RememberMe middleware adds to the response
     */
    public function pendingCookie(): ?Cookie
    {
        return $this->pending;
    }

    private function deleteCurrent(): void
    {
        if ($this->selector !== null) {
            $this->connection->table($this->table)->where('selector', $this->selector)->delete();
        }
    }

    private function expiredCookie(): Cookie
    {
        return Cookie::forget($this->cookieName, $this->path, $this->domain, $this->secure ?? $this->https);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
