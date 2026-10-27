<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

use Anthropic\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Ai\Providers\AnthropicProvider;
use VeyroPHP\Ai\Testing\FakeProvider;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;

/**
 * Registers Ai. Providers are created on first use, so an application that
 * configures AI but does not call it pays nothing.
 *
 * Config (config/ai.php):
 *
 *     'default' => 'anthropic',
 *     'providers' => [
 *         'anthropic' => [
 *             'driver' => 'anthropic',
 *             'api_key' => $env->string('ANTHROPIC_API_KEY', ''),
 *             'model' => 'claude-opus-5-5',
 *             'max_tokens' => 16000,          // non-streaming answers
 *             'stream_max_tokens' => 64000,   // streamed answers
 *             'effort' => null,               // low, medium, high, xhigh, max; null = model default
 *             'timeout' => 120,               // seconds per HTTP request
 *             'max_retries' => 2,             // for rate limits, overload and server errors
 *             'refusal_fallback' => true,     // server-side fallback when the model declines
 *         ],
 *         'fake' => ['driver' => 'fake'],
 *     ],
 */
final class AiServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->singleton(Ai::class, static function (Container $c): Ai {
            $config = $c->make(Config::class);
            $default = $config->get('ai.default', 'anthropic');

            return new Ai(
                static fn(string $name): Provider => self::make($name, $config),
                is_string($default) ? $default : 'anthropic',
                $c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger(),
            );
        });
    }

    private static function make(string $name, Config $config): Provider
    {
        $settings = $config->get('ai.providers.' . $name);
        if (!is_array($settings)) {
            throw new AiException(sprintf('No AI provider "%s" is configured in config/ai.php.', $name));
        }

        $get = static fn(string $key, mixed $default = null): mixed => $settings[$key] ?? $default;

        return match ($get('driver')) {
            'anthropic' => self::anthropic($get),
            'fake' => new FakeProvider(),
            default => throw new AiException(sprintf('Unknown AI driver for provider "%s"; supported: anthropic, fake.', $name)),
        };
    }

    /**
     * @param \Closure(string, mixed=): mixed $get
     */
    private static function anthropic(\Closure $get): AnthropicProvider
    {
        if (!class_exists(Client::class)) {
            throw AiException::missingPackage('anthropic', 'composer require anthropic-ai/sdk guzzlehttp/guzzle');
        }

        $key = $get('api_key');
        if (!is_string($key) || $key === '') {
            throw new AiException('The Anthropic API key is missing: set ANTHROPIC_API_KEY in .env.');
        }

        $int = static function (string $name, int $default) use ($get): int {
            $value = $get($name, $default);

            return is_int($value) && $value >= 0 ? $value : throw new AiException(sprintf('ai config "%s" must be a non-negative integer.', $name));
        };
        $model = $get('model', AnthropicProvider::DEFAULT_MODEL);
        $effort = $get('effort');

        $client = new Client(apiKey: $key, requestOptions: [
            'timeout' => (float) $int('timeout', 120),
            'maxRetries' => $int('max_retries', 2),
        ]);

        return new AnthropicProvider(
            $client,
            is_string($model) && $model !== '' ? $model : AnthropicProvider::DEFAULT_MODEL,
            max(1, $int('max_tokens', 16000)),
            max(1, $int('stream_max_tokens', 64000)),
            is_string($effort) ? $effort : null,
            $get('refusal_fallback', true) !== false,
        );
    }
}
