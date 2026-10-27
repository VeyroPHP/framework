<?php

declare(strict_types=1);

namespace VeyroPHP\Ai\Providers;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Generator;
use VeyroPHP\Ai\AiException;
use VeyroPHP\Ai\Provider;
use VeyroPHP\Ai\Request;
use VeyroPHP\Ai\Response;

/**
 * Claude through Anthropic's official PHP SDK (composer package anthropic-ai/sdk,
 * plus a PSR-18 HTTP client such as guzzlehttp/guzzle).
 *
 * - Default model claude-opus-5-5. Thinking is left at the model's default
 *   (adaptive); "effort" controls how much it thinks.
 * - Server-side refusal fallback is on by default: when the model declines for
 *   policy reasons, the API retries on its configured fallback model.
 * - Structured output uses output_config.format with a JSON schema.
 * - The SDK retries rate limits, overload and server errors itself (with
 *   back-off, honoring retry-after) before an error reaches this class.
 */
final class AnthropicProvider implements Provider
{
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param string|null $effort default effort (low, medium, high, xhigh, max); null leaves the model's default
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly int $maxTokens = 16000,
        private readonly int $streamMaxTokens = 64000,
        private readonly ?string $effort = null,
        private readonly bool $refusalFallback = true,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function chat(Request $request): Response
    {
        $p = $this->params($request, $this->maxTokens);

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $p['maxTokens'],
                messages: $p['messages'],
                model: $p['model'],
                fallbacks: $p['fallbacks'],
                outputConfig: $p['outputConfig'],
                system: $p['system'],
                betas: $p['betas'],
            );
        } catch (APIException $e) {
            throw self::translate($e);
        }

        return self::toResponse($message);
    }

    public function stream(Request $request): Generator
    {
        $model = $request->options->model ?? $this->model;
        $stopReason = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $text = '';

        $p = $this->params($request, $this->streamMaxTokens);

        try {
            $events = $this->client->beta->messages->createStream(
                maxTokens: $p['maxTokens'],
                messages: $p['messages'],
                model: $p['model'],
                fallbacks: $p['fallbacks'],
                outputConfig: $p['outputConfig'],
                system: $p['system'],
                betas: $p['betas'],
            );

            foreach ($events as $event) {
                if ($event instanceof BetaRawMessageStartEvent) {
                    $model = $event->message->model;
                    $inputTokens = $event->message->usage->inputTokens;
                } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                    $text .= $event->delta->text;
                    yield $event->delta->text;
                } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                    $stopReason = $event->delta->stopReason ?? $stopReason;
                    $outputTokens = $event->usage->outputTokens;
                }
            }
        } catch (APIException $e) {
            throw self::translate($e);
        }

        return new Response($text, $model, is_string($stopReason) ? $stopReason : null, $inputTokens, $outputTokens);
    }

    /**
     * @return array{model: string, maxTokens: int, messages: list<array{role: 'user'|'assistant', content: string}>, system: string|null, outputConfig: array{effort?: 'low'|'medium'|'high'|'xhigh'|'max', format?: array{type: 'json_schema', schema: array<string, mixed>}}|null, fallbacks: 'default'|null, betas: list<string>|null}
     */
    private function params(Request $request, int $defaultMaxTokens): array
    {
        $options = $request->options;
        $effort = self::effort($options->effort ?? $this->effort);

        $outputConfig = [];
        if ($effort !== null) {
            $outputConfig['effort'] = $effort;
        }
        if ($request->schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->schema];
        }

        return [
            'model' => $options->model ?? $this->model,
            'maxTokens' => $options->maxTokens ?? $defaultMaxTokens,
            'messages' => array_map(static fn($m): array => ['role' => $m->role, 'content' => $m->content], $request->messages),
            'system' => $options->system,
            'outputConfig' => $outputConfig === [] ? null : $outputConfig,
            'fallbacks' => $this->refusalFallback ? 'default' : null,
            'betas' => $this->refusalFallback ? [self::FALLBACK_BETA] : null,
        ];
    }

    /**
     * @return 'low'|'medium'|'high'|'xhigh'|'max'|null
     */
    private static function effort(?string $value): ?string
    {
        return match ($value) {
            null => null,
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
            'max' => 'max',
            default => throw new AiException(sprintf('Unknown effort "%s"; use low, medium, high, xhigh or max.', $value)),
        };
    }

    private static function toResponse(BetaMessage $message): Response
    {
        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text' && isset($block->text) && is_string($block->text)) {
                $text .= $block->text;
            }
        }

        return new Response($text, $message->model, $message->stopReason, $message->usage->inputTokens, $message->usage->outputTokens);
    }

    private static function translate(APIException $e): AiException
    {
        if ($e instanceof APIStatusException) {
            $status = $e->status ?? 0;
            $retryable = in_array($status, [408, 409, 429], true) || $status >= 500;
            $what = match (true) {
                $status === 401 => 'the API key was rejected (check ANTHROPIC_API_KEY)',
                $status === 403 => 'the API key may not use this model or feature',
                $status === 429 => 'rate limited; try again later',
                $status === 529 => 'Anthropic is overloaded; try again later',
                $status >= 500 => 'Anthropic had a server error; try again later',
                default => 'the request was rejected',
            };

            return new AiException(sprintf('Anthropic API error %d: %s.', $status, $what), $retryable, previous: $e);
        }

        if ($e instanceof APIConnectionException) {
            return new AiException('Could not reach the Anthropic API (network error or timeout).', true, previous: $e);
        }

        return new AiException('Anthropic API call failed.', previous: $e);
    }
}
