<?php

declare(strict_types=1);

namespace VeyroPHP\Logging;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;
use VeyroPHP\Exception\ThrowableFormatter;
use VeyroPHP\Logging\Exception\LoggingException;

/**
 * PSR-3 logger that appends one line per entry to a file.
 *
 *     [2026-10-25T10:15:00.123456+00:00] app.ERROR: Payment failed {"order":42}
 *
 * - Entries below the minimum level are dropped.
 * - "{key}" placeholders are replaced with scalar context values (PSR-3).
 * - Context is redacted before it is used (see Redactor) and appended as JSON.
 * - Line breaks in the message are escaped, so a message cannot forge extra entries.
 * - An exception under the "exception" key is logged with its trace, without arguments.
 * - Write failures throw LoggingException instead of being ignored.
 * - An entry longer than MAX_ENTRY_BYTES (256 KiB) is cut, ending in "[truncated N bytes]".
 * - With $keepDays > 0, entries go to one file per day ("veyrophp-2026-10-27.log" for
 *   "veyrophp.log") and files of that pattern older than $keepDays days are deleted
 *   when a new day's file starts. With 0 (the default) there is one file; rotate it
 *   externally (logrotate): every entry reopens the path, so moving the file is safe.
 */
final class FileLogger extends AbstractLogger
{
    private const SEVERITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private const MAX_DEPTH = 10;

    /** Longest entry written, newline included; longer ones are cut with a marker. */
    public const MAX_ENTRY_BYTES = 262_144;

    private readonly int $minimumSeverity;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param Closure(): DateTimeImmutable|null $clock defaults to the current time
     * @param int $keepDays 0 for one file; otherwise daily files, kept for this many days
     *
     * @throws InvalidArgumentException when $minimumLevel is not a PSR-3 level
     * @throws LoggingException when $channel contains invalid characters
     */
    public function __construct(
        private readonly string $path,
        string $minimumLevel = LogLevel::DEBUG,
        private readonly string $channel = 'app',
        private readonly Redactor $redactor = new Redactor(),
        ?Closure $clock = null,
        private readonly int $keepDays = 0,
    ) {
        if ($keepDays < 0) {
            throw new \InvalidArgumentException('Log retention must be 0 (one file) or a number of days.');
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/D', $channel) !== 1) {
            throw LoggingException::invalidChannel($channel);
        }

        $this->minimumSeverity = self::severity($minimumLevel);
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     *
     * @throws InvalidArgumentException when $level is not a PSR-3 level
     * @throws LoggingException when the entry cannot be written
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level)) {
            throw new InvalidArgumentException(sprintf('Log level must be a string, %s given.', get_debug_type($level)));
        }

        if (self::severity($level) < $this->minimumSeverity) {
            return;
        }

        $context = $this->redactor->redact($context);
        $text = self::escapeLineBreaks(self::interpolate((string) $message, $context));

        $now = ($this->clock)();
        $line = sprintf(
            '[%s] %s.%s: %s',
            $now->format('Y-m-d\TH:i:s.uP'),
            $this->channel,
            strtoupper($level),
            $text,
        );

        if ($context !== []) {
            $line .= ' ' . self::encodeContext($context);
        }

        $line = self::limit($line);

        if ($this->keepDays === 0) {
            $this->append($this->path, $line . "\n");

            return;
        }

        [$prefix, $suffix] = $this->dailyNameParts();
        $path = dirname($this->path) . DIRECTORY_SEPARATOR . $prefix . $now->format('Y-m-d') . $suffix;
        $isNewFile = !is_file($path);
        $this->append($path, $line . "\n");

        if ($isNewFile) {
            $this->deleteOldFiles($now);
        }
    }

    /**
     * The daily file name around the date: "veyrophp.log" gives ["veyrophp-", ".log"].
     *
     * @return array{string, string}
     */
    private function dailyNameParts(): array
    {
        $name = basename($this->path);
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return $extension === ''
            ? [$name . '-', '']
            : [substr($name, 0, -strlen($extension) - 1) . '-', '.' . $extension];
    }

    /**
     * Deletes this logger's daily files older than the retention. Only names of the exact
     * pattern (base-YYYY-MM-DD.ext) are touched; compressed or other files are left alone.
     */
    private function deleteOldFiles(DateTimeImmutable $now): void
    {
        $oldestKept = $now->modify('-' . ($this->keepDays - 1) . ' days')->format('Y-m-d');
        [$prefix, $suffix] = $this->dailyNameParts();
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{4}-\d{2}-\d{2})' . preg_quote($suffix, '/') . '$/D';
        $directory = dirname($this->path);

        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match($pattern, $name, $m) === 1 && $m[1] < $oldestKept) {
                @unlink($directory . DIRECTORY_SEPARATOR . $name);
            }
        }
    }

    /**
     * Cuts an entry longer than MAX_ENTRY_BYTES (newline included) at a UTF-8 character
     * boundary and says how much was cut, so one huge context cannot fill the disk alone.
     */
    private static function limit(string $line): string
    {
        if (strlen($line) + 1 <= self::MAX_ENTRY_BYTES) {
            return $line;
        }

        // Room for " [truncated N bytes]" and the newline.
        $kept = mb_strcut($line, 0, self::MAX_ENTRY_BYTES - 40, 'UTF-8');

        return $kept . ' [truncated ' . (strlen($line) - strlen($kept)) . ' bytes]';
    }

    private static function severity(string $level): int
    {
        return self::SEVERITY[$level] ?? throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $level));
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        return preg_replace_callback(
            '/\{([A-Za-z0-9_.]+)\}/',
            static function (array $match) use ($context): string {
                $key = $match[1];

                if (!array_key_exists($key, $context)) {
                    return $match[0];
                }

                $value = $context[$key];

                return match (true) {
                    $value === null => 'null',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_scalar($value), $value instanceof Stringable => (string) $value,
                    $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339),
                    default => $match[0],
                };
            },
            $message,
        ) ?? $message;
    }

    private static function escapeLineBreaks(string $text): string
    {
        return str_replace(["\r", "\n"], ['\r', '\n'], $text);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function encodeContext(array $context): string
    {
        return json_encode(
            self::normalize($context, 0),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: '{}';
    }

    private static function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[max depth reached]';
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_array($value) => array_map(static fn(mixed $item): mixed => self::normalize($item, $depth + 1), $value),
            $value instanceof Throwable => ThrowableFormatter::toArray($value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            $value instanceof Stringable => (string) $value,
            is_object($value) => '[object ' . $value::class . ']',
            is_resource($value) => '[resource ' . get_resource_type($value) . ']',
            default => '[' . get_debug_type($value) . ']',
        };
    }

    private function append(string $path, string $line): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw LoggingException::cannotCreateDirectory($directory, error_get_last()['message'] ?? 'unknown error');
        }

        $handle = @fopen($path, 'ab');

        if ($handle === false) {
            throw LoggingException::cannotWrite($path, error_get_last()['message'] ?? 'unknown error');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw LoggingException::cannotWrite($path, 'could not acquire lock');
            }

            $written = fwrite($handle, $line);
            flock($handle, LOCK_UN);

            if ($written !== strlen($line)) {
                throw LoggingException::cannotWrite($path, 'incomplete write');
            }
        } finally {
            fclose($handle);
        }
    }
}
