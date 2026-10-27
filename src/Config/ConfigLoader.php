<?php

declare(strict_types=1);

namespace VeyroPHP\Config;

use Throwable;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Exception\ConfigException;

/**
 * Builds a Config from a directory of PHP files.
 *
 * Every "*.php" file directly inside the directory must return an array. The
 * file name without extension becomes the top-level key, so config/app.php is
 * read as "app.*". Subdirectories and other files are ignored. Files are loaded
 * in alphabetical order.
 *
 * Each file can read environment variables through the $env variable, an
 * instance of Env. Config files are executed as PHP code and must be trusted.
 */
final class ConfigLoader
{
    /**
     * @throws ConfigException when the directory is missing or a file is invalid
     */
    public function load(string $directory, Env $env = new Env()): Config
    {
        if (!is_dir($directory) || !is_readable($directory)) {
            throw ConfigException::directoryNotFound($directory);
        }

        $items = [];

        foreach (self::configFiles($directory) as $name => $path) {
            $items[$name] = self::requireFile($path, $env);
        }

        return new Config($items);
    }

    /**
     * @return array<string, string> top-level key => file path, sorted by key
     */
    private static function configFiles(string $directory): array
    {
        $entries = scandir($directory);

        if ($entries === false) {
            throw ConfigException::directoryNotFound($directory);
        }

        $files = [];

        foreach ($entries as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if ($entry[0] === '.' || !str_ends_with($entry, '.php') || !is_file($path)) {
                continue;
            }

            $name = substr($entry, 0, -4);

            if (str_contains($name, '.')) {
                throw ConfigException::invalidFileName($path);
            }

            $files[$name] = $path;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function requireFile(string $path, Env $env): array
    {
        try {
            // Static closure: the file sees only $__path and $env, not $this or the loader's locals.
            $returned = (static fn(string $__path, Env $env): mixed => require $__path)($path, $env);
        } catch (Throwable $e) {
            throw ConfigException::fileFailed($path, $e);
        }

        if (!is_array($returned)) {
            throw ConfigException::fileDidNotReturnArray($path, $returned);
        }

        return $returned;
    }
}
