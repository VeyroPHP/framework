<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

use ErrorException;

/**
 * Represents a fatal PHP error (out of memory, compile error, ...) caught at shutdown.
 */
final class FatalError extends ErrorException implements VeyroPHPException {}
