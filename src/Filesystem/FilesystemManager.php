<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use Closure;

/**
 * The configured disks, created on first use.
 *
 *     $storage->disk()->put('reports/today.csv', $csv);      // the default disk
 *     $storage->disk('public')->url('avatars/7.png');
 */
final class FilesystemManager
{
    /** @var array<string, Disk> */
    private array $disks = [];

    /**
     * @param Closure(string): Disk $factory creates a disk by name
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly string $default,
    ) {}

    public function disk(?string $name = null): Disk
    {
        $name ??= $this->default;

        return $this->disks[$name] ??= ($this->factory)($name);
    }
}
