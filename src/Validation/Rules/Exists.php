<?php

declare(strict_types=1);

namespace VeyroPHP\Validation\Rules;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Validation\Rule;

/**
 * The value must exist in $table.$column (for example a selected category id).
 */
final class Exists implements Rule
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $column = 'id',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?string
    {
        if (!is_scalar($value)) {
            return 'The selected :field is invalid.';
        }

        return $this->connection->table($this->table)->where($this->column, $value)->exists() ? null : 'The selected :field is invalid.';
    }
}
