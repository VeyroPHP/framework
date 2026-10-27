<?php

declare(strict_types=1);

namespace VeyroPHP\Scheduling;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use VeyroPHP\Console\Output;

/**
 * One scheduled task and when it runs. Created by Schedule; the frequency
 * methods change the cron expression (default: every minute).
 *
 *     $schedule->command('cache:prune')->everyThirtyMinutes();
 *     $schedule->job(new SendDigest())->dailyAt('07:30')->timezone('Asia/Kolkata');
 *     $schedule->call(fn () => ...)->weekdays()->hourly()->withoutOverlapping();
 */
final class Event
{
    /** @var array{string, string, string, string, string} */
    private array $fields = ['*', '*', '*', '*', '*'];

    private ?DateTimeZone $timezone = null;

    /** @var list<string> */
    private array $environments = [];

    /** @var list<Closure(): bool> */
    private array $filters = [];

    /** @var list<Closure(): bool> */
    private array $rejects = [];

    private ?int $overlapMinutes = null;

    /**
     * @param Closure(Output): void $task
     */
    public function __construct(
        private readonly Closure $task,
        private string $description,
    ) {}

    // Frequencies

    public function cron(string $expression): self
    {
        $this->fields = (new CronExpression($expression))->fields();

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->set(0, '*')->set(1, '*');
    }

    public function everyTwoMinutes(): self
    {
        return $this->set(0, '*/2')->set(1, '*');
    }

    public function everyFiveMinutes(): self
    {
        return $this->set(0, '*/5')->set(1, '*');
    }

    public function everyTenMinutes(): self
    {
        return $this->set(0, '*/10')->set(1, '*');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->set(0, '*/15')->set(1, '*');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->set(0, '0,30')->set(1, '*');
    }

    public function hourly(): self
    {
        return $this->hourlyAt(0);
    }

    public function hourlyAt(int $minute): self
    {
        return $this->set(0, (string) self::range($minute, 0, 59, 'minute'))->set(1, '*');
    }

    public function everyTwoHours(): self
    {
        return $this->set(0, '0')->set(1, '*/2');
    }

    public function daily(): self
    {
        return $this->dailyAt('00:00');
    }

    /**
     * @param string $time "HH:MM" (24 hours)
     */
    public function dailyAt(string $time): self
    {
        return $this->at($time);
    }

    public function twiceDaily(int $first = 1, int $second = 13, int $minute = 0): self
    {
        return $this->set(0, (string) self::range($minute, 0, 59, 'minute'))
            ->set(1, self::range($first, 0, 23, 'hour') . ',' . self::range($second, 0, 23, 'hour'));
    }

    /**
     * Sundays at 00:00.
     */
    public function weekly(): self
    {
        return $this->weeklyOn(0);
    }

    /**
     * @param int $day 0 (Sunday) to 6 (Saturday)
     */
    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        return $this->at($time)->set(2, '*')->set(4, (string) self::range($day, 0, 6, 'weekday'));
    }

    public function monthly(): self
    {
        return $this->monthlyOn(1);
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        return $this->at($time)->set(2, (string) self::range($day, 1, 31, 'day'));
    }

    public function quarterly(): self
    {
        return $this->at('00:00')->set(2, '1')->set(3, '1,4,7,10');
    }

    public function yearly(): self
    {
        return $this->at('00:00')->set(2, '1')->set(3, '1');
    }

    /**
     * Sets the hour and minute of the current frequency ("HH:MM", 24 hours).
     */
    public function at(string $time): self
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/D', $time, $m) !== 1) {
            throw new ScheduleException(sprintf('Invalid time "%s": use "HH:MM", for example "07:30".', $time));
        }

        return $this->set(0, (string) self::range((int) $m[2], 0, 59, 'minute'))->set(1, (string) self::range((int) $m[1], 0, 23, 'hour'));
    }

    public function weekdays(): self
    {
        return $this->set(4, '1-5');
    }

    public function weekends(): self
    {
        return $this->set(4, '0,6');
    }

    public function mondays(): self
    {
        return $this->set(4, '1');
    }

    public function sundays(): self
    {
        return $this->set(4, '0');
    }

    /**
     * @param int ...$days 0 (Sunday) to 6 (Saturday)
     */
    public function days(int ...$days): self
    {
        return $this->set(4, implode(',', array_map(static fn(int $d): int => self::range($d, 0, 6, 'weekday'), $days)));
    }

    /**
     * Evaluates the frequency in this time zone (default: the server's).
     */
    public function timezone(string $timezone): self
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new ScheduleException(sprintf('Unknown time zone "%s".', $timezone), 0, $e);
        }

        return $this;
    }

    // Conditions

    /**
     * Runs only when app.env is one of these.
     */
    public function environments(string ...$environments): self
    {
        $this->environments = array_values($environments);

        return $this;
    }

    /**
     * @param Closure(): bool $condition runs only when it returns true
     */
    public function when(Closure $condition): self
    {
        $this->filters[] = $condition;

        return $this;
    }

    /**
     * @param Closure(): bool $condition skipped when it returns true
     */
    public function skip(Closure $condition): self
    {
        $this->rejects[] = $condition;

        return $this;
    }

    /**
     * Skips a run while the previous run is still going. The lock expires after
     * $minutes, in case a run died without releasing it. Needs a cache store
     * shared by all servers (file on one server, Redis on several).
     */
    public function withoutOverlapping(int $minutes = 1440): self
    {
        $this->overlapMinutes = max(1, $minutes);

        return $this;
    }

    public function name(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    // Inspection and running

    public function description(): string
    {
        return $this->description;
    }

    public function expression(): string
    {
        return implode(' ', $this->fields);
    }

    public function timezoneName(): ?string
    {
        return $this->timezone?->getName();
    }

    public function overlapMinutes(): ?int
    {
        return $this->overlapMinutes;
    }

    /**
     * A stable id for locks: the same task gets the same id on every run and server.
     */
    public function id(): string
    {
        return 'schedule-' . sha1($this->expression() . '|' . $this->description);
    }

    public function isDue(DateTimeImmutable $now, string $environment): bool
    {
        if ($this->environments !== [] && !in_array($environment, $this->environments, true)) {
            return false;
        }

        if (!(new CronExpression($this->expression()))->isDue($this->timezone === null ? $now : $now->setTimezone($this->timezone))) {
            return false;
        }

        foreach ($this->filters as $filter) {
            if (!$filter()) {
                return false;
            }
        }

        foreach ($this->rejects as $reject) {
            if ($reject()) {
                return false;
            }
        }

        return true;
    }

    public function nextRunDate(DateTimeImmutable $now): DateTimeImmutable
    {
        return (new CronExpression($this->expression()))->nextRunDate($this->timezone === null ? $now : $now->setTimezone($this->timezone));
    }

    public function run(Output $output): void
    {
        ($this->task)($output);
    }

    private function set(int $position, string $value): self
    {
        $fields = $this->fields;
        $fields[$position] = $value;
        // Validates at once, so a wrong schedule fails when it is defined.
        $this->fields = (new CronExpression(implode(' ', $fields)))->fields();

        return $this;
    }

    private static function range(int $value, int $min, int $max, string $label): int
    {
        if ($value < $min || $value > $max) {
            throw new ScheduleException(sprintf('Invalid %s %d: use %d to %d.', $label, $value, $min, $max));
        }

        return $value;
    }
}
