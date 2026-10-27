<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

/**
 * English word forms Laravel uses to infer table, key and parameter names.
 * Covers regular words only; irregular ones are flagged by the converters'
 * "inferred" TODO notes.
 */
final class Words
{
    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace(['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/', '/[-\s]+/'], ['$1_$2', '$1_$2', '_'], $value));
    }

    public static function plural(string $word): string
    {
        return match (true) {
            (bool) preg_match('/[^aeiou]y$/D', $word) => substr($word, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/D', $word) => $word . 'es',
            default => $word . 's',
        };
    }

    public static function singular(string $word): string
    {
        return match (true) {
            str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(ss|x|z|ch|sh)es$/D', $word) => substr($word, 0, -2),
            str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }
}
