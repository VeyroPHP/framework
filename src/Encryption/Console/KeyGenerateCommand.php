<?php

declare(strict_types=1);

namespace VeyroPHP\Encryption\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\ConfigCache;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Encryption\Encrypter;

/**
 * Writes a new APP_KEY to .env.
 *
 * - Refuses to replace an existing key unless --force is given, because data
 *   encrypted with it would become unreadable.
 * - With --force, the old key moves to the front of APP_PREVIOUS_KEYS, so
 *   existing encrypted data can still be decrypted (key rotation).
 * - --show prints a key without changing any file.
 */
#[AsCommand('key:generate', 'Create the application encryption key (APP_KEY) in .env')]
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly Application $app) {}

    public function definition(): array
    {
        return [
            Option::flag('show', 'Only print a new key'),
            Option::flag('force', 'Replace an existing key; the old key is kept in APP_PREVIOUS_KEYS'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $key = Encrypter::generateKey();

        if ($input->flag('show')) {
            $output->line($key);

            return self::SUCCESS;
        }

        $path = $this->app->basePath('.env');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $output->error('.env not found. Copy .env.example to .env first.');

            return self::FAILURE;
        }

        $current = self::value($contents, 'APP_KEY');

        if ($current !== null && $current !== '' && !$input->flag('force')) {
            $output->error('APP_KEY is already set. Replacing it makes data encrypted with it unreadable; use --force to rotate (the old key is kept in APP_PREVIOUS_KEYS).');

            return self::FAILURE;
        }

        $contents = self::set($contents, 'APP_KEY', $key);

        if ($current !== null && $current !== '') {
            $previous = array_values(array_filter(
                array_map('trim', explode(',', self::value($contents, 'APP_PREVIOUS_KEYS') ?? '')),
                static fn(string $k): bool => $k !== '' && $k !== $current,
            ));
            $contents = self::set($contents, 'APP_PREVIOUS_KEYS', implode(',', [$current, ...$previous]));
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            $output->error('Could not write ' . $path);

            return self::FAILURE;
        }

        $output->success($current === null || $current === '' ? 'APP_KEY created in .env.' : 'APP_KEY rotated; the old key is now first in APP_PREVIOUS_KEYS.');

        if (is_file($this->app->basePath(ConfigCache::RELATIVE_PATH))) {
            $output->line('The configuration is cached: run "php veyro config:cache" again so the new key is used.');
        }

        return self::SUCCESS;
    }

    private static function value(string $contents, string $name): ?string
    {
        // No "$": in multiline mode it does not match before "\r" of CRLF line endings.
        if (preg_match('/^' . $name . '=([^\r\n]*)/m', $contents, $m) !== 1) {
            return null;
        }

        return trim(trim($m[1]), '"\'');
    }

    private static function set(string $contents, string $name, string $value): string
    {
        $line = $name . '=' . $value;

        if (preg_match('/^' . $name . '=[^\r\n]*/m', $contents) === 1) {
            return (string) preg_replace_callback('/^' . $name . '=[^\r\n]*/m', static fn(): string => $line, $contents, 1);
        }

        return rtrim($contents, "\r\n") . ($contents === '' ? '' : "\n") . $line . "\n";
    }
}
