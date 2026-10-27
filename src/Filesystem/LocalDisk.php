<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use FilesystemIterator;
use finfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VeyroPHP\Support\Extensions;

/**
 * Files in a local directory.
 *
 * - Every path stays inside the root: "..", absolute paths and backslashes are rejected.
 * - Writes go to a temporary file that is renamed into place, so readers never
 *   see half-written files.
 * - url() needs a public base URL (the "public" disk with "php veyro storage:link").
 */
final class LocalDisk implements Disk
{
    private readonly string $root;

    public function __construct(string $root, private readonly ?string $publicUrl = null)
    {
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new FilesystemException(sprintf('Storage directory "%s" could not be created.', $root));
        }

        $this->root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function exists(string $path): bool
    {
        return is_file($this->full(PathNormalizer::file($path)));
    }

    public function get(string $path): string
    {
        $contents = @file_get_contents($this->existing($path));

        if ($contents === false) {
            throw new FilesystemException(sprintf('File "%s" could not be read.', $path));
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $stream = @fopen($this->existing($path), 'rb');

        if ($stream === false) {
            throw new FilesystemException(sprintf('File "%s" could not be opened.', $path));
        }

        return $stream;
    }

    public function put(string $path, string $contents): void
    {
        $this->write($path, static fn($handle): int|false => fwrite($handle, $contents));
    }

    public function putStream(string $path, $stream): void
    {
        if (!is_resource($stream)) {
            throw new FilesystemException('putStream() needs an open stream.');
        }

        $this->write($path, static fn($handle): int|false => stream_copy_to_stream($stream, $handle));
    }

    public function delete(string $path): bool
    {
        $full = $this->full(PathNormalizer::file($path));

        return is_file($full) && @unlink($full);
    }

    public function copy(string $from, string $to): void
    {
        $source = $this->existing($from);
        $target = $this->prepare($to);

        if (!@copy($source, $target)) {
            throw new FilesystemException(sprintf('Could not copy "%s" to "%s".', $from, $to));
        }
    }

    public function move(string $from, string $to): void
    {
        $source = $this->existing($from);
        $target = $this->prepare($to);

        if (!@rename($source, $target)) {
            throw new FilesystemException(sprintf('Could not move "%s" to "%s".', $from, $to));
        }
    }

    public function size(string $path): int
    {
        $size = @filesize($this->existing($path));

        return $size === false ? throw new FilesystemException(sprintf('Size of "%s" unknown.', $path)) : $size;
    }

    public function lastModified(string $path): int
    {
        $time = @filemtime($this->existing($path));

        return $time === false ? throw new FilesystemException(sprintf('Modification time of "%s" unknown.', $path)) : $time;
    }

    public function mimeType(string $path): string
    {
        Extensions::require('fileinfo', 'detecting a file type');
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($this->existing($path));

        return is_string($type) ? $type : 'application/octet-stream';
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->list($directory, $recursive, files: true);
    }

    public function directories(string $directory = ''): array
    {
        return $this->list($directory, false, files: false);
    }

    public function deleteDirectory(string $directory): void
    {
        $relative = PathNormalizer::directory($directory);

        if ($relative === '') {
            throw new FilesystemException('Refusing to delete the root of the disk.');
        }

        $full = $this->full($relative);

        if (!is_dir($full)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            // Links are removed, never followed.
            $ok = $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());

            if (!$ok) {
                throw new FilesystemException(sprintf('Could not delete "%s".', $directory));
            }
        }

        if (!@rmdir($full)) {
            throw new FilesystemException(sprintf('Could not delete "%s".', $directory));
        }
    }

    public function url(string $path): string
    {
        if ($this->publicUrl === null) {
            throw new FilesystemException('This disk is not public: it has no "url" in config/filesystems.php.');
        }

        return rtrim($this->publicUrl, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', PathNormalizer::file($path))));
    }

    private function full(string $relative): string
    {
        return $relative === '' ? $this->root : $this->root . '/' . $relative;
    }

    private function existing(string $path): string
    {
        $full = $this->full(PathNormalizer::file($path));

        return is_file($full) ? $full : throw FileNotFoundException::at($path);
    }

    /**
     * Creates the parent directory and returns the full path.
     */
    private function prepare(string $path): string
    {
        $full = $this->full(PathNormalizer::file($path));
        $dir = dirname($full);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new FilesystemException(sprintf('Directory for "%s" could not be created.', $path));
        }

        if (is_dir($full)) {
            throw new FilesystemException(sprintf('"%s" is a directory.', $path));
        }

        return $full;
    }

    /**
     * @param \Closure(resource): (int|false) $writer
     */
    private function write(string $path, \Closure $writer): void
    {
        $full = $this->prepare($path);
        $temp = dirname($full) . '/.' . basename($full) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temp, 'xb');

        if ($handle === false) {
            throw new FilesystemException(sprintf('File "%s" could not be written.', $path));
        }

        try {
            $ok = $writer($handle) !== false && fflush($handle);
        } finally {
            fclose($handle);
        }

        if (!$ok || !@rename($temp, $full)) {
            @unlink($temp);

            throw new FilesystemException(sprintf('File "%s" could not be written.', $path));
        }

        @chmod($full, 0644);
    }

    /**
     * @return list<string>
     */
    private function list(string $directory, bool $recursive, bool $files): array
    {
        $relative = PathNormalizer::directory($directory);
        $full = $this->full($relative);

        if (!is_dir($full)) {
            return [];
        }

        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST)
            : new FilesystemIterator($full, FilesystemIterator::SKIP_DOTS);
        $found = [];

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);

            if (($files && !$item->isFile()) || (!$files && !$item->isDir()) || str_ends_with($item->getFilename(), '.tmp') && str_starts_with($item->getFilename(), '.')) {
                continue;
            }

            $found[] = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($this->root)), '/');
        }

        sort($found);

        return $found;
    }
}
