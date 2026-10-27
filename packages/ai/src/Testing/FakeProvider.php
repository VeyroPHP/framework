<?php

declare(strict_types=1);

namespace VeyroPHP\Ai\Testing;

use Closure;
use Generator;
use Throwable;
use VeyroPHP\Ai\AiException;
use VeyroPHP\Ai\Provider;
use VeyroPHP\Ai\Request;
use VeyroPHP\Ai\Response;

/**
 * A provider for tests: answers from a queue and records every request, so
 * AI features can be tested without network calls or API costs.
 *
 *     $fake = new FakeProvider(['First answer', '{"name":"Asha"}']);
 *     $container->instance(Ai::class, Ai::fake($fake));
 *     // ... run the code under test ...
 *     $fake->requests()[0]->messages[0]->content;   // what was sent
 *
 * Queue entries: a string (the answer text), a Response, a Throwable (thrown),
 * or a Closure(Request): Response|string. When the queue is empty, every call
 * answers with $fallback.
 */
final class FakeProvider implements Provider
{
    /** @var list<string|Response|Throwable|Closure(Request): (Response|string)> */
    private array $queue;

    /** @var list<Request> */
    private array $requests = [];

    /**
     * @param list<string|Response|Throwable|Closure(Request): (Response|string)> $responses
     */
    public function __construct(array $responses = [], private readonly string $fallback = 'Fake answer.')
    {
        $this->queue = $responses;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function chat(Request $request): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? $this->fallback;

        if ($next instanceof Throwable) {
            throw $next instanceof AiException ? $next : new AiException($next->getMessage(), previous: $next);
        }

        if ($next instanceof Closure) {
            $next = $next($request);
        }

        return $next instanceof Response ? $next : new Response($next, $request->options->model ?? 'fake-model', 'end_turn', 10, max(1, str_word_count($next)));
    }

    public function stream(Request $request): Generator
    {
        $response = $this->chat($request);

        foreach (preg_split('/(?<=\s)/', $response->text) ?: [] as $chunk) {
            if ($chunk !== '') {
                yield $chunk;
            }
        }

        return $response;
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastPrompt(): ?string
    {
        $last = $this->requests[count($this->requests) - 1] ?? null;

        return $last === null ? null : $last->messages[count($last->messages) - 1]->content;
    }
}
