<?php

declare(strict_types=1);

namespace VeyroPHP\Package;

/**
 * The list of installed Composer packages that integrate with VeyroPHP.
 *
 * A package opts in through its composer.json:
 *
 *     "extra": {
 *         "veyrophp": {
 *             "providers": ["Acme\\Greeting\\GreetingServiceProvider"]
 *         }
 *     }
 *
 * - Discovery only reads vendor/composer/installed.json; no package code runs.
 * - Order is deterministic: packages sorted by name, providers in the order
 *   each package lists them.
 * - The result is cached in bootstrap/cache/packages.php and rebuilt when
 *   installed.json changes (or with "php veyro package:discover").
 * - Invalid entries are skipped and reported in warnings(), never guessed.
 */
final class PackageManifest
{
    public const CACHE = 'bootstrap/cache/packages.php';

    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';

    /** @var list<string> */
    private array $warnings = [];

    private bool $cacheWritten = false;

    public function __construct(
        private readonly string $basePath,
        private readonly string $vendorDir = 'vendor',
    ) {}

    /**
     * Packages with their providers, from the cache when it is current.
     *
     * @return array<string, array{version: string, providers: list<string>}>
     */
    public function packages(): array
    {
        $cache = $this->path(self::CACHE);
        $installed = $this->installedJson();

        if (is_file($cache) && (!is_file($installed) || filemtime($cache) >= filemtime($installed))) {
            $data = require $cache;

            if (is_array($data) && is_array($data['packages'] ?? null)) {
                /** @var array<string, array{version: string, providers: list<string>}> $packages */
                $packages = $data['packages'];

                return $packages;
            }
        }

        return $this->build();
    }

    /**
     * Reads installed.json and writes the cache.
     *
     * @return array<string, array{version: string, providers: list<string>}>
     */
    public function build(): array
    {
        $this->warnings = [];
        $packages = [];
        $file = $this->installedJson();

        if (is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            $list = is_array($json) ? ($json['packages'] ?? $json) : [];

            foreach (is_array($list) ? $list : [] as $package) {
                $entry = is_array($package) ? $this->entry($package) : null;

                if ($entry !== null) {
                    $packages[$entry[0]] = $entry[1];
                }
            }
        }

        ksort($packages, SORT_STRING);
        $this->cacheWritten = $this->write($packages);

        return $packages;
    }

    /**
     * Whether the last build() wrote bootstrap/cache/packages.php. When it could not,
     * warnings() says why, and every request reads installed.json again (CORE-4).
     */
    public function cacheWritten(): bool
    {
        return $this->cacheWritten;
    }

    /**
     * Providers to register, in order, without the packages listed in $dontDiscover
     * ("vendor/name", or "*" for none at all).
     *
     * @param list<string> $dontDiscover
     *
     * @return list<string>
     */
    public function providers(array $dontDiscover = []): array
    {
        if (in_array('*', $dontDiscover, true)) {
            return [];
        }

        $providers = [];

        foreach ($this->packages() as $name => $package) {
            if (!in_array($name, $dontDiscover, true)) {
                array_push($providers, ...$package['providers']);
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * Problems found while reading package metadata during the last build().
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<mixed> $package
     *
     * @return array{string, array{version: string, providers: list<string>}}|null
     */
    private function entry(array $package): ?array
    {
        $name = $package['name'] ?? null;
        $extra = is_array($package['extra'] ?? null) ? ($package['extra']['veyrophp'] ?? null) : null;

        if (!is_string($name) || !is_array($extra)) {
            return null;
        }

        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#D', $name) !== 1) {
            $this->warnings[] = sprintf('Skipped package with invalid name "%s".', $name);

            return null;
        }

        $providers = [];

        foreach ((array) ($extra['providers'] ?? []) as $provider) {
            if (is_string($provider) && preg_match(self::CLASS_NAME, ltrim($provider, '\\')) === 1) {
                $providers[] = ltrim($provider, '\\');
            } else {
                $this->warnings[] = sprintf('%s: skipped invalid provider entry %s.', $name, json_encode($provider));
            }
        }

        $version = $package['version'] ?? 'unknown';

        return [$name, ['version' => is_string($version) ? $version : 'unknown', 'providers' => $providers]];
    }

    /**
     * @param array<string, array{version: string, providers: list<string>}> $packages
     */
    private function write(array $packages): bool
    {
        $cache = $this->path(self::CACHE);
        $dir = dirname($cache);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->writeFailed('its directory could not be created');
        }

        $temp = $cache . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $code = "<?php\n\n// Generated by \"php veyro package:discover\"; do not edit.\n\nreturn " . var_export(['packages' => $packages], true) . ";\n";

        if (@file_put_contents($temp, $code, LOCK_EX) !== strlen($code)) {
            @unlink($temp);

            return $this->writeFailed('the file could not be written');
        }

        if (!@rename($temp, $cache)) {
            @unlink($temp);

            return $this->writeFailed('the new file could not replace the old one');
        }

        return true;
    }

    private function writeFailed(string $reason): bool
    {
        $this->warnings[] = sprintf('Could not write %s: %s. Make bootstrap/cache writable; until then every request reads installed.json.', self::CACHE, $reason);

        return false;
    }

    private function installedJson(): string
    {
        return $this->path($this->vendorDir . '/composer/installed.json');
    }

    private function path(string $relative): string
    {
        return rtrim($this->basePath, '/\\') . '/' . $relative;
    }
}
