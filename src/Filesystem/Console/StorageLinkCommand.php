<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

/**
 * Makes the root of the "public" disk (filesystems.disks.public.root, default
 * storage/app/public) reachable as public/storage, so its files have URLs. On
 * Windows a directory junction is used, which needs no administrator rights.
 */
#[AsCommand('storage:link', 'Link public/storage to the public disk (default storage/app/public)')]
final class StorageLinkCommand extends Command
{
    public function __construct(
        private readonly Application $app,
        private readonly ?Config $config = null,
    ) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        // The root of the "public" disk (MISC-15), resolved like FilesystemServiceProvider does.
        $disk = $this->config?->get('filesystems.disks.public');
        $disk = is_array($disk) ? $disk : ['driver' => 'local'];

        if (($disk['driver'] ?? null) !== 'local') {
            $output->error('The "public" disk is not on a local disk (filesystems.disks.public.driver), so there is nothing to link.');

            return self::FAILURE;
        }

        $root = is_string($disk['root'] ?? null) && $disk['root'] !== '' ? $disk['root'] : 'storage/app/public';
        $target = $this->app->basePath($root);
        $link = $this->app->basePath('public/storage');

        if (file_exists($link) || is_link($link)) {
            $output->line('public/storage already exists.');

            return self::SUCCESS;
        }

        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            $output->error("Could not create {$root}.");

            return self::FAILURE;
        }

        $ok = PHP_OS_FAMILY === 'Windows' ? self::junction($target, $link) : @symlink($target, $link);

        if (!$ok) {
            $output->error('Could not create the link public/storage.');

            return self::FAILURE;
        }

        $output->success("public/storage now points to {$root}.");

        return self::SUCCESS;
    }

    private static function junction(string $target, string $link): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }

        $command = sprintf('mklink /J %s %s', escapeshellarg(str_replace('/', '\\', $link)), escapeshellarg(str_replace('/', '\\', $target)));
        exec('cmd /c ' . $command . ' 2>NUL', $out, $code);

        return $code === 0 && is_dir($link);
    }
}
