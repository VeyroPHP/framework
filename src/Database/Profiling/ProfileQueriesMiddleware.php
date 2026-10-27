<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Profiling;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Profiles the queries of each request.
 *
 * - Resets the profiler when the request starts.
 * - After the request (also when it throws), logs a warning when the same SQL
 *   ran at least $repeatThreshold times: a likely N+1 problem.
 * - With $exposeHeaders, adds X-Query-Count and X-Query-Time-Ms to successful
 *   responses. These reveal internals; enable them only in development.
 *
 * Register it as global middleware, outermost, so it sees every query.
 */
final class ProfileQueriesMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly QueryProfiler $profiler,
        private readonly LoggerInterface $logger,
        private readonly int $repeatThreshold = 5,
        private readonly bool $exposeHeaders = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->profiler->reset();

        try {
            $response = $handler->handle($request);
        } finally {
            $this->reportRepeatedQueries($request);
        }

        if (!$this->exposeHeaders) {
            return $response;
        }

        return $response
            ->withHeader('X-Query-Count', (string) $this->profiler->count())
            ->withHeader('X-Query-Time-Ms', sprintf('%.2f', $this->profiler->totalTimeMs()));
    }

    private function reportRepeatedQueries(ServerRequestInterface $request): void
    {
        $repeated = $this->profiler->repeatedQueries($this->repeatThreshold);

        if ($repeated === []) {
            return;
        }

        $this->logger->warning('Possible N+1 queries on {method} {path}: {statements} statement(s) repeated, {total} queries in total', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'statements' => count($repeated),
            'total' => $this->profiler->count(),
            'repeated' => $repeated,
        ]);
    }
}
