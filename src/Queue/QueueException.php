<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A job that cannot be queued or run: data that cannot be stored, an unknown
 * connection, a missing model, a handle() that cannot be called, or a queue
 * backend that is not reachable.
 */
class QueueException extends RuntimeException implements VeyroPHPException {}
