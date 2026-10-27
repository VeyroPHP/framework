<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Health;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Named checks that tell whether the application can serve requests.
 *
 *     $health->add('redis', fn () => $redis->ping());   // throw to fail
 *
 * - Every check runs, even after one fails, so the report is complete.
 * - A failure is logged with its message. The web endpoint (HealthEndpoint) shows
 *   only "ok"/"fail" per check, because error messages can contain host names or
 *   credentials; "php veyro health" shows the messages and times.
 * - Checks run in order and are not time-limited: keep them fast (one query, one
 *   cache write).
 */
final class HealthCheck
{
    /** @var array<string, Closure(): mixed> */
    private array $checks = [];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

    /**
     * @param Closure(): mixed $check throws to report a failure; the return value is ignored
     *
     * @throws \InvalidArgumentException for a duplicate or invalid name
     */
    public function add(string $name, Closure $check): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid health check name "%s": use lower-case letters, digits, ".", "_" and "-".', $name));
        }

        if (isset($this->checks[$name])) {
            throw new \InvalidArgumentException(sprintf('A health check named "%s" already exists.', $name));
        }

        $this->checks[$name] = $check;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->checks);
    }

    public function run(): HealthReport
    {
        $results = [];

        foreach ($this->checks as $name => $check) {
            $start = hrtime(true);
            $error = null;

            try {
                $check();
            } catch (Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
                $this->logger->error('Health check {check} failed: {error}', ['check' => $name, 'error' => $error, 'exception' => $e]);
            }

            $results[$name] = ['ok' => $error === null, 'ms' => round((hrtime(true) - $start) / 1e6, 2), 'error' => $error];
        }

        return new HealthReport($results);
    }
}
