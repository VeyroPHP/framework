<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

final class FileNotFoundException extends FilesystemException
{
    public static function at(string $path): self
    {
        return new self(sprintf('File "%s" does not exist.', $path));
    }
}
