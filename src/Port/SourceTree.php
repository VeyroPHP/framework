<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

use VeyroPHP\Support\Extensions;

/**
 * Read-only access to a project being analyzed. Files are only read as text
 * and tokenized: nothing is included, required or executed. Symlinks and
 * junctions are not followed, dependency and runtime folders are skipped, and
 * files that hold secrets (.env, keys) are never opened.
 */
final class SourceTree
{
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.svn', '.idea', '.vscode', 'storage', 'writable', 'bootstrap/cache', 'public/build', 'public/vendor'];

    private const MAX_FILES = 20_000;

    private const MAX_BYTES = 2_000_000;

    public readonly string $root;

    /** @var list<string>|null relative paths with "/" separators */
    private ?array $files = null;

    private bool $truncated = false;

    public function __construct(string $root)
    {
        // Every analysis and conversion starts here, and all of them tokenize PHP.
        Extensions::require('tokenizer', 'the porting assistant');
        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            throw new PortException("{$root} is not a directory.");
        }

        $this->root = str_replace('\\', '/', $real);
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = [];
        $this->walk('', $files);
        sort($files);

        return $this->files = $files;
    }

    public function truncated(): bool
    {
        $this->files();

        return $this->truncated;
    }

    public function exists(string $relative): bool
    {
        return in_array($relative, $this->files(), true);
    }

    /**
     * Files below $directory (relative, no trailing slash) whose names end with $suffix.
     *
     * @return list<string>
     */
    public function under(string $directory, string $suffix = '.php'): array
    {
        $prefix = rtrim($directory, '/') . '/';

        return array_values(array_filter($this->files(), static fn(string $f): bool => str_starts_with($f, $prefix) && str_ends_with($f, $suffix)));
    }

    /**
     * Whether read() returns "" for this file because it is larger than the read limit.
     */
    public function tooLarge(string $relative): bool
    {
        return $this->exists($relative) && filesize($this->root . '/' . $relative) > self::MAX_BYTES;
    }

    public function read(string $relative): string
    {
        if (self::secret($relative)) {
            throw new PortException("{$relative} may contain secrets and is never read.");
        }

        if (!$this->exists($relative)) {
            return '';
        }

        $path = $this->root . '/' . $relative;

        return filesize($path) > self::MAX_BYTES ? '' : (string) file_get_contents($path);
    }

    /**
     * @return array<mixed>|null
     */
    public function json(string $relative): ?array
    {
        $data = json_decode($this->read($relative), true);

        return is_array($data) ? $data : null;
    }

    /**
     * PHP tokens of a file; tokenizing does not execute anything.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    public function tokens(string $relative): array
    {
        return token_get_all($this->read($relative));
    }

    public static function secret(string $relative): bool
    {
        $name = strtolower(basename($relative));

        return ($name === '.env' || str_starts_with($name, '.env.')) && $name !== '.env.example'
            || preg_match('/\.(pem|key|p12|pfx)$/', $name) === 1
            || in_array($name, ['auth.json', 'oauth-private.key', 'oauth-public.key'], true);
    }

    /**
     * @param list<string> $files
     */
    private function walk(string $relative, array &$files): void
    {
        $dir = $this->root . ($relative === '' ? '' : '/' . $relative);
        $entries = @scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (count($files) >= self::MAX_FILES) {
                $this->truncated = true;

                return;
            }

            $child = $relative === '' ? $entry : $relative . '/' . $entry;
            $path = $dir . '/' . $entry;

            if (is_link($path) || str_replace('\\', '/', (string) realpath($path)) !== $path) {
                continue; // symlink or junction: never followed
            }

            if (is_dir($path)) {
                if (!in_array($child, self::SKIP_DIRS, true) && !in_array($entry, ['vendor', 'node_modules', '.git'], true)) {
                    $this->walk($child, $files);
                }
            } elseif (!self::secret($child)) {
                $files[] = $child;
            }
        }
    }
}
