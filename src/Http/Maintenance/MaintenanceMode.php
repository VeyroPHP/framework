<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Maintenance;

use Closure;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Support\Base64Url;

/**
 * Whether the application is down for maintenance, stored in one JSON file
 * (by default storage/maintenance.json, outside public/).
 *
 * - The file is the switch: every web server process and worker sees it at once,
 *   without a cache or a restart.
 * - The bypass secret is never stored, only its SHA-256 hash; each down() makes a
 *   new one, so an old link stops working.
 * - A file that cannot be read counts as down: an unreadable switch must not
 *   silently reopen the site in the middle of a migration.
 */
final class MaintenanceMode
{
    public const DEFAULT_RETRY = 60;

    /** The switch file, relative to the application's base path. */
    public const FILE = 'storage/maintenance.json';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock Unix time, for tests
     */
    public function __construct(private readonly string $file, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function isDown(): bool
    {
        return is_file($this->file);
    }

    /**
     * Takes the application down.
     *
     * @param int $retryAfter seconds for the Retry-After header
     * @param list<string> $allow IP addresses or CIDR ranges that keep access
     *
     * @return string|null the bypass secret when $withSecret, shown once and not stored
     *
     * @throws \InvalidArgumentException for an invalid address or range, or a negative retry
     * @throws \RuntimeException when the file cannot be written
     */
    public function down(int $retryAfter = self::DEFAULT_RETRY, string $message = '', array $allow = [], bool $withSecret = false): ?string
    {
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('Retry-after must not be negative.');
        }

        foreach ($allow as $range) {
            if (!ClientIp::isValidRange($range)) {
                throw new \InvalidArgumentException(sprintf('"%s" is not an IP address or CIDR range.', $range));
            }
        }

        $secret = $withSecret ? Base64Url::secret() : null;
        $state = [
            'since' => ($this->clock)(),
            'retry' => $retryAfter,
            'message' => $message,
            'allow' => $allow,
            'secret_hash' => $secret === null ? null : hash('sha256', $secret),
        ];

        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s.', $directory));
        }

        // Written to a temporary file and renamed, so a request never reads half a file.
        $temporary = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Could not write %s.', $this->file));
        }

        return $secret;
    }

    /**
     * @return bool false when the application was not down
     */
    public function up(): bool
    {
        if (!is_file($this->file)) {
            return false;
        }

        if (!@unlink($this->file) && is_file($this->file)) {
            throw new \RuntimeException(sprintf('Could not delete %s.', $this->file));
        }

        return true;
    }

    /**
     * The current state, or null when up. An unreadable file gives safe defaults.
     *
     * @return array{since: int, retry: int, message: string, allow: list<string>, has_secret: bool}|null
     */
    public function state(): ?array
    {
        $state = $this->current();

        return $state === null ? null : [
            'since' => $state->since,
            'retry' => $state->retry,
            'message' => $state->message,
            'allow' => $state->allow,
            'has_secret' => $state->secretHash !== null,
        ];
    }

    public function isAllowed(string $ip): bool
    {
        return $this->current()?->allows($ip) ?? false;
    }

    public function matchesSecret(string $secret): bool
    {
        return $this->current()?->matchesSecret($secret) ?? false;
    }

    /**
     * @internal the state from one read of the file, or null when up; BlockDuringMaintenance
     * asks all its questions of it
     */
    public function current(): ?MaintenanceState
    {
        if (!is_file($this->file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($this->file), true);
        $data = is_array($data) ? $data : [];
        $allow = [];

        foreach (is_array($data['allow'] ?? null) ? $data['allow'] : [] as $range) {
            if (is_string($range) && ClientIp::isValidRange($range)) {
                $allow[] = $range;
            }
        }

        $secretHash = $data['secret_hash'] ?? null;

        return new MaintenanceState(
            is_int($data['since'] ?? null) ? $data['since'] : 0,
            is_int($data['retry'] ?? null) && $data['retry'] >= 0 ? $data['retry'] : self::DEFAULT_RETRY,
            is_string($data['message'] ?? null) ? $data['message'] : '',
            $allow,
            is_string($secretHash) && preg_match('/^[a-f0-9]{64}$/D', $secretHash) === 1 ? $secretHash : null,
        );
    }
}
