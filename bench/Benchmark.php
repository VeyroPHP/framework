<?php

declare(strict_types=1);

namespace VeyroPHP\Bench;

use Closure;

/**
 * Minimal micro-benchmark runner.
 *
 * Each case runs a warm-up, then several rounds. Every operation is timed
 * individually with hrtime(), so percentiles describe single operations.
 * Throughput is reported per round; the median round is the headline number
 * and the min/max show the spread between rounds.
 */
final class Benchmark
{
    /**
     * @param Closure(): mixed $operation
     *
     * @return array{name: string, iterations: int, rounds: int, ops_per_sec_median: float, ops_per_sec_min: float, ops_per_sec_max: float, p50_us: float, p95_us: float, p99_us: float, peak_memory_mb: float}
     */
    public static function run(string $name, Closure $operation, int $iterations, int $rounds = 5, int $warmup = 500): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            $operation();
        }

        $durations = [];
        $throughputs = [];

        for ($round = 0; $round < $rounds; $round++) {
            $roundStart = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                $operation();
                $durations[] = hrtime(true) - $start;
            }

            $roundSeconds = (hrtime(true) - $roundStart) / 1e9;
            $throughputs[] = $iterations / $roundSeconds;
        }

        sort($durations);
        sort($throughputs);

        return [
            'name' => $name,
            'iterations' => $iterations,
            'rounds' => $rounds,
            'ops_per_sec_median' => round($throughputs[intdiv(count($throughputs), 2)], 1),
            'ops_per_sec_min' => round($throughputs[0], 1),
            'ops_per_sec_max' => round($throughputs[count($throughputs) - 1], 1),
            'p50_us' => round(self::percentile($durations, 0.50) / 1000, 2),
            'p95_us' => round(self::percentile($durations, 0.95) / 1000, 2),
            'p99_us' => round(self::percentile($durations, 0.99) / 1000, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * @param list<int|float> $sorted
     */
    private static function percentile(array $sorted, float $fraction): float
    {
        $index = (int) ceil($fraction * count($sorted)) - 1;

        return (float) $sorted[max(0, min($index, count($sorted) - 1))];
    }
}
