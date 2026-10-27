<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use VeyroPHP\Support\Extensions;

/**
 * Detects an upload's type from its content (finfo), never from the client's
 * filename or Content-Type, which the client controls.
 */
final class MimeType
{
    /** @var array<string, list<string>> extension => content types that count as that extension */
    public const EXTENSIONS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'zip' => ['application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * The detected content type, or null when the upload cannot be read.
     */
    public static function detect(UploadedFileInterface $file): ?string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $head = $stream->read(65536);
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        } catch (\RuntimeException) {
            return null;
        }

        return self::detectData($head);
    }

    /**
     * The content type of raw data (only the first 64 KB are examined).
     */
    public static function detectData(string $data): ?string
    {
        if ($data === '') {
            return 'application/x-empty';
        }

        Extensions::require('fileinfo', 'detecting the type of an upload');
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer(substr($data, 0, 65536));

        return is_string($type) ? $type : null;
    }

    /**
     * The canonical extension for a detected type ("bin" when unknown).
     */
    public static function extensionFor(string $mimeType): string
    {
        foreach (self::EXTENSIONS as $extension => $types) {
            if ($types[0] === $mimeType) {
                return $extension;
            }
        }

        return 'bin';
    }
}
