<?php

declare(strict_types=1);

namespace VeyroPHP\Encryption;

/**
 * An encrypted value was changed, made with another key or purpose, or is not
 * an encrypted value at all. Treat it like any other invalid input (for example
 * respond with 400 or 404); the message never contains the value or the key.
 */
final class DecryptException extends EncryptionException {}
