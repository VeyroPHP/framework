<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * Counts calls in PHP tokens. Whitespace and comments are ignored, so
 * commented-out code is not counted.
 */
final class Tokens
{
    /**
     * Significant tokens: [id or 0 for single characters, text, line].
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{int, string, int}>
     */
    public static function significant(array $tokens): array
    {
        $out = [];
        $line = 1;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                    $out[] = [$token[0], $token[1], $token[2]];
                }
            } else {
                $out[] = [0, $token, $line];
            }
        }

        return $out;
    }

    /**
     * A method name: T_STRING, or a keyword PHP allows as a method name (match, list, ...).
     *
     * @param array{int, string, int} $token
     */
    private static function identifier(array $token): bool
    {
        return $token[0] !== 0 && preg_match('/^[A-Za-z_]\w*$/D', $token[1]) === 1;
    }

    /**
     * "Class::method(" calls for the given class short names, e.g. Route::get.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>|null $classes null for every class
     * @return array<string, int> "Class::method" => count
     */
    public static function staticCalls(array $tokens, ?array $classes = null): array
    {
        $t = self::significant($tokens);
        $counts = [];

        for ($i = 0, $n = count($t) - 3; $i < $n; $i++) {
            if (in_array($t[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) && $t[$i + 1][0] === T_DOUBLE_COLON && self::identifier($t[$i + 2]) && $t[$i + 3][1] === '(') {
                $class = ltrim(substr((string) strrchr('\\' . $t[$i][1], '\\'), 1), '\\');

                if ($classes === null || in_array($class, $classes, true)) {
                    $key = $class . '::' . $t[$i + 2][1];
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * Methods called on a variable, e.g. $table->string(...) for "$table".
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, int> method => count
     */
    public static function methodCallsOn(array $tokens, string $variable): array
    {
        $t = self::significant($tokens);
        $counts = [];

        for ($i = 0, $n = count($t) - 3; $i < $n; $i++) {
            if ($t[$i][0] === T_VARIABLE && $t[$i][1] === $variable && in_array($t[$i + 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && $t[$i + 2][0] === T_STRING && $t[$i + 3][1] === '(') {
                $counts[$t[$i + 2][1]] = ($counts[$t[$i + 2][1]] ?? 0) + 1;

                // Chained calls on the same expression: $table->string('a')->nullable()->unique()
                $depth = 0;

                for ($j = $i + 3; $j < count($t); $j++) {
                    $text = $t[$j][1];

                    if ($text === '(') {
                        $depth++;
                    } elseif ($text === ')') {
                        $depth--;

                        if ($depth === 0 && isset($t[$j + 3]) && $t[$j + 1][0] === T_OBJECT_OPERATOR && $t[$j + 2][0] === T_STRING && $t[$j + 3][1] === '(') {
                            $counts[$t[$j + 2][1]] = ($counts[$t[$j + 2][1]] ?? 0) + 1;
                            $j += 2;
                        } elseif ($depth === 0) {
                            break;
                        }
                    } elseif ($depth === 0 && $text === ';') {
                        break;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * Calls of global functions such as env() or view(): not methods, not
     * static calls, not declarations, not "new".
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, int>
     */
    public static function functionCalls(array $tokens): array
    {
        $t = self::significant($tokens);
        $counts = [];

        for ($i = 0, $n = count($t) - 1; $i < $n; $i++) {
            if (!in_array($t[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || $t[$i + 1][1] !== '(') {
                continue;
            }

            $before = $i > 0 ? $t[$i - 1] : [0, '', 0];

            if (in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                continue;
            }

            $name = strtolower(ltrim($t[$i][1], '\\'));
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }
}
