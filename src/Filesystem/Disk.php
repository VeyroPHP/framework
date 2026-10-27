<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

/**
 * A place to store files: a local directory or an S3-compatible bucket.
 *
 * Paths are relative ("invoices/2026/42.pdf"), use "/" and may not contain
 * "..", "." segments, backslashes or null bytes; invalid paths throw
 * FilesystemException before anything is touched.
 */
interface Disk
{
    public function exists(string $path): bool;

    /**
     * @throws FileNotFoundException
     */
    public function get(string $path): string;

    /**
     * @return resource
     *
     * @throws FileNotFoundException
     */
    public function readStream(string $path);

    /**
     * Writes (or replaces) a file; missing directories are created.
     */
    public function put(string $path, string $contents): void;

    /**
     * @param resource $stream read from its current position to the end
     */
    public function putStream(string $path, $stream): void;

    /**
     * @return bool false when the file did not exist
     */
    public function delete(string $path): bool;

    public function copy(string $from, string $to): void;

    public function move(string $from, string $to): void;

    /**
     * @throws FileNotFoundException
     */
    public function size(string $path): int;

    /**
     * Unix time.
     *
     * @throws FileNotFoundException
     */
    public function lastModified(string $path): int;

    /**
     * @throws FileNotFoundException
     */
    public function mimeType(string $path): string;

    /**
     * Files under $directory ("" for the root), as paths relative to the disk, sorted.
     *
     * @return list<string>
     */
    public function files(string $directory = '', bool $recursive = false): array;

    /**
     * @return list<string>
     */
    public function directories(string $directory = ''): array;

    /**
     * Deletes a directory with everything in it.
     */
    public function deleteDirectory(string $directory): void;

    /**
     * The public URL of a file on a public disk.
     *
     * @throws FilesystemException when the disk has no public URL
     */
    public function url(string $path): string;
}
