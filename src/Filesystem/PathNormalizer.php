<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

/**
 * Checks disk paths. Rejects instead of "fixing", so "../../.env" is an error
 * and never a different file.
 *
 * @internal used by the disks
 */
final class PathNormalizer
{
    /**
     * A file path: "a/b.txt" (leading "/" allowed and removed).
     */
    public static function file(string $path): string
    {
        $normalized = self::directory($path);

        if ($normalized === '') {
            throw new FilesystemException('A file path must not be empty.');
        }

        return $normalized;
    }

    /**
     * A directory path; "" is the root.
     */
    public static function directory(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new FilesystemException('Paths must not contain backslashes or control characters.');
        }

        if (preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new FilesystemException('Disk paths are relative; drive letters are not allowed.');
        }

        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return '';
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new FilesystemException(sprintf('Invalid path "%s": empty, "." and ".." segments are not allowed.', $path));
            }
        }

        return $trimmed;
    }
}
