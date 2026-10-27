<?php

declare(strict_types=1);

namespace VeyroPHP\Validation\Rules;

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Validation\Rule;

/**
 * The value must not exist yet in $table.$column. Pass $ignoreId when
 * updating, so the record being edited does not conflict with itself.
 *
 * This is a usability check, not a guarantee: keep a unique index in the
 * database, because two requests can pass validation at the same time.
 */
final class Unique implements Rule
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $column,
        private readonly int|string|null $ignoreId = null,
        private readonly string $idColumn = 'id',
    ) {}

    public function validate(string $field, mixed $value, array $data): ?string
    {
        if (!is_scalar($value)) {
            return 'The :field field is invalid.';
        }

        $query = $this->connection->table($this->table)->where($this->column, $value);

        if ($this->ignoreId !== null) {
            $query->where($this->idColumn, '!=', $this->ignoreId);
        }

        return $query->exists() ? 'The :field has already been taken.' : null;
    }
}
