<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

use Generator;

/**
 * An AI service such as Anthropic's Claude. Implement this to add another one.
 */
interface Provider
{
    /**
     * A short name for logs, e.g. "anthropic".
     */
    public function name(): string;

    /**
     * @throws AiException
     */
    public function chat(Request $request): Response;

    /**
     * Yields the answer's text piece by piece and returns the final Response.
     *
     * @return Generator<int, string, mixed, Response>
     *
     * @throws AiException
     */
    public function stream(Request $request): Generator;
}
