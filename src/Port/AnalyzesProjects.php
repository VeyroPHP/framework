<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * Shared reading helpers for analyzers. Everything works on decoded JSON and
 * PHP tokens; nothing from the project is executed.
 */
trait AnalyzesProjects
{
    private function requires(SourceTree $tree, string $package): bool
    {
        $composer = $tree->json('composer.json') ?? [];

        foreach (['require', 'require-dev'] as $key) {
            if (is_array($composer[$key] ?? null) && array_key_exists($package, $composer[$key])) {
                return true;
            }
        }

        return $this->lockedVersion($tree, $package) !== null;
    }

    private function lockedVersion(SourceTree $tree, string $package): ?string
    {
        foreach ($this->lockedPackages($tree) as $entry) {
            if ($entry['name'] === $package) {
                return $entry['version'];
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, version: string, requires: list<string>}>
     */
    private function lockedPackages(SourceTree $tree): array
    {
        $lock = $tree->json('composer.lock') ?? [];
        $packages = [];

        foreach (['packages', 'packages-dev'] as $key) {
            foreach (is_array($lock[$key] ?? null) ? $lock[$key] : [] as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null)) {
                    $packages[] = [
                        'name' => $entry['name'],
                        'version' => is_string($entry['version'] ?? null) ? $entry['version'] : '',
                        'requires' => is_array($entry['require'] ?? null) ? array_map('strval', array_keys($entry['require'])) : [],
                    ];
                }
            }
        }

        return $packages;
    }

    /**
     * Sums the counts a tokens callback returns for each file.
     *
     * @param list<string> $files
     * @param callable(list<array{0: int, 1: string, 2: int}|string>): array<string, int> $count
     * @return array{array<string, int>, array<string, list<string>>} [totals, key => files]
     */
    private function sum(SourceTree $tree, array $files, callable $count): array
    {
        $totals = $where = [];

        foreach ($files as $file) {
            foreach ($count($tree->tokens($file)) as $key => $n) {
                $totals[$key] = ($totals[$key] ?? 0) + $n;
                $where[$key][] = $file;
            }
        }

        ksort($totals);

        return [$totals, $where];
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function examples(array $files): array
    {
        $files = array_values(array_unique($files));
        sort($files);

        return array_slice($files, 0, 10);
    }

    /**
     * @param array<string, array{string, Status, string}> $folders folder => [item, status, note]
     */
    private function folders(SourceTree $tree, Report $report, array $folders, string $guide): void
    {
        foreach ($folders as $folder => [$item, $status, $note]) {
            $files = $tree->under($folder);

            if ($files !== []) {
                $report->add(new Finding('Structure', $item, $status, count($files), $note, $this->examples($files), $guide));
            }
        }
    }

    /**
     * Classifies composer.json dependencies. Packages the callback calls
     * framework-independent are checked in composer.lock: if they depend on
     * the source framework ($frameworkPrefix), they need a closer look.
     *
     * @param callable(string): (array{Status, string}|null) $classify null to skip a package
     */
    private function classifyPackages(SourceTree $tree, Report $report, string $guide, callable $classify, string $frameworkPrefix): void
    {
        $composer = $tree->json('composer.json');

        if ($composer === null) {
            $report->warn('No readable composer.json: dependencies were not analyzed.');

            return;
        }

        $locked = [];

        foreach ($this->lockedPackages($tree) as $entry) {
            $locked[$entry['name']] = $entry['requires'];
        }

        $groups = [];

        foreach (['require', 'require-dev'] as $key) {
            foreach (array_keys(is_array($composer[$key] ?? null) ? $composer[$key] : []) as $name) {
                $name = (string) $name;

                if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || str_starts_with($name, 'composer/')) {
                    continue;
                }

                $verdict = $classify($name);

                if ($verdict === null) {
                    continue;
                }

                [$status, $note] = $verdict;
                $frameworkDeps = array_values(array_filter($locked[$name] ?? [], static fn(string $dep): bool => str_starts_with($dep, $frameworkPrefix) && !in_array($dep, ['illuminate/collections', 'illuminate/macroable', 'illuminate/conditionable', 'illuminate/contracts'], true)));

                if ($status === Status::Direct && $frameworkDeps !== []) {
                    [$status, $note] = [Status::Manual, 'depends on ' . implode(', ', $frameworkDeps) . ': check whether it works without the framework'];
                }

                $groups[$status->value . '|' . $note][] = $name;
            }
        }

        ksort($groups);

        foreach ($groups as $key => $names) {
            [$status, $note] = explode('|', $key, 2);
            sort($names);
            $report->add(new Finding('Packages', implode(', ', $names), Status::from($status), count($names), $note, ['composer.json'], 'docs/getting-started/packages.md'));
        }
    }
}
