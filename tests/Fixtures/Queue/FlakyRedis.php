<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Queue;

use Redis;
use RedisException;

/**
 * A Redis connection whose writes fail while $failing is true, like a connection
 * that drops in the middle of an operation. Loaded only when ext-redis is.
 */
final class FlakyRedis extends Redis
{
    public bool $failing = false;

    /**
     * @param array<mixed>|float $score_or_options
     */
    public function zAdd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems): Redis|int|float|false
    {
        if ($this->failing) {
            throw new RedisException('connection lost');
        }

        return parent::zAdd($key, $score_or_options, ...$more_scores_and_mems);
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        if ($this->failing) {
            throw new RedisException('connection lost');
        }

        return parent::eval($script, $args, $num_keys);
    }
}
