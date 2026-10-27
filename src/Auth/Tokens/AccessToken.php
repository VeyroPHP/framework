<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

/**
 * A stored API token (never the secret itself).
 */
final class AccessToken
{
    /**
     * @param list<string> $abilities "*" allows everything
     */
    public function __construct(
        public readonly int $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly array $abilities,
        public readonly int $createdAt,
        public readonly ?int $lastUsedAt,
        public readonly ?int $expiresAt,
    ) {}

    public function can(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
