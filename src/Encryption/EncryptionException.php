<?php

declare(strict_types=1);

namespace VeyroPHP\Encryption;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Encryption cannot be used: missing or invalid key, missing extension, or a value that cannot be encoded.
 */
class EncryptionException extends RuntimeException implements VeyroPHPException {}
