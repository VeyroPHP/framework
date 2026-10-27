<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

use Closure;
use SensitiveParameter;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Orm\Model;
use VeyroPHP\Support\Base64Url;

/**
 * Personal API tokens, sent as "Authorization: Bearer <token>".
 *
 *     $new = $tokens->create($user, 'Mobile app', ['orders.read'], expiresAt: time() + 90 * 86400);
 *     echo $new->plainText;   // "vyr_12_..." shown once
 *
 * - Format "vyr_<id>_<secret>": the prefix lets secret scanners (GitHub,
 *   GitGuardian) recognise leaked tokens; the id finds the row, the 32-byte
 *   secret is compared with the stored SHA-256 in constant time.
 * - Abilities limit what a token may do; "*" allows everything the user may do.
 *   Abilities never add permissions: RBAC checks still apply to the user.
 * - Tokens can expire and can be revoked one by one or all at once.
 * - last_used_at is written at most once per minute per token, so busy APIs do
 *   not write on every request.
 */
final class ApiTokens
{
    public const PREFIX = 'vyr_';

    private const TOUCH_INTERVAL = 60;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'personal_access_tokens',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param array<mixed> $abilities ability names (checked here)
     */
    public function create(Model $user, string $name, array $abilities = ['*'], ?int $expiresAt = null): NewAccessToken
    {
        $userId = $user->getKey() ?? throw new TokenException('Tokens can only be created for saved users.');
        $name = trim($name);

        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new TokenException('A token name must be 1 to 100 characters without control characters.');
        }

        $checked = [];

        foreach ($abilities as $ability) {
            if (!is_string($ability) || preg_match('/^(\*|[A-Za-z0-9_.:-]{1,100})$/D', $ability) !== 1) {
                throw new TokenException('Abilities are names such as "orders.read" (letters, digits, ".", ":", "_", "-"), or "*".');
            }

            $checked[] = $ability;
        }

        $now = ($this->clock)();

        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new TokenException('The expiry must be in the future.');
        }

        $secret = Base64Url::secret();
        $abilities = array_values(array_unique($checked));

        $id = (int) $this->connection->table($this->table)->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $secret),
            'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
            'last_used_at' => null,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return new NewAccessToken(
            new AccessToken($id, (string) $userId, $name, $abilities, $now, null, $expiresAt),
            self::PREFIX . $id . '_' . $secret,
        );
    }

    /**
     * The token for a plain-text secret, or null when it is unknown, wrong, expired or revoked.
     * Records the use.
     */
    public function find(#[SensitiveParameter] string $plainText): ?AccessToken
    {
        if (preg_match('/^' . self::PREFIX . '(\d{1,18})_([A-Za-z0-9_-]{43})$/D', $plainText, $m) !== 1) {
            return null;
        }

        $row = $this->connection->table($this->table)->where('id', (int) $m[1])->first();

        if ($row === null || !is_string($row['token_hash'] ?? null) || !hash_equals($row['token_hash'], hash('sha256', $m[2]))) {
            return null;
        }

        $token = self::hydrate($row);
        $now = ($this->clock)();

        if ($token->isExpired($now)) {
            return null;
        }

        if ($token->lastUsedAt === null || $token->lastUsedAt <= $now - self::TOUCH_INTERVAL) {
            $this->connection->table($this->table)->where('id', $token->id)->update(['last_used_at' => $now]);
        }

        return $token;
    }

    /**
     * @return list<AccessToken> newest first
     */
    public function tokensFor(Model $user): array
    {
        return array_map(
            self::hydrate(...),
            $this->connection->table($this->table)->where('user_id', $user->getKey())->orderByDesc('id')->get(),
        );
    }

    /**
     * Revokes one token of $user (a user cannot revoke other users' tokens this way).
     */
    public function revoke(Model $user, int $tokenId): bool
    {
        return $this->connection->table($this->table)->where('id', $tokenId)->where('user_id', $user->getKey())->delete() === 1;
    }

    /**
     * Revokes every token of $user, for example after a password change.
     */
    public function revokeAll(Model $user): int
    {
        return $this->connection->table($this->table)->where('user_id', $user->getKey())->delete();
    }

    /**
     * Deletes expired tokens ("php veyro token:prune").
     */
    public function pruneExpired(): int
    {
        return $this->connection->table($this->table)->whereNotNull('expires_at')->where('expires_at', '<=', ($this->clock)())->delete();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AccessToken
    {
        $abilities = json_decode(is_string($row['abilities'] ?? null) ? $row['abilities'] : '[]', true);
        $int = static fn(string $key): ?int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;

        return new AccessToken(
            (int) $int('id'),
            is_scalar($row['user_id'] ?? null) ? (string) $row['user_id'] : '',
            is_string($row['name'] ?? null) ? $row['name'] : '',
            is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [],
            (int) $int('created_at'),
            $int('last_used_at'),
            $int('expires_at'),
        );
    }
}
