<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use VeyroPHP\Support\Ulid;

/**
 * Stores uploaded files safely.
 *
 * - The client's filename is never used: files get a ULID name and an extension
 *   derived from their detected content, so names cannot traverse directories,
 *   overwrite other files or smuggle ".php".
 * - The base directory should be outside the web root (e.g. storage/uploads);
 *   serve files through a controller that checks access.
 * - Validate first (Validator rules "file", "mimes", "max_size", "image").
 */
final class UploadStore
{
    public function __construct(private readonly string $baseDirectory) {}

    /**
     * @return string the stored path relative to the base directory, e.g. "avatars/01JB...png"
     *
     * @throws InvalidArgumentException for a failed upload or an invalid subdirectory
     * @throws RuntimeException when the file cannot be written
     */
    public function store(UploadedFileInterface $file, string $subdirectory = ''): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(sprintf('Upload failed with error code %d.', $file->getError()));
        }

        if ($subdirectory !== '' && preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#D', $subdirectory) !== 1) {
            throw new InvalidArgumentException('Upload subdirectory may only contain lowercase letters, digits, "_", "-" and "/" between segments.');
        }

        $mimeType = MimeType::detect($file) ?? 'application/octet-stream';
        $relative = ($subdirectory === '' ? '' : $subdirectory . '/') . Ulid::generate() . '.' . MimeType::extensionFor($mimeType);
        $target = rtrim($this->baseDirectory, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create upload directory "%s".', $directory));
        }

        $file->moveTo($target);

        return $relative;
    }
}
