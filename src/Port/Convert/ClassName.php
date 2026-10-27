<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

/**
 * A "Foo::class" literal read from source code.
 */
final class ClassName
{
    public function __construct(public readonly string $name) {}

    public function short(): string
    {
        $pos = strrpos($this->name, '\\');

        return $pos === false ? $this->name : substr($this->name, $pos + 1);
    }
}
