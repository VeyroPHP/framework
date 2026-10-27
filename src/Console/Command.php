<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * Base class for console commands. Name it with #[AsCommand]; dependencies come
 * through the constructor (autowired by the container when the command runs).
 *
 *     #[AsCommand('cache:clear', 'Remove every cached item')]
 *     final class CacheClearCommand extends Command
 *     {
 *         public function __construct(private readonly CacheManager $cache) {}
 *
 *         public function execute(Input $input, Output $output): int
 *         {
 *             $this->cache->store()->clear();
 *             $output->success('Cache cleared.');
 *
 *             return self::SUCCESS;
 *         }
 *     }
 */
abstract class Command
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    public const USAGE = 2;

    /**
     * Arguments and options, in order.
     *
     * @return list<Argument|Option>
     */
    public function definition(): array
    {
        return [];
    }

    /**
     * Longer help text shown by "help <command>", after the description.
     */
    public function help(): string
    {
        return '';
    }

    /**
     * @return int exit code: self::SUCCESS, self::FAILURE or another value 0-255
     *
     * @throws UsageException for wrong usage the parser cannot detect
     */
    abstract public function execute(Input $input, Output $output): int;
}
