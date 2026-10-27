<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Seeding;

use Closure;

/**
 * Fills the database with data ("php veyro db:seed"). Constructor
 * parameters are injected; call() runs other seeders.
 *
 *     final class DatabaseSeeder extends Seeder
 *     {
 *         public function __construct(private readonly UserFactory $users) {}
 *
 *         public function run(): void
 *         {
 *             $this->call(RoleSeeder::class);
 *             $this->users->count(20)->create();
 *         }
 *     }
 *
 * Seeders may run more than once (for example on every deploy of a demo
 * site); write them so a second run does no harm, or guard them.
 */
abstract class Seeder
{
    /** @var (Closure(class-string<Seeder>): void)|null */
    private ?Closure $caller = null;

    abstract public function run(): void;

    /**
     * @param Closure(class-string<Seeder>): void $caller
     *
     * @internal set by SeederRunner
     */
    final public function setCaller(Closure $caller): void
    {
        $this->caller = $caller;
    }

    /**
     * @param class-string<Seeder> ...$seeders
     */
    protected function call(string ...$seeders): void
    {
        $caller = $this->caller;

        if ($caller === null) {
            throw new \LogicException('Seeders call other seeders only when run by SeederRunner ("php veyro db:seed").');
        }

        foreach ($seeders as $seeder) {
            $caller($seeder);
        }
    }
}
