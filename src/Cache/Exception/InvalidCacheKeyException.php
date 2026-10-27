<?php

declare(strict_types=1);

namespace VeyroPHP\Cache\Exception;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;
use VeyroPHP\Exception\VeyroPHPException;

final class InvalidCacheKeyException extends InvalidArgumentException implements PsrInvalidArgumentException, VeyroPHPException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Invalid cache key "%s": keys must be non-empty and must not contain {}()/\\@:', $key));
    }

    public static function invalidTtl(string $type): self
    {
        return new self(sprintf('Cache TTL must be null, an int or a DateInterval, %s given.', $type));
    }

    public static function invalidConfig(string $store, string $reason): self
    {
        return new self(sprintf('Invalid configuration for cache store "%s": %s.', $store, $reason));
    }
}
