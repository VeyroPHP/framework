<?php

declare(strict_types=1);

namespace VeyroPHP\Translation;

/**
 * Reads language files once per process.
 *
 *     lang/en/auth.php     return ['failed' => 'The email address or password is incorrect.', 'nested' => ['key' => '...']];
 *     lang/hi.json         {"Welcome, :name": "स्वागत है, :name"}
 *
 * Locale and group names are validated, so they cannot point outside the directory.
 *
 * @internal used by Translator
 */
final class TranslationLoader
{
    /** @var array<string, array<array-key, mixed>> */
    private array $groups = [];

    /** @var array<string, array<string, string>> */
    private array $json = [];

    public function __construct(private readonly string $directory) {}

    /**
     * @return array<array-key, mixed>
     */
    public function group(string $locale, string $group): array
    {
        self::assertName($locale, 'locale');
        self::assertName($group, 'group');

        return $this->groups[$locale . '/' . $group] ??= $this->read($this->directory . '/' . $locale . '/' . $group . '.php');
    }

    /**
     * @return array<string, string>
     */
    public function json(string $locale): array
    {
        self::assertName($locale, 'locale');

        if (isset($this->json[$locale])) {
            return $this->json[$locale];
        }

        $file = $this->directory . '/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        if (!is_array($data)) {
            throw new TranslationException(sprintf('%s is not valid JSON.', $file));
        }

        return $this->json[$locale] = array_filter($data, static fn(mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Locales with a directory or JSON file, sorted.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $locales = [];

        foreach (glob($this->directory . '/*') ?: [] as $entry) {
            $name = basename($entry, '.json');

            if (preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/D', $name) === 1) {
                $locales[$name] = true;
            }
        }

        $locales = array_keys($locales);
        sort($locales);

        return $locales;
    }

    /**
     * Group names of a locale (file names without .php), sorted.
     *
     * @return list<string>
     */
    public function groups(string $locale): array
    {
        self::assertName($locale, 'locale');
        $groups = array_map(static fn(string $f): string => basename($f, '.php'), glob($this->directory . '/' . $locale . '/*.php') ?: []);
        sort($groups);

        return $groups;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $data = require $file;

        if (!is_array($data)) {
            throw new TranslationException(sprintf('%s must return an array.', $file));
        }

        return $data;
    }

    private static function assertName(string $name, string $what): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) !== 1) {
            throw new TranslationException(sprintf('Invalid %s name "%s".', $what, $name));
        }
    }
}
