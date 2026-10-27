<?php

declare(strict_types=1);

namespace VeyroPHP\Exception\Renderer;

use Throwable;
use VeyroPHP\Exception\ThrowableFormatter;

/**
 * Plain text output, used for the command line.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class PlainTextRenderer implements ExceptionRenderer
{
    public const PRODUCTION_MESSAGE = 'An unexpected error occurred. Check the application log for details.';

    public function render(Throwable $throwable, bool $debug): string
    {
        return ($debug ? ThrowableFormatter::toText($throwable) : self::PRODUCTION_MESSAGE) . "\n";
    }

    public function contentType(): string
    {
        return 'text/plain; charset=utf-8';
    }
}
