<?php

declare(strict_types=1);

namespace VeyroPHP\Package\Console;

use Psr\Container\ContainerInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Package\Publishables;

/**
 * Copies files that packages offer (config, views, migrations) into the
 * application. Existing files are never overwritten without --force.
 */
#[AsCommand('vendor:publish', 'Copy files offered by packages (config, views, migrations) into the application')]
final class PublishCommand extends Command
{
    public function __construct(
        private readonly Application $app,
        private readonly ContainerInterface $container,
    ) {}

    public function definition(): array
    {
        return [
            Option::value('tag', 'Only files with this tag, e.g. greeting-config'),
            Option::value('provider', 'Only files of this provider class'),
            Option::flag('force', 'Overwrite files that already exist'),
            Option::flag('list', 'Show what can be published, change nothing'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $registry = $this->container->has(Publishables::class) ? $this->container->get(Publishables::class) : null;
        $items = $registry instanceof Publishables ? $registry->all($input->option('tag'), $input->option('provider')) : [];

        if ($items === []) {
            $output->line('Nothing to publish.');

            return self::SUCCESS;
        }

        if ($input->flag('list')) {
            $output->table(['Tag', 'Provider', 'Target'], array_map(static fn(array $i): array => [$i['tag'], $i['provider'], $i['target']], $items));

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($items as $item) {
            $target = $this->app->basePath($item['target']);

            if (!is_file($item['source'])) {
                $output->error(sprintf('Missing source file for %s: %s', $item['target'], $item['source']));
                $failed++;

                continue;
            }

            if (is_file($target) && !$input->flag('force')) {
                $output->line(sprintf('Skipped %s (exists; use --force to overwrite)', $item['target']));

                continue;
            }

            $dir = dirname($target);

            if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || !@copy($item['source'], $target)) {
                $output->error('Could not write ' . $item['target']);
                $failed++;

                continue;
            }

            $output->success('Published ' . $item['target']);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
