<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Hashes and verifies passwords with PHP's password API. No custom cryptography.
 *
 * - Default algorithm: PASSWORD_DEFAULT (bcrypt in PHP 8.2). "argon2id" can be
 *   chosen where PHP supports it.
 * - bcrypt only uses the first 72 bytes of a password. Longer passwords are
 *   rejected instead of being silently truncated; validate with "max_bytes:72" (bytes, not characters) or use argon2id.
 * - Empty passwords and passwords containing null bytes are rejected.
 * - needsRehash() tells you when a stored hash should be upgraded (Auth does this on login).
 */
final class PasswordHasher
{
    private const BCRYPT_MAX_BYTES = 72;

    private readonly string $algorithm;

    private ?string $dummyHash = null;

    /**
     * @param string $algorithm "default", "bcrypt" or "argon2id"
     * @param array<string, int> $options e.g. ['cost' => 12] for bcrypt
     */
    public function __construct(string $algorithm = 'default', private readonly array $options = [])
    {
        $this->algorithm = match ($algorithm) {
            'default' => PASSWORD_DEFAULT,
            'bcrypt' => PASSWORD_BCRYPT,
            'argon2id' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : throw new InvalidArgumentException('argon2id is not available in this PHP build; use "bcrypt".'),
            default => throw new InvalidArgumentException(sprintf('Unknown password algorithm "%s". Use default, bcrypt or argon2id.', $algorithm)),
        };
    }

    /**
     * @throws InvalidArgumentException for empty, null-byte or (bcrypt) over-long passwords
     */
    public function hash(#[SensitiveParameter] string $password): string
    {
        $this->assertUsable($password);

        return password_hash($password, $this->algorithm, $this->options);
    }

    /**
     * Constant-time check. Returns false (never throws) for unusable input or malformed hashes.
     */
    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if ($password === '' || $hash === '' || str_contains($password, "\0")) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /**
     * A hash with the configured algorithm and cost that no password matches.
     * Verifying against it when a user does not exist takes as long as a real
     * check, so timing does not reveal which accounts exist.
     *
     * It is assembled from random characters in the hash format instead of being
     * computed: a new hasher is created for every web request, and computing it
     * would make an unknown user cost a hash and a verify, twice a wrong
     * password's single verify (VQ-012). password_verify() still runs the full
     * algorithm at the cost written in the hash.
     */
    public function dummyHash(): string
    {
        if ($this->dummyHash !== null) {
            return $this->dummyHash;
        }

        if ($this->algorithm === PASSWORD_BCRYPT) {
            $cost = $this->options['cost'] ?? PASSWORD_BCRYPT_DEFAULT_COST;

            return $this->dummyHash = sprintf('$2y$%02d$%s', $cost, self::bcryptCharacters(53));
        }

        return $this->dummyHash = sprintf(
            '$argon2id$v=19$m=%d,t=%d,p=%d$%s$%s',
            $this->options['memory_cost'] ?? PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            $this->options['time_cost'] ?? PASSWORD_ARGON2_DEFAULT_TIME_COST,
            $this->options['threads'] ?? PASSWORD_ARGON2_DEFAULT_THREADS,
            rtrim(base64_encode(random_bytes(16)), '='),
            rtrim(base64_encode(random_bytes(32)), '='),
        );
    }

    /**
     * Random characters of bcrypt's alphabet (salt and hash part of a bcrypt hash).
     */
    private static function bcryptCharacters(int $length): string
    {
        $alphabet = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $characters = '';

        for ($i = 0; $i < $length; $i++) {
            $characters .= $alphabet[random_int(0, 63)];
        }

        return $characters;
    }

    private function assertUsable(#[SensitiveParameter] string $password): void
    {
        if ($password === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }

        if (str_contains($password, "\0")) {
            throw new InvalidArgumentException('Password must not contain null bytes.');
        }

        if ($this->algorithm === PASSWORD_BCRYPT && strlen($password) > self::BCRYPT_MAX_BYTES) {
            throw new InvalidArgumentException('Password is longer than 72 bytes, which bcrypt would silently truncate. Limit it with "max_bytes:72" or use argon2id.');
        }
    }
}
