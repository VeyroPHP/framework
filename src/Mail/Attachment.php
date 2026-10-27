<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

use VeyroPHP\Security\MimeType;

/**
 * A file attached to a message.
 *
 *     Attachment::fromPath($app->basePath('storage/invoices/42.pdf'), 'Invoice 42.pdf');
 *     Attachment::fromData($csv, 'report.csv', 'text/csv');
 *
 * The file is read when the attachment is created. Without a MIME type it is
 * detected from the content.
 */
final class Attachment
{
    private function __construct(
        public readonly string $data,
        public readonly string $name,
        public readonly string $mimeType,
    ) {}

    public static function fromPath(string $path, ?string $name = null, ?string $mimeType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MailException(sprintf('Attachment "%s" does not exist or is not readable.', basename($path)));
        }

        $data = file_get_contents($path);

        if ($data === false) {
            throw new MailException(sprintf('Attachment "%s" could not be read.', basename($path)));
        }

        return self::fromData($data, $name ?? basename($path), $mimeType);
    }

    public static function fromData(string $data, string $name, ?string $mimeType = null): self
    {
        $name = trim($name);

        if ($name === '' || preg_match('/[\x00-\x1F\x7F\/\\\\"]/', $name) === 1) {
            throw new MailException('An attachment name must not be empty or contain line breaks, quotes or slashes.');
        }

        $mimeType ??= MimeType::detectData($data) ?? 'application/octet-stream';

        if (preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#iD', $mimeType) !== 1) {
            throw new MailException(sprintf('Invalid attachment MIME type "%s".', $mimeType));
        }

        return new self($data, $name, strtolower($mimeType));
    }
}
