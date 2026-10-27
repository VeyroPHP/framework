# AI

`VeyroPHP\Ai` adds AI features (answers, chat, structured data, streaming) behind one small interface. Design: [ADR 0019](../architecture/adr/0019-ai.md).

AI is a first-party package, `veyrophp/ai` ([ADR 0034](../architecture/adr/0034-first-party-packages.md)); the core does not include it. The included driver uses Claude through Anthropic's official PHP SDK. Install them only when you use AI:

```bash
composer require veyrophp/ai
composer require anthropic-ai/sdk guzzlehttp/guzzle
```

Then set the key in `.env` (never commit it):

```ini
ANTHROPIC_API_KEY=sk-ant-...
```

Package discovery registers `VeyroPHP\Ai\AiServiceProvider`; configure `config/ai.php`; see the starter app's file for every setting. The default model is `claude-opus-5-5`.

**Every request to a real provider costs money.** Use the `fake` provider while developing and in tests.

## Usage

```php
use VeyroPHP\Ai\Ai;
use VeyroPHP\Ai\Message;
use VeyroPHP\Ai\Options;

public function summary(Ai $ai, string $article): string
{
    return $ai->text("Summarize in three sentences:\n\n" . $article);
}
```

| Method | Returns |
| --- | --- |
| `text($prompt, $options)` | The answer as a string. A refusal throws `AiException` (`isRefusal()`). |
| `chat([Message::user(...), Message::assistant(...), ...], $options)` | `Response`: `text`, `model`, `stopReason`, `inputTokens`, `outputTokens`, `refused()`, `truncated()` |
| `json($prompt, $schema, $options)` | The answer decoded from JSON that follows `$schema` |
| `stream($prompt, $options)` | A generator of text pieces; `getReturn()` gives the final `Response` |
| `using('fake')` | The same service with another configured provider |

`Options`: `system` (instructions, kept apart from user input), `model`, `maxTokens`, `effort` (`low`, `medium`, `high`, `xhigh`, `max`: how much the model thinks; lower is cheaper and faster).

```php
$data = $ai->json('Extract the customer: ' . $email, [
    'type' => 'object',
    'properties' => ['name' => ['type' => 'string'], 'plan' => ['type' => 'string', 'enum' => ['free', 'pro']]],
    'required' => ['name', 'plan'],
    'additionalProperties' => false,
]);

foreach ($ai->stream('Write a short poem about Jaipur', new Options(effort: 'low')) as $piece) {
    echo $piece;
}
```

From the command line: `php veyro ai:ask "Your question"` (starter app), or `--provider=fake` to try it without a key.

## Errors

All failures are `AiException`:

- `isRetryable()`: rate limit, overloaded provider, server or network error. The SDK has already retried with back-off (`max_retries`, default 2); try again later or queue the work.
- `isRefusal()`: the model declined. By default the API first retries on a fallback model (`refusal_fallback`), so a refusal that reaches you is final.
- Otherwise: a configuration or request problem (missing key, unknown model, invalid schema).

Messages never contain the API key or the prompt.

## Testing

Replace `Ai` with a fake; nothing is sent and nothing is billed:

```php
$fake = new FakeProvider(['First answer', '{"name":"Asha","plan":"pro"}']);
$container->instance(Ai::class, Ai::fake($fake));

// ... run the code under test ...

$fake->lastPrompt();                     // the last prompt sent
$fake->requests()[0]->options->system;   // what was configured
```

Queue entries can be strings, `Response` objects, exceptions (thrown), or closures that build an answer from the request. The starter app's `tests/Feature/AiCommandTest.php` is a complete example.

## Logging

Each call is logged at `info` with provider, model, token counts, duration and stop reason, and failures at `warning`. Prompts and answers are **never** logged, because they often contain personal data.

## Security

- **Prompt injection:** text from users, web pages, emails or files can contain instructions aimed at the model ("ignore your instructions and…"). Put your instructions in `system`, pass outside text as data, and never let the model's output decide on its own what your application does (deleting records, sending money, changing permissions). Check permissions in code as for any other user input.
- **Output is untrusted input:** escape it before showing it (`$e()` in views), and validate `json()` results like form data before saving them.
- **Personal data:** only send what the task needs, and tell users when their data goes to an AI provider.
- **Costs:** limit who may trigger AI calls, rate-limit those routes (`ThrottleRequests`), and set `max_tokens`.
- **Keys:** keep `ANTHROPIC_API_KEY` in `.env` or the server environment; rotate it if it leaks.

## Adding another provider

Implement `VeyroPHP\Ai\Provider` (`name()`, `chat()`, `stream()`) and return it from your own service provider, or add a driver to `AiServiceProvider`. The rest of the application does not change.
