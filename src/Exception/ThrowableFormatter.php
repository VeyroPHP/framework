<?php

declare(strict_types=1);

namespace VeyroPHP\Exception;

use Throwable;

/**
 * Converts exceptions into arrays or text for logs and debug pages.
 *
 * Stack frames never include function arguments, because they can contain
 * passwords, tokens or personal data.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class ThrowableFormatter
{
    private const MAX_PREVIOUS = 10;

    /**
     * @return array{class: class-string<Throwable>, message: string, code: int|string, file: string, line: int, trace: list<string>, previous: array<string, mixed>|null}
     */
    public static function toArray(Throwable $throwable, int $depth = 0): array
    {
        $previous = $throwable->getPrevious();

        return [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => self::frames($throwable),
            'previous' => $previous !== null && $depth < self::MAX_PREVIOUS ? self::toArray($previous, $depth + 1) : null,
        ];
    }

    /**
     * @return list<string> frames like "#0 /app/src/Foo.php(12): App\Foo->bar()"
     */
    public static function frames(Throwable $throwable): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $index => $frame) {
            $location = isset($frame['file']) ? sprintf('%s(%d)', $frame['file'], $frame['line'] ?? 0) : '[internal function]';
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            $frames[] = sprintf('#%d %s: %s', $index, $location, $call);
        }

        return $frames;
    }

    /**
     * Multi-line text: class, message, location and frames, followed by previous exceptions.
     */
    public static function toText(Throwable $throwable): string
    {
        $parts = [];
        $current = $throwable;

        for ($depth = 0; $current !== null && $depth <= self::MAX_PREVIOUS; $depth++) {
            $header = sprintf('%s%s: %s in %s:%d', $depth === 0 ? '' : 'Caused by: ', $current::class, $current->getMessage(), $current->getFile(), $current->getLine());
            $parts[] = $header . "\n" . implode("\n", self::frames($current));
            $current = $current->getPrevious();
        }

        return implode("\n\n", $parts);
    }
}
