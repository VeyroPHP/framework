<?php

declare(strict_types=1);

namespace VeyroPHP\Ai\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use VeyroPHP\Ai\Ai;
use VeyroPHP\Ai\AiException;
use VeyroPHP\Ai\Message;
use VeyroPHP\Ai\Options;
use VeyroPHP\Ai\Provider;
use VeyroPHP\Ai\Request;
use VeyroPHP\Ai\Response;
use VeyroPHP\Ai\Testing\FakeProvider;

#[CoversClass(Ai::class)]
#[CoversClass(FakeProvider::class)]
#[CoversClass(Options::class)]
#[CoversClass(Response::class)]
#[CoversClass(Message::class)]
#[CoversClass(Request::class)]
#[CoversClass(AiException::class)]
final class AiTest extends TestCase
{
    /** @var \ArrayObject<int, array{string, string, array<string, mixed>}> */
    private \ArrayObject $logs;

    protected function setUp(): void
    {
        $this->logs = new \ArrayObject();
    }

    private function ai(FakeProvider $fake): Ai
    {
        $logger = new class ($this->logs) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, array{string, string, array<string, mixed>}> $logs
             */
            public function __construct(private readonly \ArrayObject $logs) {}

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs->append([is_string($level) ? $level : '', (string) $message, $context]);
            }
        };

        return new Ai(static fn(): Provider => $fake, 'fake', $logger);
    }

    public function testTextSendsOnePromptAndReturnsTheAnswer(): void
    {
        $fake = new FakeProvider(['Paris']);

        self::assertSame('Paris', $this->ai($fake)->text('Capital of France?', new Options(system: 'Answer in one word.', effort: 'low')));

        $request = $fake->requests()[0];
        self::assertSame('Capital of France?', $fake->lastPrompt());
        self::assertSame('Answer in one word.', $request->options->system);
        self::assertSame('low', $request->options->effort);
        self::assertNull($request->schema);
    }

    public function testChatKeepsTheConversation(): void
    {
        $fake = new FakeProvider([new Response('Why did the chicken…', 'claude-opus-5-5', 'end_turn', 42, 9)]);

        $response = $this->ai($fake)->chat([Message::user('Hi'), Message::assistant('Hello!'), Message::user('Tell me a joke')]);

        self::assertSame('Why did the chicken…', $response->text);
        self::assertSame([42, 9], [$response->inputTokens, $response->outputTokens]);
        self::assertSame(['user', 'assistant', 'user'], array_map(static fn(Message $m): string => $m->role, $fake->requests()[0]->messages));
    }

    public function testJsonSendsTheSchemaAndDecodesTheAnswer(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'], 'additionalProperties' => false];
        $fake = new FakeProvider(['{"name":"Asha"}']);

        self::assertSame(['name' => 'Asha'], $this->ai($fake)->json('Extract the name: Asha', $schema));
        self::assertSame($schema, $fake->requests()[0]->schema);
    }

    public function testInvalidJsonAndBadSchemasAreReported(): void
    {
        $schema = ['type' => 'object'];

        try {
            $this->ai(new FakeProvider(['not json']))->json('x', $schema);
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertSame('The model did not return valid JSON.', $e->getMessage());
        }

        try {
            $this->ai(new FakeProvider([new Response('{"name":"As', 'm', 'max_tokens')]))->json('x', $schema);
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertStringContainsString('hit the token limit', $e->getMessage());
        }

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('must describe an object or an array');
        $this->ai(new FakeProvider())->json('x', ['properties' => []]);
    }

    public function testRefusalsThrowFromTextButAreReturnedByChat(): void
    {
        $refusal = new Response('', 'claude-opus-5-5', 'refusal');

        $response = $this->ai(new FakeProvider([$refusal]))->chat([Message::user('x')]);
        self::assertTrue($response->refused());

        try {
            $this->ai(new FakeProvider([$refusal]))->text('x');
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertTrue($e->isRefusal());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testStreamYieldsChunksAndReturnsTheResponse(): void
    {
        $stream = $this->ai(new FakeProvider(['Roses are red']))->stream('Write a poem');

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['Roses ', 'are ', 'red'], $chunks);
        self::assertSame('Roses are red', $stream->getReturn()->text);
    }

    public function testErrorsAreLoggedAndRethrown(): void
    {
        $fake = new FakeProvider([new AiException('Anthropic API error 429: rate limited.', true)]);

        try {
            $this->ai($fake)->text('secret question');
            self::fail('Expected AiException');
        } catch (AiException $e) {
            self::assertTrue($e->isRetryable());
        }

        $logs = $this->logs->getArrayCopy();
        self::assertSame('warning', $logs[0][0] ?? null);
        self::assertTrue($logs[0][2]['retryable'] ?? null);
    }

    public function testLogsNeverContainPromptsOrAnswers(): void
    {
        $ai = $this->ai(new FakeProvider(['the secret answer', 'streamed secret']));
        $ai->text('my private prompt');
        iterator_to_array($ai->stream('another private prompt'));

        self::assertCount(2, $this->logs);
        $everything = (string) json_encode($this->logs->getArrayCopy());
        self::assertStringNotContainsString('private prompt', $everything);
        self::assertStringNotContainsString('secret', $everything);
        $logs = $this->logs->getArrayCopy();
        self::assertSame('fake', $logs[0][2]['provider'] ?? null);
        self::assertSame(10, $logs[0][2]['usage_in'] ?? null);
        self::assertTrue($logs[1][2]['streamed'] ?? null);
    }

    public function testTokenCountsSurviveLogRedaction(): void
    {
        // Every context key containing "token" is redacted, which hid the counts before.
        $this->ai(new FakeProvider(['answer']))->text('prompt');
        $context = $this->logs->getArrayCopy()[0][2] ?? null;
        self::assertIsArray($context);

        self::assertSame($context, (new \VeyroPHP\Logging\Redactor())->redact($context));
    }

    public function testValidation(): void
    {
        $ai = $this->ai(new FakeProvider());

        foreach ([
            static fn() => $ai->chat([]),
            static fn() => $ai->chat([Message::user('a'), Message::assistant('b')]),
            /** @phpstan-ignore argument.type (invalid on purpose: plain strings are rejected at runtime) */
            static fn() => $ai->chat(['plain string']),
            static fn() => new Options(maxTokens: 0),
            static fn() => new Options(effort: 'extreme'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected AiException');
            } catch (AiException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUsingSwitchesProviderAndResolvesEachOnce(): void
    {
        $made = [];
        $providers = ['a' => new FakeProvider([], 'from a'), 'b' => new FakeProvider([], 'from b')];
        $ai = new Ai(static function (string $name) use (&$made, $providers): Provider {
            $made[] = $name;

            return $providers[$name];
        }, 'a');

        self::assertSame('from a', $ai->text('x'));
        self::assertSame('from b', $ai->using('b')->text('x'));
        self::assertSame('from a', $ai->text('x'));
        self::assertSame('from b', $ai->using('b')->text('x'));
        self::assertSame(['a', 'b'], $made);
    }

    public function testFakeHelperAndFakeQueue(): void
    {
        $fake = new FakeProvider(['first', static fn(Request $r): string => 'echo: ' . $r->messages[0]->content]);
        $ai = Ai::fake($fake);

        self::assertSame('first', $ai->text('a'));
        self::assertSame('echo: b', $ai->text('b'));
        self::assertSame('Fake answer.', $ai->text('c'), 'empty queue falls back');
        self::assertCount(3, $fake->requests());
    }
}
