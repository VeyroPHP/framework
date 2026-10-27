<?php

declare(strict_types=1);

namespace VeyroPHP\Translation;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\BootableProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Validation\Validator;
use VeyroPHP\View\View;

/**
 * Registers Translator (per request), SetLocale and translated validation
 * messages, and gives templates $t('key', [...]), $tc('key', $count, [...]) and $locale().
 *
 * Config (config/app.php):
 *
 *     'locale' => 'en',               // default
 *     'fallback_locale' => 'en',      // used for missing keys
 *     'locales' => ['en', 'hi'],      // what SetLocale may choose
 *
 * Language files live in lang/ (config "translation.path" to change it).
 */
final class TranslationServiceProvider implements BootableProvider
{
    /** @var list<class-string<\VeyroPHP\Console\Command>> */
    public const COMMANDS = [Console\MissingCommand::class];

    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(TranslationLoader::class, static function (Container $c) use ($app): TranslationLoader {
            $path = $c->make(Config::class)->get('translation.path', 'lang');

            return new TranslationLoader($app->basePath(is_string($path) ? $path : 'lang'));
        });

        $container->scoped(Translator::class, static function (Container $c): Translator {
            $config = $c->make(Config::class);

            return new Translator($c->make(TranslationLoader::class), self::string($config, 'app.locale', 'en'), self::string($config, 'app.fallback_locale', 'en'));
        });

        $container->bind(SetLocale::class, static function (Container $c): SetLocale {
            $config = $c->make(Config::class);
            $default = self::string($config, 'app.locale', 'en');
            $locales = $config->get('app.locales', [$default]);

            return new SetLocale(
                $c->make(Translator::class),
                is_array($locales) ? array_values(array_filter($locales, 'is_string')) : [$default],
                $default,
            );
        });

        $container->bind(Validator::class, static fn(Container $c): Validator => new Validator([], static function (string $key) use ($c): ?string {
            $translator = $c->make(Translator::class);

            return $translator->has($key) || $translator->has($key, self::string($c->make(Config::class), 'app.fallback_locale', 'en'))
                ? $translator->get($key)
                : null;
        }));
    }

    public function boot(Application $app): void
    {
        $container = $app->container();

        if (!$container->bound(View::class)) {
            return;
        }

        $view = $container->make(View::class);
        // Resolved on each call: the translator belongs to the current request.
        $view->share('t', static fn(string $key, array $replace = []): string => $container->make(Translator::class)->get($key, self::replacements($replace)));
        $view->share('locale', static fn(): string => $container->make(Translator::class)->locale());
        $view->share('tc', static fn(string $key, int $count, array $replace = []): string => $container->make(Translator::class)->choice($key, $count, self::replacements($replace)));
    }

    /**
     * Templates are not type-checked: keep only named scalar replacements.
     *
     * @param array<mixed> $replace
     *
     * @return array<string, string|int|float>
     */
    private static function replacements(array $replace): array
    {
        $clean = [];

        foreach ($replace as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || is_float($value))) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    private static function string(Config $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
