<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

use VeyroPHP\Port\Patterns;
use VeyroPHP\Port\PortException;

/**
 * Writes converted files into a VeyroPHP application, safely:
 *
 * - the target must be a VeyroPHP application (a "veyro" script and a
 *   composer.json that requires veyrophp/core);
 * - the source and the target may not contain each other, so the source is
 *   never written to;
 * - target paths are relative and may not leave the target;
 * - existing files are never replaced, except files this converter wrote
 *   earlier (they carry ConvertedFile::MARKER) when $force is set.
 */
final class ProjectWriter
{
    public readonly string $root;

    public function __construct(string $target, string $sourceRoot)
    {
        $real = realpath($target);

        if ($real === false || !is_dir($real)) {
            throw new PortException("{$target} is not a directory: create the new application first (composer create-project veyrophp/app).");
        }

        $this->root = str_replace('\\', '/', $real);
        $composer = json_decode((string) @file_get_contents($this->root . '/composer.json'), true);
        $requires = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        if (!is_file($this->root . '/veyro') || !array_key_exists('veyrophp/core', $requires)) {
            throw new PortException("{$this->root} is not a VeyroPHP application (no veyro script or veyrophp/core requirement).");
        }

        $source = rtrim(str_replace('\\', '/', $sourceRoot), '/');

        if ($this->root === $source || str_starts_with($this->root . '/', $source . '/') || str_starts_with($source . '/', $this->root . '/')) {
            throw new PortException('The target must be a separate application: it may not be inside the source project, or contain it.');
        }
    }

    /**
     * @return 'written'|'exists'|'replaced'
     */
    public function plan(ConvertedFile $file, bool $force): string
    {
        $path = $this->path($file->target);

        if (!is_file($path)) {
            return 'written';
        }

        return $force && str_contains((string) file_get_contents($path), ConvertedFile::MARKER) ? 'replaced' : 'exists';
    }

    /**
     * @return 'written'|'exists'|'replaced'
     */
    public function write(ConvertedFile $file, bool $force): string
    {
        $status = $this->plan($file, $force);

        if ($status === 'exists') {
            return $status;
        }

        $path = $this->path($file->target);

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0o775, true)) {
            throw new PortException('Could not create ' . dirname($path) . '.');
        }

        if (file_put_contents($path, $file->content) === false) {
            throw new PortException("Could not write {$path}.");
        }

        return $status;
    }

    /**
     * Tables that converted migrations create and that migrations already in
     * the target (not written by the converter) also create: running both fails.
     *
     * @param list<ConvertedFile> $files
     * @return array<string, array{string, string}> table => [converted migration, existing migration]
     */
    public function tableConflicts(array $files): array
    {
        $existing = [];

        foreach (glob($this->root . '/database/migrations/*.php') ?: [] as $path) {
            $code = (string) file_get_contents($path);

            if (str_contains($code, ConvertedFile::MARKER)) {
                continue;
            }

            foreach (self::createdTables($code) as $table) {
                $existing[$table] = 'database/migrations/' . basename($path);
            }
        }

        $conflicts = [];

        foreach ($files as $file) {
            if ($file->kind === 'migration') {
                foreach (Patterns::createdTables($file->content) as $table) {
                    if (isset($existing[$table])) {
                        $conflicts[$table] = [$file->target, $existing[$table]];
                    }
                }
            }
        }

        ksort($conflicts);

        return $conflicts;
    }

    /**
     * Routes in converted route files whose method and path, or name, are
     * already used by the target's own route files. Approximate: it reads
     * "$router->verb('/path')" and "->name('x')" and follows group prefixes
     * by indentation, as the converter and the starter write them.
     *
     * @param list<ConvertedFile> $files
     * @return list<string>
     */
    public function routeConflicts(array $files): array
    {
        $existing = ['routes' => [], 'names' => []];

        foreach (glob($this->root . '/routes/*.php') ?: [] as $path) {
            $code = (string) file_get_contents($path);

            if (!str_contains($code, ConvertedFile::MARKER)) {
                $this->collectRoutes($code, 'routes/' . basename($path), $existing);
            }
        }

        $conflicts = [];

        foreach ($files as $file) {
            if ($file->kind !== 'routes') {
                continue;
            }

            $ported = ['routes' => [], 'names' => []];
            $this->collectRoutes($file->content, $file->target, $ported);

            foreach ($ported['routes'] as $route => $where) {
                if (isset($existing['routes'][$route])) {
                    $conflicts[] = "{$route} is defined in {$where} and in {$existing['routes'][$route]}";
                }
            }

            foreach ($ported['names'] as $name => $where) {
                if (isset($existing['names'][$name])) {
                    $conflicts[] = "route name \"{$name}\" is used in {$where} and in {$existing['names'][$name]}";
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param array{routes: array<string, string>, names: array<string, string>} $into
     */
    private function collectRoutes(string $code, string $file, array &$into): void
    {
        $prefixes = []; // indentation => prefix

        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            $indent = strlen($line) - strlen(ltrim($line));
            $prefixes = array_filter($prefixes, static fn(int $level): bool => $level < $indent, ARRAY_FILTER_USE_KEY);

            if (preg_match('/\$router->group\(\s*[\'"]([^\'"]*)[\'"]/', $line, $g) === 1) {
                $prefixes[$indent] = $g[1];

                continue;
            }

            $prefix = implode('', $prefixes);

            if (preg_match('/\$router->(get|post|put|patch|delete|options)\(\s*[\'"]([^\'"]+)[\'"]/', $line, $r) === 1) {
                $path = $prefix === '' ? $r[2] : ($r[2] === '/' ? $prefix : $prefix . $r[2]);
                $into['routes'][strtoupper($r[1]) . ' ' . $path] = $file;
            }

            if (preg_match('/->name\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $n) === 1) {
                $into['names'][$n[1]] = $file;
            }
        }
    }

    /**
     * Tables a migration creates: "->create('t')" calls, plus the ones made by
     * schema classes it calls ("QueueSchema::up($schema)"), read from those
     * classes' source files (found by reflection, not executed).
     *
     * @return list<string>
     */
    private static function createdTables(string $code): array
    {
        $tables = Patterns::createdTables($code);
        preg_match_all('/^use\s+([\w\\\\]+)\s*;/m', $code, $uses);
        $imports = [];

        foreach ($uses[1] as $use) {
            $imports[substr((string) strrchr('\\' . $use, '\\'), 1)] = $use;
        }

        preg_match_all('/\b([A-Z]\w*)::up\(/', $code, $calls);

        foreach (array_unique($calls[1]) as $short) {
            $class = $imports[$short] ?? null;

            if ($class !== null && class_exists($class)) {
                $file = (new \ReflectionClass($class))->getFileName();

                if (is_string($file)) {
                    $source = (string) file_get_contents($file);
                    $tables = [...$tables, ...Patterns::createdTables($source)];

                    // create($jobsTable, ...) with "string $jobsTable = 'jobs'" as a parameter default
                    preg_match_all('/->create\(\s*\$(\w+)\s*,/', $source, $variables);

                    foreach ($variables[1] as $variable) {
                        if (preg_match('/\$' . $variable . '\s*=\s*[\'"](\w+)[\'"]/', $source, $default) === 1) {
                            $tables[] = $default[1];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($tables));
    }

    private function path(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw new PortException("Refusing the target path {$relative}.");
        }

        return $this->root . '/' . $relative;
    }
}
