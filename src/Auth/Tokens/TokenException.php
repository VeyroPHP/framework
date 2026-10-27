<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

use InvalidArgumentException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A token cannot be created as requested (invalid name, ability or expiry).
 */
final class TokenException extends InvalidArgumentException implements VeyroPHPException {}
