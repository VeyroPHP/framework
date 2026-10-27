<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Seeding;

use Closure;
use Psr\Container\ContainerInterface;

/**
 * Creates seeders through the container and runs them, including the seeders
 * they call. Each seeder runs at most once per run(), so two seeders calling
 * the same third one do not insert its data twice.
 */
final class SeederRunner
{
    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @param class-string<Seeder> $seeder
     * @param (Closure(string): void)|null $onSeeding called with each seeder's class before it runs
     *
     * @return list<class-string<Seeder>> the seeders that ran, in order
     */
    public function run(string $seeder, ?Closure $onSeeding = null): array
    {
        $ran = [];
        $runOne = function (string $class) use (&$runOne, &$ran, $onSeeding): void {
            if (in_array($class, $ran, true)) {
                return;
            }

            if (!is_subclass_of($class, Seeder::class)) {
                throw new \InvalidArgumentException(sprintf('%s is not a seeder (it must extend %s).', $class, Seeder::class));
            }

            $instance = $this->container->get($class);
            \assert($instance instanceof Seeder);
            $ran[] = $class;

            if ($onSeeding !== null) {
                $onSeeding($class);
            }

            $instance->setCaller($runOne);
            $instance->run();
        };

        $runOne($seeder);

        return $ran;
    }
}
