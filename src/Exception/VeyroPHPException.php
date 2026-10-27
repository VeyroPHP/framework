<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by the framework.
 *
 * Applications can catch this interface to handle any framework failure
 * without depending on concrete exception classes.
 */
interface VeyroPHPException extends Throwable {}
