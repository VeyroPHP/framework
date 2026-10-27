<?php

declare(strict_types=1);

namespace VeyroPHP\Package;

/**
 * Files packages offer to copy into the application ("php veyro vendor:publish"),
 * for example a config file the application may edit.
 */
final class Publishables
{
    /** @var list<array{provider: string, tag: string, source: string, target: string}> */
    private array $items = [];

    /**
     * @param array<string, string> $paths absolute source file => target path relative to the application
     */
    public function add(string $provider, string $tag, array $paths): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $tag) !== 1) {
            throw new PackageException(sprintf('Invalid publish tag "%s".', $tag));
        }

        foreach ($paths as $source => $target) {
            $this->items[] = ['provider' => $provider, 'tag' => $tag, 'source' => $source, 'target' => self::target($target)];
        }
    }

    /**
     * @return list<array{provider: string, tag: string, source: string, target: string}>
     */
    public function all(?string $tag = null, ?string $provider = null): array
    {
        return array_values(array_filter(
            $this->items,
            static fn(array $item): bool => ($tag === null || $item['tag'] === $tag) && ($provider === null || $item['provider'] === $provider),
        ));
    }

    /**
     * Targets stay inside the application: relative, no "..", no backslashes.
     */
    private static function target(string $target): string
    {
        $target = trim($target);

        if ($target === '' || str_starts_with($target, '/') || str_contains($target, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $target) === 1 || preg_match('/^[A-Za-z]:/', $target) === 1) {
            throw new PackageException(sprintf('Publish target "%s" must be a relative path inside the application.', $target));
        }

        return $target;
    }
}
