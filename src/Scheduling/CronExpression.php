<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A standard five-field cron expression: minute hour day-of-month month day-of-week.
 *
 *     new CronExpression('*\/15 9-17 * * mon-fri');
 *
 * - Each field: "*", a number, a range "1-5", a step "*\/10" or "1-30/5", or a
 *   comma-separated list of those. Months accept JAN-DEC, weekdays SUN-SAT
 *   (0 and 7 are both Sunday).
 * - Shortcuts: @yearly (@annually), @monthly, @weekly, @daily (@midnight), @hourly.
 * - When both day-of-month and day-of-week are restricted, a day matches if
 *   either matches (as in Vixie cron).
 */
final class CronExpression
{
    private const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    private const WEEKDAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    public readonly string $expression;

    /** @var array{string, string, string, string, string} */
    private readonly array $fields;

    /** @var array<int, true> */
    private readonly array $minutes;

    /** @var array<int, true> */
    private readonly array $hours;

    /** @var array<int, true> */
    private readonly array $days;

    /** @var array<int, true> */
    private readonly array $months;

    /** @var array<int, true> */
    private readonly array $weekdays;

    private readonly bool $daysRestricted;

    private readonly bool $weekdaysRestricted;

    public function __construct(string $expression)
    {
        $expression = trim($expression);
        $expanded = self::ALIASES[strtolower($expression)] ?? $expression;
        $fields = preg_split('/\s+/', $expanded) ?: [];

        if (count($fields) !== 5) {
            throw new ScheduleException(sprintf('Invalid cron expression "%s": it needs 5 fields (minute hour day month weekday).', $expression));
        }

        $this->expression = $expression;
        $this->fields = [$fields[0], $fields[1], $fields[2], $fields[3], $fields[4]];
        $this->minutes = self::field($fields[0], 0, 59, [], 'minute', $expression);
        $this->hours = self::field($fields[1], 0, 23, [], 'hour', $expression);
        $this->days = self::field($fields[2], 1, 31, [], 'day of month', $expression);
        $this->months = self::field($fields[3], 1, 12, self::MONTHS, 'month', $expression);

        $weekdays = self::field($fields[4], 0, 7, self::WEEKDAYS, 'day of week', $expression);
        if (isset($weekdays[7])) {
            unset($weekdays[7]);
            $weekdays[0] = true;
        }
        $this->weekdays = $weekdays;

        $this->daysRestricted = $fields[2] !== '*' && $fields[2] !== '?';
        $this->weekdaysRestricted = $fields[4] !== '*' && $fields[4] !== '?';
    }

    /**
     * The five fields, with shortcuts such as @daily expanded.
     *
     * @return array{string, string, string, string, string}
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Whether the expression matches the minute of $time (in $time's time zone).
     */
    public function isDue(DateTimeInterface $time): bool
    {
        return isset($this->minutes[(int) $time->format('i')], $this->hours[(int) $time->format('G')])
            && $this->dayMatches($time);
    }

    /**
     * The first matching minute strictly after $after, in $after's time zone.
     */
    public function nextRunDate(DateTimeInterface $after): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromInterface($after)->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');

        // Day by day, then hour by hour, then minute by minute; at most a few thousand steps.
        for ($day = 0; $day < 366 * 5; $day++) {
            if (isset($this->months[(int) $time->format('n')]) && $this->dayMatches($time)) {
                for ($hour = (int) $time->format('G'); $hour < 24; $hour++) {
                    if (!isset($this->hours[$hour])) {
                        continue;
                    }

                    $startMinute = $hour === (int) $time->format('G') ? (int) $time->format('i') : 0;

                    for ($minute = $startMinute; $minute < 60; $minute++) {
                        if (isset($this->minutes[$minute])) {
                            $candidate = $time->setTime($hour, $minute);

                            // Skip times that do not exist on DST change days.
                            if ((int) $candidate->format('G') === $hour && (int) $candidate->format('i') === $minute) {
                                return $candidate;
                            }
                        }
                    }
                }
            }

            $time = $time->modify('+1 day')->setTime(0, 0);
        }

        throw new ScheduleException(sprintf('The cron expression "%s" never matches (for example 31 February).', $this->expression));
    }

    private function dayMatches(DateTimeInterface $time): bool
    {
        if (!isset($this->months[(int) $time->format('n')])) {
            return false;
        }

        $day = isset($this->days[(int) $time->format('j')]);
        $weekday = isset($this->weekdays[(int) $time->format('w')]);

        if ($this->daysRestricted && $this->weekdaysRestricted) {
            return $day || $weekday;
        }

        return $day && $weekday;
    }

    /**
     * @param array<string, int> $names
     *
     * @return array<int, true>
     */
    private static function field(string $field, int $min, int $max, array $names, string $label, string $expression): array
    {
        $values = [];

        foreach (explode(',', strtolower($field)) as $part) {
            if (preg_match('#^(\*|\?|[a-z0-9]+(?:-[a-z0-9]+)?)(?:/(\d+))?$#D', $part, $m) !== 1) {
                throw self::invalid($expression, $label, $part);
            }

            $step = isset($m[2]) ? (int) $m[2] : 1;

            if ($step < 1) {
                throw self::invalid($expression, $label, $part);
            }

            if ($m[1] === '*' || $m[1] === '?') {
                [$from, $to] = [$min, $max];
            } else {
                $bounds = explode('-', $m[1]);
                $from = self::value($bounds[0], $names, $min, $max, $expression, $label);
                $to = isset($bounds[1]) ? self::value($bounds[1], $names, $min, $max, $expression, $label) : (isset($m[2]) ? $max : $from);

                if ($to < $from) {
                    throw self::invalid($expression, $label, $part);
                }
            }

            for ($v = $from; $v <= $to; $v += $step) {
                $values[$v] = true;
            }
        }

        return $values;
    }

    /**
     * @param array<string, int> $names
     */
    private static function value(string $text, array $names, int $min, int $max, string $expression, string $label): int
    {
        $value = ctype_digit($text) ? (int) $text : ($names[$text] ?? null);

        if ($value === null || $value < $min || $value > $max) {
            throw self::invalid($expression, $label, $text);
        }

        return $value;
    }

    private static function invalid(string $expression, string $label, string $part): ScheduleException
    {
        return new ScheduleException(sprintf('Invalid cron expression "%s": "%s" is not a valid %s.', $expression, $part, $label));
    }
}
