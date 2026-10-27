<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * "Did you mean ...?" for mistyped command and option names.
 *
 * @internal
 */
final class Suggest
{
    /**
     * @param list<string> $candidates
     */
    public static function closest(string $input, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            // Prefixes count as close: "mig" suggests "migrate".
            $distance = str_starts_with($candidate, $input) ? 0 : levenshtein($input, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(strlen($input), 3)) ? $best : null;
    }
}
