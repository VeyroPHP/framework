<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * An invalid path, a file operation that failed, or a storage service error.
 * Messages never contain credentials.
 */
class FilesystemException extends RuntimeException implements VeyroPHPException {}
