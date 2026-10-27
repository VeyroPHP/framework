<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * The packages recorded in a composer.lock. Only the JSON is read: nothing is
 * installed, autoloaded or executed.
 */
final class LockFile
{
    /**
     * @param list<array{name: string, version: string, dev: bool, abandoned: ?string}> $packages
     *        abandoned: null if not abandoned, otherwise the suggested replacement ('' when none)
     */
    private function __construct(
        public readonly string $path,
        public readonly string $sha256,
        private readonly array $packages,
    ) {}

    public static function read(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new AuditException("No lock file at {$path}: run composer install or composer update first.");
        }

        $data = json_decode($json, true);

        if (!is_array($data) || !is_array($data['packages'] ?? null)) {
            throw new AuditException("{$path} is not a valid composer.lock.");
        }

        $packages = [];

        foreach (['packages' => false, 'packages-dev' => true] as $key => $dev) {
            $list = $data[$key] ?? [];

            foreach (is_array($list) ? $list : [] as $package) {
                if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                    throw new AuditException("{$path} has a package entry without a name or version.");
                }

                $abandoned = $package['abandoned'] ?? false;
                $packages[] = [
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'dev' => $dev,
                    'abandoned' => is_string($abandoned) ? $abandoned : ($abandoned === true ? '' : null),
                ];
            }
        }

        usort($packages, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);

        return new self($path, hash('sha256', $json), $packages);
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    /**
     * @return list<array{name: string, version: string, dev: bool, abandoned: ?string}>
     */
    public function packages(bool $includeDev = true): array
    {
        return $includeDev ? $this->packages : array_values(array_filter($this->packages, static fn(array $p): bool => !$p['dev']));
    }

    public function version(string $package): ?string
    {
        foreach ($this->packages as $entry) {
            if ($entry['name'] === $package) {
                return $entry['version'];
            }
        }

        return null;
    }
}
