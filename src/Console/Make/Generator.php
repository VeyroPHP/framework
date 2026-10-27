<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use RuntimeException;
use VeyroPHP\Application\Application;
use VeyroPHP\Console\UsageException;

/**
 * Shared helpers for the make:* commands: name checks, naming conventions and
 * file writing that never overwrites.
 *
 * @internal
 */
final class Generator
{
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
        'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof',
        'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null',
        'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'return', 'self', 'static',
        'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    public function __construct(private readonly Application $app) {}

    /**
     * Splits "Admin/UserController" (or "Admin\UserController") into ["Admin", "UserController"].
     *
     * @return array{string, string} [sub-namespace with "\" separators or "", class name]
     *
     * @throws UsageException for names that are not StudlyCase PHP class names
     */
    public static function className(string $name): array
    {
        $segments = explode('/', str_replace('\\', '/', $name));

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $segment) !== 1) {
                throw new UsageException(sprintf('"%s" is not a valid class name; use StudlyCase such as Post or Admin/PostController.', $name));
            }
            if (in_array(strtolower($segment), self::RESERVED, true)) {
                throw new UsageException(sprintf('"%s" is a reserved word in PHP and cannot be a class name.', $segment));
            }
        }

        $class = (string) array_pop($segments);

        return [implode('\\', $segments), $class];
    }

    /**
     * Where a make:* command puts a class: "Admin/PostController" below App\Http\Controllers
     * and app/Http/Controllers (CORE-10).
     *
     * @return array{string, string, string, string} [namespace, class, file relative to the base path, sub-directory below $directory: "" or ending in "/"]
     *
     * @throws UsageException for names that are not StudlyCase PHP class names
     */
    public static function target(string $name, string $namespace, string $directory): array
    {
        [$sub, $class] = self::className($name);
        $subPath = $sub === '' ? '' : str_replace('\\', '/', $sub) . '/';

        return [$namespace . ($sub === '' ? '' : '\\' . $sub), $class, $directory . '/' . $subPath . $class . '.php', $subPath];
    }

    /**
     * "BlogPost" => "blog_posts". Simple English plurals only; use --table otherwise.
     */
    public static function tableName(string $class): string
    {
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $class));

        return match (true) {
            preg_match('/[^aeiou]y$/D', $snake) === 1 => substr($snake, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/D', $snake) === 1 => $snake . 'es',
            default => $snake . 's',
        };
    }

    /**
     * Absolute path for a path relative to the base path (validated by Application).
     */
    public function path(string $relativePath): string
    {
        return $this->app->basePath($relativePath);
    }

    /**
     * Writes a new file under the base path. Never overwrites.
     *
     * @return string the path relative to the base path, with "/" separators
     *
     * @throws UsageException when the file already exists
     * @throws RuntimeException when the file cannot be created or written completely
     */
    public function write(string $relativePath, string $contents): string
    {
        $path = $this->app->basePath($relativePath);

        if (file_exists($path)) {
            throw new UsageException(sprintf('%s already exists; nothing was written.', $relativePath));
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory %s.', $directory));
        }

        // Mode "x" fails if the file appeared in the meantime, so nothing is ever overwritten.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw file_exists($path)
                ? new UsageException(sprintf('%s already exists; nothing was written.', $relativePath))
                : new RuntimeException(sprintf('Could not create %s: check that the directory is writable.', $relativePath));
        }

        // A short write (full disk, quota) must not leave a truncated file reported as created (CORE-11).
        $written = fwrite($handle, $contents);
        $closed = fclose($handle);

        if ($written !== strlen($contents) || !$closed) {
            @unlink($path);

            throw new RuntimeException(sprintf('Could not write %s completely; nothing was kept.', $relativePath));
        }

        return str_replace('\\', '/', $relativePath);
    }
}
