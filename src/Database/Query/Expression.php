<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Query;

/**
 * A raw SQL fragment that is inserted into a query without quoting.
 *
 * Never build an Expression from user input. Use bindings for values.
 */
final class Expression
{
    public function __construct(public readonly string $sql) {}
}
