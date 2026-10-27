<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A mistake in how commands are defined or registered (a programming error).
 */
final class ConsoleException extends LogicException implements VeyroPHPException {}
