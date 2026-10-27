<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Access;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * An authorization rule is set up wrongly (a programming error, not a denial).
 */
final class AccessException extends LogicException implements VeyroPHPException {}
