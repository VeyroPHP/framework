<?php

declare(strict_types=1);

namespace VeyroPHP\Ai\Tests;

use Anthropic\Client;
use Nyholm\Psr7\Response as HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use VeyroPHP\Ai\Ai;
use VeyroPHP\Ai\AiException;
use VeyroPHP\Ai\Message;
use VeyroPHP\Ai\Options;
use VeyroPHP\Ai\Provider;
use VeyroPHP\Ai\Providers\AnthropicProvider;

/**
 * Runs the real Anthropic SDK against a fake HTTP transport: no network, no API
 * key, no cost. Streaming cases replay the SDK's own captured fixtures.
 */
#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTest extends TestCase
{
    /** @var \ArrayObject<int, RequestInterface> */
    private \ArrayObject $sent;

    /** @var \ArrayObject<int, ResponseInterface|\Throwable> */
    private \ArrayObject $replies;

    protected function setUp(): void
    {
        $this->sent = new \ArrayObject();
        $this->replies = new \ArrayObject();
    }

    private function provider(bool $fallback = true, ?string $effort = null): AnthropicProvider
    {
        $transport = new class ($this->sent, $this->replies) implements ClientInterface {
            /**
             * @param \ArrayObject<int, RequestInterface> $sent
             * @param \ArrayObject<int, ResponseInterface|\Throwable> $replies
             */
            public function __construct(private readonly \ArrayObject $sent, private readonly \ArrayObject $replies) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent->append($request);
                $reply = $this->replies[count($this->sent) - 1] ?? throw new RuntimeException('No reply queued.');

                return $reply instanceof \Throwable ? throw $reply : $reply;
            }
        };

        $client = new Client(apiKey: 'sk-test-key', requestOptions: [
            'transporter' => $transport,
            'streamingTransporter' => $transport,
            'maxRetries' => 0,
        ]);

        return new AnthropicProvider($client, effort: $effort, refusalFallback: $fallback);
    }

    private function reply(int $status, string $body, string $type = 'application/json'): void
    {
        $this->replies->append(new HttpResponse($status, ['content-type' => $type], $body));
    }

    /**
     * Queues one of the SDK's captured streaming responses (status line, headers, blank line, body).
     */
    private function replyFixture(string $name): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/vendor/anthropic-ai/sdk/fixtures/ga/' . $name . '.txt');
        [$head, $body] = explode("\n\n", str_replace("\r\n", "\n", $raw), 2);
        $lines = explode("\n", $head);
        $status = (int) explode(' ', (string) array_shift($lines))[1];
        $headers = [];
        foreach ($lines as $line) {
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $headers[$key] = $value;
        }
        $this->replies->append(new HttpResponse($status, $headers, $body));
    }

    private function sentRequest(int $index): RequestInterface
    {
        $request = $this->sent->getArrayCopy()[$index] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastBody(): array
    {
        $requests = $this->sent->getArrayCopy();
        $last = end($requests);
        self::assertInstanceOf(RequestInterface::class, $last);
        $body = json_decode((string) $last->getBody(), true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    private static function message(string $text, string $stopReason = 'end_turn'): string
    {
        return (string) json_encode([
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 21, 'output_tokens' => 7],
        ]);
    }

    public function testBuildsTheMessagesRequest(): void
    {
        $this->reply(200, self::message('Paris'));

        $response = $this->provider(effort: 'high')->chat(new \VeyroPHP\Ai\Request(
            [Message::user('Hi'), Message::assistant('Hello'), Message::user('Capital of France?')],
            new Options(system: 'Be brief.'),
        ));

        $request = $this->sentRequest(0);
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/v1/messages', $request->getUri()->getPath());
        // How the key is sent is the SDK's decision (v1.3 uses a Bearer header); it must just arrive.
        self::assertSame('Bearer sk-test-key', $request->getHeaderLine('Authorization'));
        self::assertNotSame('', $request->getHeaderLine('anthropic-version'));
        self::assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));

        $body = $this->lastBody();
        self::assertSame('claude-opus-5-5', $body['model']);
        self::assertSame(16000, $body['max_tokens']);
        self::assertSame('Be brief.', $body['system']);
        self::assertSame('default', $body['fallbacks']);
        self::assertSame(['effort' => 'high'], $body['output_config']);
        self::assertSame([['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello'], ['role' => 'user', 'content' => 'Capital of France?']], $body['messages']);
        self::assertArrayNotHasKey('thinking', $body, 'thinking is left at the model default (adaptive)');

        self::assertSame('Paris', $response->text);
        self::assertSame(['claude-opus-5-5', 'end_turn', 21, 7], [$response->model, $response->stopReason, $response->inputTokens, $response->outputTokens]);
    }

    public function testStructuredOutputSendsTheJsonSchema(): void
    {
        $this->reply(200, self::message('{"name":"Asha","plan":"pro"}'));
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'plan' => ['type' => 'string']], 'required' => ['name', 'plan'], 'additionalProperties' => false];
        $ai = new Ai(fn(): Provider => $this->provider(), 'anthropic');

        self::assertSame(['name' => 'Asha', 'plan' => 'pro'], $ai->json('Extract: Asha, pro plan', $schema, new Options(model: 'claude-sonnet-5-5', maxTokens: 500)));

        $body = $this->lastBody();
        self::assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        self::assertSame('claude-sonnet-5-5', $body['model']);
        self::assertSame(500, $body['max_tokens']);
    }

    public function testFallbackCanBeTurnedOff(): void
    {
        $this->reply(200, self::message('ok'));
        $this->provider(fallback: false)->chat(new \VeyroPHP\Ai\Request([Message::user('x')]));

        self::assertArrayNotHasKey('fallbacks', $this->lastBody());
        self::assertStringNotContainsString('server-side-fallback', $this->sentRequest(0)->getHeaderLine('anthropic-beta'));
    }

    public function testStreamsTextFromTheSdkFixture(): void
    {
        $this->replyFixture('basic_text');
        $stream = $this->provider()->stream(new \VeyroPHP\Ai\Request([Message::user('Say hello')]));

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }
        $response = $stream->getReturn();

        self::assertSame(['Hello', ' there', '!'], $chunks);
        self::assertSame('Hello there!', $response->text);
        self::assertSame('end_turn', $response->stopReason);
        self::assertSame([11, 6], [$response->inputTokens, $response->outputTokens]);
        self::assertTrue($this->lastBody()['stream']);
        self::assertSame(64000, $this->lastBody()['max_tokens']);
    }

    public function testRefusalIsReported(): void
    {
        $this->replyFixture('refusal');
        $stream = $this->provider()->stream(new \VeyroPHP\Ai\Request([Message::user('x')]));
        iterator_to_array($stream);

        self::assertTrue($stream->getReturn()->refused());

        $this->reply(200, self::message('', 'refusal'));
        $ai = new Ai(fn(): Provider => $this->provider(), 'anthropic');
        try {
            $ai->text('x');
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertTrue($e->isRefusal());
        }
    }

    public function testHttpErrorsBecomeAiExceptions(): void
    {
        $error = static fn(string $type, string $message): string => (string) json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
        $cases = [
            [429, 'rate_limit_error', true, 'rate limited'],
            [529, 'overloaded_error', true, 'overloaded'],
            [500, 'api_error', true, 'server error'],
            [401, 'authentication_error', false, 'ANTHROPIC_API_KEY'],
            [400, 'invalid_request_error', false, 'rejected'],
        ];

        foreach ($cases as [$status, $type, $retryable, $text]) {
            $this->reply($status, $error($type, 'details'));
            try {
                $this->provider()->chat(new \VeyroPHP\Ai\Request([Message::user('x')]));
                self::fail("Expected AiException for $status");
            } catch (AiException $e) {
                self::assertSame($retryable, $e->isRetryable(), (string) $status);
                self::assertStringContainsString($text, $e->getMessage());
                self::assertStringNotContainsString('sk-test-key', $e->getMessage());
            }
        }
    }

    public function testNetworkErrorsAreRetryable(): void
    {
        $this->replies->append(new class ('connection refused') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new \Nyholm\Psr7\Request('POST', 'https://api.anthropic.com/v1/messages');
            }
        });

        try {
            $this->provider()->chat(new \VeyroPHP\Ai\Request([Message::user('x')]));
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertTrue($e->isRetryable());
            self::assertStringContainsString('Could not reach the Anthropic API', $e->getMessage());
        }
    }

    public function testUnknownEffortIsRejectedBeforeAnyRequest(): void
    {
        try {
            $this->provider(effort: 'extreme')->chat(new \VeyroPHP\Ai\Request([Message::user('x')]));
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertStringContainsString('Unknown effort', $e->getMessage());
        }

        self::assertCount(0, $this->sent);
    }
}
