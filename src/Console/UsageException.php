<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * The user called a command wrongly (unknown option, missing argument).
 * The console prints the message and the command's usage, and exits with 2.
 */
final class UsageException extends RuntimeException implements VeyroPHPException {}
