<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

use Closure;

/**
 * Stores each entry as a file named after the SHA-256 of its key.
 *
 * - Writes go to a temporary file that is then renamed, so readers never see
 *   a half-written entry. Concurrent writers: the last rename wins.
 * - Values are serialized with serialize(). On read, objects are restored only
 *   for classes listed in $allowedClasses (default: none, so objects come back
 *   as __PHP_Incomplete_Class). This prevents object injection if someone can
 *   write to the cache directory.
 * - Corrupt or unreadable files count as misses and are deleted.
 * - Locks use flock() on separate lock files and expire after their TTL.
 * - Expired entries are deleted when read; prune() removes the ones nobody reads
 *   again (run "php veyro cache:prune" from cron).
 */
final class FileStore implements LockingStore, PrunableStore
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param list<class-string>|bool $allowedClasses classes unserialize() may instantiate; true allows all (unsafe if the directory is writable by others)
     * @param Closure(): int|null $clock
     */
    public function __construct(
        private readonly string $directory,
        private readonly array|bool $allowedClasses = false,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function fetch(string $key): ?array
    {
        $path = $this->path($key);
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            return null;
        }

        $entry = @unserialize($contents, ['allowed_classes' => $this->allowedClasses]);

        if (!is_array($entry) || !array_key_exists('v', $entry) || !array_key_exists('e', $entry) || ($entry['e'] !== null && !is_int($entry['e']))) {
            @unlink($path);

            return null;
        }

        if ($entry['e'] !== null && $entry['e'] <= ($this->clock)()) {
            @unlink($path);

            return null;
        }

        return [$entry['v']];
    }

    public function put(string $key, mixed $value, ?int $expiresAt): bool
    {
        $path = $this->path($key);

        if (!self::ensureDirectory(dirname($path))) {
            return false;
        }

        try {
            $payload = serialize(['k' => $key, 'e' => $expiresAt, 'v' => $value]);
        } catch (\Throwable) {
            return false; // closures and other unserializable values
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
            @unlink($temporary);

            return false;
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || @unlink($path) || !is_file($path);
    }

    /**
     * Removes entries whose key starts with $prefix. Reads each file's stored key,
     * so it costs one read per entry in the directory.
     */
    public function clear(string $prefix): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $ok = true;

        foreach (glob($this->directory . '/*/*.cache') ?: [] as $file) {
            $contents = @file_get_contents($file);
            $entry = $contents === false ? false : @unserialize($contents, ['allowed_classes' => false]);
            $key = is_array($entry) && is_string($entry['k'] ?? null) ? $entry['k'] : null;

            if ($key === null || str_starts_with($key, $prefix)) {
                $ok = (@unlink($file) || !is_file($file)) && $ok;
            }
        }

        // Remove released or expired lock files of this prefix; held locks stay.
        $now = ($this->clock)();
        foreach (glob($this->directory . '/*/*.cache.lock') ?: [] as $file) {
            $this->removeFreeLockFile($file, $now, $prefix);
        }

        foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            @rmdir($directory); // only succeeds when empty
        }

        return $ok;
    }

    /**
     * Reads every entry once, so it costs one file read per entry; run it from
     * cron rather than during requests.
     */
    public function prune(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $now = ($this->clock)();
        $removed = 0;

        foreach (glob($this->directory . '/*/*.cache') ?: [] as $file) {
            $contents = @file_get_contents($file);

            if ($contents === false) {
                continue; // being replaced or not readable right now
            }

            // Objects are never restored here: only the expiry is needed.
            $entry = @unserialize($contents, ['allowed_classes' => false]);
            $expiresAt = is_array($entry) && array_key_exists('e', $entry) ? $entry['e'] : false;
            $corrupt = $expiresAt !== null && !is_int($expiresAt);
            $expired = is_int($expiresAt) && $expiresAt <= $now;

            if (($corrupt || $expired) && (@unlink($file) || !is_file($file))) {
                $removed++;
            }
        }

        foreach (glob($this->directory . '/*/*.cache.lock') ?: [] as $file) {
            if ($this->removeFreeLockFile($file, $now, null)) {
                $removed++;
            }
        }

        // Temporary files left by a crash between write and rename.
        foreach (glob($this->directory . '/*/*.tmp') ?: [] as $file) {
            $modified = @filemtime($file);

            if ($modified !== false && $modified < $now - 3600 && @unlink($file)) {
                $removed++;
            }
        }

        foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            @rmdir($directory); // only succeeds when empty
        }

        return $removed;
    }

    public function acquireLock(string $name, int $seconds): ?string
    {
        $path = $this->path('lock:' . $name) . '.lock';

        if (!self::ensureDirectory(dirname($path))) {
            return null;
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }

            $current = stream_get_contents($handle);
            $data = is_string($current) && $current !== '' ? json_decode($current, true) : null;
            $now = ($this->clock)();

            if (is_array($data) && is_int($data['expiresAt'] ?? null) && $data['expiresAt'] > $now) {
                flock($handle, LOCK_UN);

                return null;
            }

            // The file may have been removed between fopen() and now; a token written into
            // it would be a second lock next to whoever creates the file again (MISC-1).
            if (!self::isStillAt($handle, $path)) {
                flock($handle, LOCK_UN);

                return null;
            }

            $token = bin2hex(random_bytes(8));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['name' => $name, 'token' => $token, 'expiresAt' => $now + max(1, $seconds)]));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $token;
        } finally {
            fclose($handle);
        }
    }

    public function releaseLock(string $name, string $token): void
    {
        $path = $this->path('lock:' . $name) . '.lock';
        $handle = is_file($path) ? @fopen($path, 'c+') : false;

        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                $data = json_decode((string) stream_get_contents($handle), true);

                if (is_array($data) && ($data['token'] ?? null) === $token) {
                    // Keep the name so clear() can find and remove the released lock file.
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, (string) json_encode(['name' => $name, 'expiresAt' => 0]));
                    fflush($handle);
                }

                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Removes a released or expired lock file, deciding and unlinking while holding its
     * flock(): an acquirer that opened the file before cannot take it in between, and
     * one that opened it earlier sees afterwards that it is gone (isStillAt()). A file
     * someone holds right now is left for the next run. With $prefix, only lock files
     * whose name starts with it (or that have no readable name).
     */
    private function removeFreeLockFile(string $file, int $now, ?string $prefix): bool
    {
        $handle = @fopen($file, 'r+');

        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            $data = json_decode((string) stream_get_contents($handle), true);
            $name = is_array($data) && is_string($data['name'] ?? null) ? $data['name'] : null;
            $expiresAt = is_array($data) && is_int($data['expiresAt'] ?? null) ? $data['expiresAt'] : 0;
            $remove = $expiresAt <= $now && ($prefix === null || $name === null || str_starts_with($name, $prefix)) && self::isStillAt($handle, $file);
            $removed = $remove && @unlink($file);
            flock($handle, LOCK_UN);

            return $removed;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Creates the directory if it is missing. Another process (cache:prune, clear) may have
     * removed it since this process last looked, and PHP's stat cache would still say it
     * exists: a long-running worker then could never write that entry or take that lock
     * again. So the cached answer is dropped first.
     */
    private static function ensureDirectory(string $directory): bool
    {
        clearstatcache(true, $directory);

        return is_dir($directory) || @mkdir($directory, 0o775, true) || is_dir($directory);
    }

    /**
     * Whether $path still names the file $handle has open (same device and inode, or
     * file index on Windows).
     *
     * @param resource $handle
     */
    private static function isStillAt($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $open = fstat($handle);
        $named = @stat($path);

        return $open !== false && $named !== false && $open['dev'] === $named['dev'] && $open['ino'] === $named['ino'];
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->directory . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.cache';
    }
}
