<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Passwords;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use SensitiveParameter;
use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Auth\Events\PasswordReset;
use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Support\Base64Url;

/**
 * "Forgot password": sends a one-time link and sets a new password with it.
 *
 *     $status = $broker->sendResetLink($email);      // LinkSent, UserNotFound or Throttled
 *     $status = $broker->reset($email, $token, $new); // PasswordReset or InvalidToken
 *
 * - Tokens are 32 random bytes. Only their SHA-256 hash is stored, so a copy
 *   of the database cannot be used to reset passwords.
 * - A token works once, for "expire" minutes (default 60), and only for the
 *   account it was made for. A new link replaces the previous one.
 * - At most one link per account every "throttle" seconds (default 60).
 * - Wrong, used, expired and unknown-account tokens all give InvalidToken.
 * - Tokens are compared in constant time.
 */
final class PasswordBroker
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(Model, string, string): void $sendLink sends the link: (user, email address, plain token)
     * @param (Closure(): int)|null $clock
     * @param (Closure(Model): void)|null $afterReset runs after a successful reset, for example to revoke API tokens
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Orm $orm,
        private readonly AuthConfig $config,
        private readonly PasswordHasher $hasher,
        private readonly Closure $sendLink,
        private readonly string $table = 'password_reset_tokens',
        private readonly string $emailColumn = 'email',
        private readonly int $expireMinutes = 60,
        private readonly int $throttleSeconds = 60,
        private readonly ?EventDispatcherInterface $events = null,
        ?Closure $clock = null,
        private readonly ?Closure $afterReset = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function sendResetLink(string $identifier): ResetStatus
    {
        $user = $this->findUser($identifier);

        if ($user === null) {
            return ResetStatus::UserNotFound;
        }

        $key = $this->key($user);
        $email = $user->raw($this->emailColumn);

        if (!is_string($email) || $email === '') {
            return ResetStatus::UserNotFound;
        }

        $now = ($this->clock)();
        $last = $this->connection->table($this->table)->where('identifier', $key)->value('created_at');

        if (is_numeric($last) && (int) $last > $now - $this->throttleSeconds) {
            return ResetStatus::Throttled;
        }

        $token = Base64Url::secret();

        $this->connection->transaction(function () use ($key, $token, $now): void {
            $this->connection->table($this->table)->where('identifier', $key)->delete();
            $this->connection->table($this->table)->insert([
                'identifier' => $key,
                'token_hash' => hash('sha256', $token),
                'created_at' => $now,
            ]);
        });

        ($this->sendLink)($user, $email, $token);

        return ResetStatus::LinkSent;
    }

    /**
     * Checks a token without using it, for example before showing the form.
     */
    public function tokenIsValid(string $identifier, #[SensitiveParameter] string $token): bool
    {
        $user = $this->findUser($identifier);

        return $user !== null && $this->matchingRow($this->key($user), $token) !== null;
    }

    /**
     * The token is used up by a delete that must remove exactly the row that was checked,
     * in the same transaction as the password change. Of two resets with one token only
     * one can remove the row, so only one changes the password (SVC-4); a failed save
     * rolls the delete back, so the token still works.
     */
    public function reset(string $identifier, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): ResetStatus
    {
        $user = $this->findUser($identifier);
        $row = $user === null ? null : $this->matchingRow($this->key($user), $token);

        if ($user === null || $row === null) {
            return ResetStatus::InvalidToken;
        }

        // Hashed before the transaction: bcrypt is slow, and locks should be short.
        $hash = $this->hasher->hash($password);

        $consumed = $this->connection->transaction(function () use ($user, $row, $hash): bool {
            $removed = $this->connection->table($this->table)
                ->where('identifier', $this->key($user))
                ->where('token_hash', $row['token_hash'])
                ->where('created_at', $row['created_at'])
                ->delete();

            if ($removed !== 1) {
                return false; // another request used the token first
            }

            $user->set($this->config->passwordColumn, $hash);
            $this->orm->save($user);

            return true;
        });

        if (!$consumed) {
            return ResetStatus::InvalidToken;
        }

        if ($this->afterReset !== null) {
            ($this->afterReset)($user);
        }

        $this->events?->dispatch(new PasswordReset($user));

        return ResetStatus::PasswordReset;
    }

    /**
     * Deletes expired tokens; returns how many ("php veyro auth:clear-resets").
     */
    public function deleteExpired(): int
    {
        return $this->connection->table($this->table)
            ->where('created_at', '<', ($this->clock)() - $this->expireMinutes * 60)
            ->delete();
    }

    public function expireMinutes(): int
    {
        return $this->expireMinutes;
    }

    /**
     * The stored token row when $token is valid for the account, or null.
     *
     * @return array{token_hash: string, created_at: int|string}|null
     */
    private function matchingRow(string $key, #[SensitiveParameter] string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = $this->connection->table($this->table)->where('identifier', $key)->first();
        $hash = $row['token_hash'] ?? null;
        $createdAt = $row['created_at'] ?? null;

        if (!is_string($hash) || !(is_int($createdAt) || is_string($createdAt)) || !is_numeric($createdAt)) {
            return null;
        }

        if ((int) $createdAt < ($this->clock)() - $this->expireMinutes * 60) {
            return null;
        }

        return hash_equals($hash, hash('sha256', $token)) ? ['token_hash' => $hash, 'created_at' => $createdAt] : null;
    }

    private function findUser(string $identifier): ?Model
    {
        $identifier = trim($identifier);

        return $identifier === '' ? null : $this->orm->query($this->config->model)->where($this->config->identifier, $identifier)->first();
    }

    /**
     * Tokens belong to the account (its primary key), not to the text typed in the form.
     */
    private function key(Model $user): string
    {
        return (string) $user->getKey();
    }
}
