<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Env;

use VeyroPHP\Config\Exception\EnvException;

/**
 * Parses the contents of a .env file into name => string value pairs.
 *
 * Supported syntax, one variable per line:
 *
 *     # comment
 *     NAME=value            unquoted; surrounding spaces trimmed; " #" starts a comment
 *     NAME="a \"b\"\n"      double quotes; escapes \n \r \t \" \\ and \$
 *     NAME='literal $x'     single quotes; no escapes
 *     export NAME=value     optional "export " prefix
 *
 * Not supported: multi-line values and ${VAR} interpolation. Both fail
 * loudly instead of producing a surprising value.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class DotenvParser
{
    /**
     * @return array<string, string>
     *
     * @throws EnvException on a syntax error or duplicate name
     */
    public function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = trim($rawLine);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $equals = strpos($line, '=');

            if ($equals === false) {
                throw EnvException::syntax($lineNumber, 'expected NAME=value');
            }

            $name = rtrim(substr($line, 0, $equals));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw EnvException::syntax($lineNumber, 'variable names must match [A-Za-z_][A-Za-z0-9_]*');
            }

            if (array_key_exists($name, $values)) {
                throw EnvException::duplicate($name, $lineNumber);
            }

            $values[$name] = self::parseValue(ltrim(substr($line, $equals + 1)), $lineNumber);
        }

        return $values;
    }

    private static function parseValue(string $raw, int $lineNumber): string
    {
        if ($raw === '') {
            return '';
        }

        return match ($raw[0]) {
            '"' => self::parseDoubleQuoted($raw, $lineNumber),
            "'" => self::parseSingleQuoted($raw, $lineNumber),
            default => self::parseUnquoted($raw, $lineNumber),
        };
    }

    private static function parseUnquoted(string $raw, int $lineNumber): string
    {
        $value = $raw;
        $comment = strpos($raw, ' #');

        if ($comment !== false) {
            $value = substr($raw, 0, $comment);
        }

        $value = rtrim($value);

        if (str_contains($value, '"') || str_contains($value, "'")) {
            throw EnvException::syntax($lineNumber, 'quotes are only allowed around the whole value');
        }

        self::rejectInterpolation($value, $lineNumber);

        return $value;
    }

    private static function parseSingleQuoted(string $raw, int $lineNumber): string
    {
        $end = strpos($raw, "'", 1);

        if ($end === false) {
            throw EnvException::syntax($lineNumber, 'unterminated single-quoted value (multi-line values are not supported)');
        }

        self::assertOnlyCommentAfter(substr($raw, $end + 1), $lineNumber);

        return substr($raw, 1, $end - 1);
    }

    private static function parseDoubleQuoted(string $raw, int $lineNumber): string
    {
        $value = '';
        $length = strlen($raw);

        for ($i = 1; $i < $length; $i++) {
            $char = $raw[$i];

            if ($char === '"') {
                self::assertOnlyCommentAfter(substr($raw, $i + 1), $lineNumber);

                return $value;
            }

            if ($char === '$' && ($raw[$i + 1] ?? '') === '{') {
                self::rejectInterpolation('${', $lineNumber);
            }

            if ($char !== '\\') {
                $value .= $char;
                continue;
            }

            $next = $raw[++$i] ?? '';
            $value .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"', '\\', '$' => $next,
                default => throw EnvException::syntax($lineNumber, 'unknown escape sequence in double-quoted value'),
            };
        }

        throw EnvException::syntax($lineNumber, 'unterminated double-quoted value (multi-line values are not supported)');
    }

    private static function assertOnlyCommentAfter(string $rest, int $lineNumber): void
    {
        $rest = trim($rest);

        if ($rest !== '' && $rest[0] !== '#') {
            throw EnvException::syntax($lineNumber, 'unexpected characters after closing quote');
        }
    }

    private static function rejectInterpolation(string $value, int $lineNumber): void
    {
        if (str_contains($value, '${')) {
            throw EnvException::syntax($lineNumber, '${VAR} interpolation is not supported; use single quotes or \\$ for a literal "$"');
        }
    }
}
