<?php

declare(strict_types=1);

namespace VeyroPHP\Exception\Renderer;

use Throwable;

/**
 * Turns an uncaught exception into output for the user.
 *
 * With $debug false, implementations must not reveal the message, file paths
 * or stack trace.
 */
interface ExceptionRenderer
{
    public function render(Throwable $throwable, bool $debug): string;

    public function contentType(): string;
}
