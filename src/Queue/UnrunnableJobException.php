<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

/**
 * A queued job that can never run: its payload is invalid, its class was
 * deleted or renamed, its constructor or handle() no longer fit, or a model it
 * needs was deleted. The worker fails it at once instead of retrying.
 */
class UnrunnableJobException extends QueueException {}
