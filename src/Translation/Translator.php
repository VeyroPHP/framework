<?php

declare(strict_types=1);

namespace VeyroPHP\Translation;

/**
 * Translates text for the current request's locale.
 *
 *     $t->get('auth.failed');                          // lang/{locale}/auth.php, key "failed"
 *     $t->get('Welcome, :name', ['name' => 'Asha']);   // lang/{locale}.json, or the text itself
 *     $t->choice('cart.items', 3);                     // "{0} Empty|{1} One item|[2,*] :count items"
 *
 * - Group keys ("group.key.nested") come from PHP files; anything else is
 *   looked up in the locale's JSON file, and the text itself is the fallback.
 * - Missing in the locale: the fallback locale is used; missing there too: the key.
 * - :name, :Name and :NAME are replaced (as given, first letter upper, all upper).
 *   Replacements are not escaped; escape the result in views ($e).
 */
final class Translator
{
    private string $locale;

    public function __construct(
        private readonly TranslationLoader $loader,
        string $locale = 'en',
        private readonly string $fallback = 'en',
    ) {
        $this->setLocale($locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if (preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/D', $locale) !== 1) {
            throw new TranslationException(sprintf('Invalid locale "%s".', $locale));
        }

        $this->locale = $locale;
    }

    /**
     * @param array<string, string|int|float> $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = $this->find($key, $locale ?? $this->locale) ?? $this->find($key, $this->fallback) ?? $key;

        return self::replace($line, $replace);
    }

    /**
     * @param array<string, string|int|float> $replace :count is added automatically
     */
    public function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $line = $this->find($key, $locale);

        if ($line === null) {
            $line = $this->find($key, $this->fallback) ?? $key;
            $locale = $this->fallback;
        }

        return self::replace(PluralSelector::choose($line, $count, $locale), $replace + ['count' => $count]);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->find($key, $locale ?? $this->locale) !== null;
    }

    private function find(string $key, string $locale): ?string
    {
        $json = $this->loader->json($locale);

        if (isset($json[$key])) {
            return $json[$key];
        }

        if (preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_.-]+)$/D', $key, $m) !== 1) {
            return null;
        }

        $value = $this->loader->group($locale, $m[1]);

        // A key with dots may also be a flat key in the file ('min.string' => '...').
        if (is_string($value[$m[2]] ?? null)) {
            return $value[$m[2]];
        }

        foreach (explode('.', $m[2]) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, string|int|float> $replace
     */
    private static function replace(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        // strtr() replaces the longest match first, so :name does not break :name_full.
        $pairs = [];

        foreach ($replace as $name => $value) {
            $value = (string) $value;
            $pairs[':' . $name] = $value;
            $pairs[':' . ucfirst($name)] = self::upperFirst($value);
            $pairs[':' . strtoupper($name)] = function_exists('mb_strtoupper') ? mb_strtoupper($value) : strtoupper($value);
        }

        return strtr($line, $pairs);
    }

    private static function upperFirst(string $value): string
    {
        if (!function_exists('mb_strtoupper')) {
            return ucfirst($value);
        }

        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }
}
