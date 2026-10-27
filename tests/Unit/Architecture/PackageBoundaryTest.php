<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the first-party packages in packages/ separable from the core:
 * the core never uses a package, each package is a valid VeyroPHP package,
 * and packages use only the core's public (non-@internal) classes, exactly
 * like third-party packages.
 */
final class PackageBoundaryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array<mixed>> package directory => decoded composer.json
     */
    private static function packages(): array
    {
        $packages = [];

        foreach (glob(self::root() . '/packages/*/composer.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($data, $file);
            $packages[dirname($file)] = $data;
        }

        return $packages;
    }

    /**
     * @return list<string> PHP files below $dir
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, true> namespace prefixes of the packages, e.g. "VeyroPHP\Ai\"
     */
    private static function packageNamespaces(): array
    {
        $namespaces = [];

        foreach (self::packages() as $data) {
            $psr4 = $data['autoload'] ?? null;
            $psr4 = is_array($psr4) && is_array($psr4['psr-4'] ?? null) ? $psr4['psr-4'] : [];

            foreach (array_keys($psr4) as $namespace) {
                $namespaces[(string) $namespace] = true;
            }
        }

        return $namespaces;
    }

    public function testThereIsAtLeastOnePackage(): void
    {
        self::assertArrayHasKey(self::root() . '/packages/ai', self::packages());
    }

    public function testEachPackageIsAValidVeyroPHPPackage(): void
    {
        foreach (self::packages() as $dir => $data) {
            $name = basename($dir);
            self::assertSame('veyrophp/' . $name, $data['name'] ?? null, $dir);
            self::assertSame('MIT', $data['license'] ?? null, $dir);

            $require = is_array($data['require'] ?? null) ? $data['require'] : [];
            self::assertArrayHasKey('veyrophp/core', $require, "{$name} must declare the framework versions it supports.");
            self::assertArrayHasKey('php', $require, $name);

            $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
            $veyrophp = is_array($extra['veyrophp'] ?? null) ? $extra['veyrophp'] : [];
            $providers = is_array($veyrophp['providers'] ?? null) ? $veyrophp['providers'] : [];
            self::assertNotSame([], $providers, "{$name} must list its providers for discovery.");

            foreach ($providers as $provider) {
                self::assertIsString($provider);
                self::assertTrue(class_exists($provider), "{$name}: provider {$provider} does not exist.");
                self::assertTrue(is_subclass_of($provider, \VeyroPHP\Application\ServiceProvider::class), "{$name}: {$provider} is not a service provider.");
            }

            foreach (['README.md', 'LICENSE', 'CHANGELOG.md'] as $file) {
                self::assertFileExists($dir . '/' . $file);
            }
        }
    }

    public function testTheCoreNeverUsesAPackage(): void
    {
        $uses = [];

        foreach (self::phpFiles(self::root() . '/src') as $file) {
            $code = (string) file_get_contents($file);

            foreach (array_keys(self::packageNamespaces()) as $namespace) {
                if (str_contains($code, $namespace)) {
                    $uses[] = substr($file, strlen(self::root()) + 1) . ' uses ' . $namespace;
                }
            }
        }

        self::assertSame([], $uses, 'The core must work without the first-party packages.');
    }

    public function testPackagesUseOnlyThePublicCoreApi(): void
    {
        $problems = [];
        $own = array_keys(self::packageNamespaces());

        foreach (self::packages() as $dir => $data) {
            foreach (self::phpFiles($dir . '/src') as $file) {
                preg_match_all('/\b(VeyroPHP\\\\[A-Z]\w*(?:\\\\[A-Z]\w*)+)/', (string) file_get_contents($file), $m);

                foreach (array_unique($m[1]) as $class) {
                    foreach ($own as $namespace) {
                        if (str_starts_with($class . '\\', $namespace)) {
                            continue 2;
                        }
                    }

                    $where = substr($file, strlen(self::root()) + 1);

                    if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
                        $problems[] = "{$where}: {$class} is not a core class";

                        continue;
                    }

                    $doc = (new \ReflectionClass($class))->getDocComment();

                    if (is_string($doc) && str_contains($doc, '@internal')) {
                        $problems[] = "{$where}: {$class} is @internal";
                    }
                }
            }
        }

        self::assertSame([], $problems);
    }
}
