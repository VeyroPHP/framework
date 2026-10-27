<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

/**
 * Reads and writes small PHP literals for the converters: strings, numbers,
 * booleans, null, Foo::class and arrays of them. Parsing uses tokens only;
 * nothing is evaluated.
 */
final class Php
{
    /**
     * Splits a call's argument list (the text between the parentheses) at
     * top-level commas.
     *
     * @return list<string>
     */
    public static function splitArgs(string $args): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (self::tokens($args) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true) || is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $text;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @internal the offset of the ")" that closes the "(" at $open, skipping quoted
     * strings; null when it is not closed (CORE-7).
     */
    public static function matchingParen(string $text, int $open): ?int
    {
        $depth = 0;
        $quote = null;

        for ($i = $open, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];

            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The value of a literal expression, or null when it is not a plain literal.
     *
     * @return array{0: bool, 1: mixed} [ok, value]; class names come back as ClassName objects
     */
    public static function literal(string $code): array
    {
        $tokens = array_values(array_filter(self::tokens($code), static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $pos = 0;

        try {
            $value = self::parse($tokens, $pos);
        } catch (\UnexpectedValueException) {
            return [false, null];
        }

        return $pos === count($tokens) ? [true, $value] : [false, null];
    }

    /**
     * The string value of a quoted literal, or null.
     */
    public static function string(string $code): ?string
    {
        [$ok, $value] = self::literal($code);

        return $ok && is_string($value) ? $value : null;
    }

    /**
     * Text that is safe inside a generated "//" or "/* *\/" comment: one line, no "?>"
     * (which ends a "//" comment and PHP mode) and no "*\/". Use it for every comment
     * that contains text from the source project (paths, values, code).
     */
    public static function comment(string $text): string
    {
        return str_replace(['?>', '*/'], ['? >', '* /'], (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }

    /**
     * A class name to emit as code, or null when $name is not one: anything else from
     * the source project must be emitted as a string literal (export()).
     */
    public static function className(string $name): ?string
    {
        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $name) === 1 ? $name : null;
    }

    /**
     * Splits PHP source into lines, but never inside a string literal or heredoc: a
     * converter that comments out a "line" must comment out the whole literal, or the
     * literal's later lines would become code.
     *
     * @return list<string>
     */
    public static function lines(string $code): array
    {
        $lines = [''];
        $inString = false;

        foreach (token_get_all($code) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '"' || is_array($token) && in_array($token[0], [T_START_HEREDOC, T_END_HEREDOC], true)) {
                $inString = $text === '"' ? !$inString : $token[0] === T_START_HEREDOC;
            }

            if ($inString || is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lines[count($lines) - 1] .= $text;

                continue;
            }

            $parts = preg_split('/\R/', $text) ?: [$text];
            $lines[count($lines) - 1] .= array_shift($parts);

            foreach ($parts as $part) {
                $lines[] = $part;
            }
        }

        return $lines;
    }

    public static function export(mixed $value): string
    {
        return match (true) {
            $value instanceof ClassName => $value->name . '::class',
            is_string($value) => var_export($value, true),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => var_export($value, true),
            is_array($value) => self::exportArray($value),
            default => throw new \InvalidArgumentException('Cannot export ' . get_debug_type($value)),
        };
    }

    /**
     * @param array<mixed> $value
     */
    private static function exportArray(array $value): string
    {
        $items = [];

        foreach ($value as $key => $item) {
            $items[] = array_is_list($value) ? self::export($item) : self::export($key) . ' => ' . self::export($item);
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function tokens(string $code): array
    {
        $tokens = token_get_all('<?php ' . $code);
        array_shift($tokens);

        return $tokens;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $t
     */
    private static function parse(array $t, int &$pos): mixed
    {
        $token = $t[$pos] ?? throw new \UnexpectedValueException();
        $text = is_array($token) ? $token[1] : $token;
        $id = is_array($token) ? $token[0] : 0;

        if ($text === '-' && is_array($t[$pos + 1] ?? null) && in_array($t[$pos + 1][0], [T_LNUMBER, T_DNUMBER], true)) {
            $pos++;
            $number = self::parse($t, $pos);

            return is_int($number) || is_float($number) ? -$number : throw new \UnexpectedValueException();
        }

        $pos++;

        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            return self::unquote($text);
        }

        if ($id === T_LNUMBER) {
            return (int) $text;
        }

        if ($id === T_DNUMBER) {
            return (float) $text;
        }

        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            if (($t[$pos] ?? null) !== null && is_array($t[$pos]) && $t[$pos][0] === T_DOUBLE_COLON && is_array($t[$pos + 1] ?? null) && strtolower($t[$pos + 1][1]) === 'class') {
                $pos += 2;

                return new ClassName($text);
            }

            return match (strtolower($text)) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => throw new \UnexpectedValueException(),
            };
        }

        if ($text === '[' || $id === T_ARRAY && ($t[$pos] ?? null) === '(') {
            $close = $text === '[' ? ']' : ')';

            if ($text !== '[') {
                $pos++;
            }

            $items = [];

            while (($t[$pos] ?? null) !== $close) {
                $first = self::parse($t, $pos);

                if (is_array($t[$pos] ?? null) && $t[$pos][0] === T_DOUBLE_ARROW) {
                    $pos++;

                    if (!is_string($first) && !is_int($first)) {
                        throw new \UnexpectedValueException();
                    }

                    $items[$first] = self::parse($t, $pos);
                } else {
                    $items[] = $first;
                }

                if (($t[$pos] ?? null) === ',') {
                    $pos++;
                } elseif (($t[$pos] ?? null) !== $close) {
                    throw new \UnexpectedValueException();
                }
            }

            $pos++;

            return $items;
        }

        throw new \UnexpectedValueException();
    }

    private static function unquote(string $text): string
    {
        $body = substr($text, 1, -1);

        if ($text[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        if (preg_match('/(?<!\\\\)\$/', $body) === 1) {
            throw new \UnexpectedValueException(); // interpolated string
        }

        return stripcslashes($body);
    }
}
