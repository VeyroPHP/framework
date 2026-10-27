<?php

declare(strict_types=1);

// Small classes used by the container tests. Loaded through the autoload-dev
// classmap, which allows several classes in one file.

namespace VeyroPHP\Tests\Fixtures\Container;

use Psr\Container\ContainerInterface;
use RuntimeException;

interface LoggerContract {}

final class FileLogger implements LoggerContract {}

final class NullLogger implements LoggerContract {}

final class NoConstructor {}

final class NeedsLogger
{
    public function __construct(public readonly LoggerContract $logger) {}
}

final class NeedsConcrete
{
    public function __construct(public readonly NoConstructor $dependency) {}
}

final class NeedsNested
{
    public function __construct(public readonly NeedsConcrete $inner) {}
}

final class WithDefaults
{
    public function __construct(
        public readonly NoConstructor $dependency,
        public readonly int $retries = 3,
        public readonly string $name = 'default',
    ) {}
}

final class NeedsScalar
{
    public function __construct(public readonly int $port) {}
}

final class Untyped
{
    public readonly mixed $value;

    /** @phpstan-ignore missingType.parameter (fixture for an untyped parameter) */
    public function __construct($untyped)
    {
        $this->value = $untyped;
    }
}

final class OptionalLogger
{
    public function __construct(public readonly ?LoggerContract $logger = null) {}
}

final class NeedsUnion
{
    public function __construct(public readonly NoConstructor|FileLogger $value) {}
}

final class WithVariadic
{
    /** @var list<LoggerContract> */
    public readonly array $loggers;

    public function __construct(public readonly NoConstructor $dependency, LoggerContract ...$loggers)
    {
        $this->loggers = array_values($loggers);
    }
}

abstract class AbstractService {}

final class PrivateConstructor
{
    private function __construct() {}
}

final class CycleA
{
    public function __construct(public readonly CycleB $b) {}
}

final class CycleB
{
    public function __construct(public readonly CycleC $c) {}
}

final class CycleC
{
    public function __construct(public readonly CycleA $a) {}
}

final class SelfCycle
{
    public function __construct(public readonly self $self) {}
}

final class NeedsContainer
{
    public function __construct(public readonly ContainerInterface $container) {}
}

final class RequestContext {}

final class NeedsRequestContext
{
    public function __construct(public readonly RequestContext $context) {}
}

final class ThrowingConstructor
{
    public function __construct()
    {
        throw new RuntimeException('constructor failed');
    }
}

final class NeedsThrowing
{
    public function __construct(public readonly ThrowingConstructor $dependency) {}
}
