<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

use Closure;
use Generator;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Ai\Testing\FakeProvider;

/**
 * Entry point for AI features.
 *
 *     $answer = $ai->text('Summarize: ' . $article);
 *
 *     $reply = $ai->chat([Message::user('Hi'), Message::assistant('Hello!'), Message::user('Tell me a joke')],
 *         new Options(system: 'You are a friendly assistant.'));
 *
 *     $data = $ai->json('Extract name and email: ' . $text, [
 *         'type' => 'object',
 *         'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']],
 *         'required' => ['name', 'email'],
 *         'additionalProperties' => false,
 *     ]);
 *
 *     foreach ($ai->stream('Write a poem') as $chunk) { echo $chunk; }
 *
 * Every call is logged with provider, model, token counts, duration and stop
 * reason; prompts and answers are never logged.
 */
final class Ai
{
    /** @var array<string, Provider> */
    private array $resolved = [];

    /**
     * @param Closure(string): Provider $resolve creates a provider by name (called once per name)
     */
    public function __construct(
        private readonly Closure $resolve,
        private readonly string $default,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * An Ai that answers from a fake provider, for tests.
     */
    public static function fake(FakeProvider $provider = new FakeProvider()): self
    {
        return new self(static fn(): Provider => $provider, 'fake');
    }

    /**
     * The same service using another configured provider.
     */
    public function using(string $provider): self
    {
        $copy = new self($this->resolve, $provider, $this->logger);
        $copy->resolved = &$this->resolved;

        return $copy;
    }

    public function provider(): Provider
    {
        return $this->resolved[$this->default] ??= ($this->resolve)($this->default);
    }

    /**
     * Sends one prompt and returns the answer text.
     *
     * @throws AiException also when the model refuses (isRefusal())
     */
    public function text(string $prompt, ?Options $options = null): string
    {
        return $this->accept($this->chat([Message::user($prompt)], $options))->text;
    }

    /**
     * Sends a conversation and returns the full response (text, tokens, stop reason).
     * A refusal is returned, not thrown: check $response->refused().
     *
     * @param list<Message> $messages
     *
     * @throws AiException
     */
    public function chat(array $messages, ?Options $options = null): Response
    {
        return $this->send(new Request(self::checked($messages), $options ?? new Options()));
    }

    /**
     * Asks for an answer that follows a JSON schema and returns it decoded.
     *
     * @param string|list<Message> $prompt
     * @param array<string, mixed> $schema JSON schema; objects should set "additionalProperties": false
     *
     * @return array<mixed>
     *
     * @throws AiException when the call fails, is refused, or the answer is not valid JSON
     */
    public function json(string|array $prompt, array $schema, ?Options $options = null): array
    {
        if (($schema['type'] ?? null) !== 'object' && ($schema['type'] ?? null) !== 'array') {
            throw new AiException('The JSON schema must describe an object or an array (set "type").');
        }

        $messages = is_string($prompt) ? [Message::user($prompt)] : self::checked($prompt);
        $response = $this->accept($this->send(new Request($messages, $options ?? new Options(), $schema)));

        try {
            $data = json_decode($response->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AiException('The model did not return valid JSON' . ($response->truncated() ? ' (the answer hit the token limit).' : '.'), previous: $e);
        }

        return is_array($data) ? $data : throw new AiException('The model returned JSON that is not an object or array.');
    }

    /**
     * Streams the answer as it is generated. The generator's return value is the
     * final Response.
     *
     * @param string|list<Message> $prompt
     *
     * @return Generator<int, string, mixed, Response>
     *
     * @throws AiException
     */
    public function stream(string|array $prompt, ?Options $options = null): Generator
    {
        $messages = is_string($prompt) ? [Message::user($prompt)] : self::checked($prompt);
        $provider = $this->provider();
        $start = hrtime(true);

        $response = yield from $provider->stream(new Request($messages, $options ?? new Options()));
        $this->log($provider, $response, $start, true);

        return $response;
    }

    private function send(Request $request): Response
    {
        $provider = $this->provider();
        $start = hrtime(true);

        try {
            $response = $provider->chat($request);
        } catch (AiException $e) {
            $this->logger->warning('AI request to {provider} failed: {error}', [
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
                'duration_ms' => self::since($start),
            ]);

            throw $e;
        }

        $this->log($provider, $response, $start, false);

        return $response;
    }

    private function log(Provider $provider, Response $response, int|float $start, bool $streamed): void
    {
        // Not "input_tokens": the log redactor hides every key containing "token".
        $this->logger->info('AI request to {provider} ({model}): {usage_in} tokens in, {usage_out} out, {duration_ms} ms, {stop_reason}', [
            'provider' => $provider->name(),
            'model' => $response->model,
            'usage_in' => $response->inputTokens,
            'usage_out' => $response->outputTokens,
            'duration_ms' => self::since($start),
            'stop_reason' => $response->stopReason ?? 'unknown',
            'streamed' => $streamed,
        ]);
    }

    private function accept(Response $response): Response
    {
        return $response->refused() ? throw AiException::refused($response->model) : $response;
    }

    /**
     * @param list<mixed> $messages
     *
     * @return non-empty-list<Message>
     */
    private static function checked(array $messages): array
    {
        $checked = [];
        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                throw new AiException('Messages must be VeyroPHP\Ai\Message objects (Message::user() / Message::assistant()).');
            }
            $checked[] = $message;
        }

        if ($checked === [] || $checked[count($checked) - 1]->role !== 'user') {
            throw new AiException('A conversation must end with a user message.');
        }

        return $checked;
    }

    private static function since(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
