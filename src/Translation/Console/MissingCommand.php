<?php

declare(strict_types=1);

namespace VeyroPHP\Translation\Console;

use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Translation\TranslationLoader;

/**
 * Compares every locale with the fallback locale and lists keys that are
 * missing, so untranslated text is found before users see it.
 */
#[AsCommand('lang:missing', 'List translation keys missing in each locale')]
final class MissingCommand extends Command
{
    public function __construct(
        private readonly TranslationLoader $loader,
        private readonly Config $config,
    ) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $fallback = $this->config->get('app.fallback_locale', 'en');
        $fallback = is_string($fallback) ? $fallback : 'en';
        $reference = $this->keys($fallback);
        $missingTotal = 0;

        foreach ($this->loader->locales() as $locale) {
            if ($locale === $fallback) {
                continue;
            }

            $missing = array_values(array_diff($reference, $this->keys($locale)));
            $missingTotal += count($missing);

            if ($missing === []) {
                $output->success(sprintf('%s: complete (%d keys)', $locale, count($reference)));

                continue;
            }

            $output->warning(sprintf('%s: %d missing', $locale, count($missing)));

            foreach ($missing as $key) {
                $output->line('  ' . $key);
            }
        }

        return $missingTotal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string> "group.key" for PHP files and the text for JSON entries
     */
    private function keys(string $locale): array
    {
        $keys = array_keys($this->loader->json($locale));

        foreach ($this->loader->groups($locale) as $group) {
            array_push($keys, ...self::flatten($this->loader->group($locale, $group), $group));
        }

        sort($keys);

        return $keys;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private static function flatten(array $values, string $prefix): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix . '.' . $key;
            array_push($keys, ...(is_array($value) ? self::flatten($value, $path) : [$path]));
        }

        return $keys;
    }
}
