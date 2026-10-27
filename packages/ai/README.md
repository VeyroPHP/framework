# veyrophp/ai

AI for the VeyroPHP framework: answers, chat, JSON that follows a schema, and streaming behind one small interface, with a `fake` provider for tests. The included driver uses Claude through Anthropic's official PHP SDK.

| | |
| --- | --- |
| PHP | 8.2 or newer |
| VeyroPHP | ^0.1 |
| License | MIT |

This is a first-party package. It is developed in the framework repository (`packages/ai`) and released with the same version numbers.

## Installation

```bash
composer require veyrophp/ai
composer require anthropic-ai/sdk guzzlehttp/guzzle   # only for the Anthropic driver
```

VeyroPHP discovers `VeyroPHP\Ai\AiServiceProvider` automatically (`php veyro package:discover`). Configure it in `config/ai.php`, and put the key in `.env` as `ANTHROPIC_API_KEY`.

**Every request to a real provider costs money.** Use the `fake` provider while developing and in tests.

## Usage

```php
public function summary(VeyroPHP\Ai\Ai $ai, string $article): string
{
    return $ai->text("Summarize in three sentences:\n\n" . $article);
}
```

The full guide is the framework's `docs/getting-started/ai.md`.

## Upgrading from veyrophp/core 0.1.0-dev before the split

The classes and the `VeyroPHP\Ai` namespace are unchanged. Add `composer require veyrophp/ai`. You can keep an explicit `$app->register(new AiServiceProvider())` (discovery then skips it) or remove it.

## Security

The API key is read from the configuration only and never logged. Report security problems as described in the framework's security policy.
