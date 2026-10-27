<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Schema;

use VeyroPHP\Database\Exception\DatabaseException;

/**
 * Describes a table to create or the changes to make to an existing table.
 *
 * Column types map to portable SQL types per database; see docs/database/migrations.md.
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array{type: string, columns: list<string>, name: string}> */
    private array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $droppedColumns = [];

    /** @var list<array{from: string, to: string}> */
    private array $renamedColumns = [];

    /** @var list<string> */
    private array $droppedIndexes = [];

    public function __construct(
        public readonly string $table,
        public readonly bool $creating,
    ) {}

    // Columns

    /**
     * Auto-incrementing big integer primary key.
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        if (!$this->creating) {
            throw DatabaseException::invalidQuery('id() can only be used when creating a table.');
        }

        $column = $this->addColumn($name, 'id');
        $column->autoIncrement = true;
        $column->primary = true;
        $column->unsigned = true;

        return $column;
    }

    /**
     * 26-character ULID column, by default "public_id". It has no index by itself:
     * chain ->unique() for public ids that are looked up and must not repeat.
     */
    public function ulid(string $name = 'public_id'): ColumnDefinition
    {
        return $this->addColumn($name, 'char', ['length' => 26]);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        if ($length < 1 || $length > 16383) {
            throw DatabaseException::invalidQuery(sprintf('String length for "%s" must be between 1 and 16383.', $name));
        }

        return $this->addColumn($name, 'string', ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'text');
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer');
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigInteger');
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'boolean');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        if ($precision < 1 || $precision > 65 || $scale < 0 || $scale > $precision) {
            throw DatabaseException::invalidQuery(sprintf('Invalid decimal(%d, %d) for "%s".', $precision, $scale, $name));
        }

        return $this->addColumn($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'double');
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'date');
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'dateTime');
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'json');
    }

    /**
     * Fixed-length text, e.g. country codes.
     */
    public function char(string $name, int $length = 255): ColumnDefinition
    {
        if ($length < 1 || $length > 255) {
            throw DatabaseException::invalidQuery(sprintf('Char length for "%s" must be between 1 and 255.', $name));
        }

        return $this->addColumn($name, 'char', ['length' => $length]);
    }

    /**
     * Up to 16 MB on MySQL; the same as text() on PostgreSQL and SQLite.
     */
    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'mediumText');
    }

    /**
     * Up to 4 GB on MySQL; the same as text() on PostgreSQL and SQLite.
     */
    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'longText');
    }

    /**
     * MySQL tinyint; smallint on PostgreSQL, which has no one-byte integer.
     */
    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'tinyInteger');
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'smallInteger');
    }

    /**
     * The unsigned* types are unsigned on MySQL only; PostgreSQL and SQLite have no unsigned integers.
     */
    public function unsignedTinyInteger(string $name): ColumnDefinition
    {
        return $this->tinyInteger($name)->unsigned();
    }

    public function unsignedSmallInteger(string $name): ColumnDefinition
    {
        return $this->smallInteger($name)->unsigned();
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    /**
     * Single-precision floating point; use decimal() for money.
     */
    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'float');
    }

    /**
     * The same type as dateTime(): no time-zone conversion and no year-2038
     * limit (MySQL's TIMESTAMP has both, and MariaDB may add ON UPDATE to it).
     */
    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'timestamp');
    }

    /**
     * A string column that only accepts the given values, enforced by a CHECK
     * constraint on every database (MySQL 8.0.16+ and MariaDB 10.2+ enforce CHECK).
     *
     * @param list<string> $allowed
     */
    public function enum(string $name, array $allowed): ColumnDefinition
    {
        if ($allowed === []) {
            throw DatabaseException::invalidQuery(sprintf('Enum "%s" needs at least one allowed value.', $name));
        }

        foreach ($allowed as $value) {
            if ($value === '' || strlen($value) > 255 || str_contains($value, "\0")) {
                throw DatabaseException::invalidQuery(sprintf('Enum "%s" values must be non-empty strings of at most 255 bytes.', $name));
            }
        }

        $column = $this->addColumn($name, 'enum', ['length' => 255]);
        $column->allowed = array_values(array_unique($allowed));

        return $column;
    }

    /**
     * Nullable "remember_token" (100 characters), as used by "remember me" logins.
     */
    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Nullable "deleted_at" date-time column. The ORM does not filter it:
     * queries must add whereNull('deleted_at') themselves.
     */
    public function softDeletes(string $name = 'deleted_at'): ColumnDefinition
    {
        return $this->dateTime($name)->nullable();
    }

    /**
     * Unsigned big integer for a foreign key; chain ->constrained('users').
     */
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigInteger')->unsigned();
    }

    /**
     * Nullable created_at and updated_at date-time columns.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    // Indexes and constraints

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex('index', $columns, $name);
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex('unique', $columns, $name);
    }

    /**
     * Full-text index for Builder::whereFullText() over the same columns in the same
     * order: FULLTEXT on MySQL/MariaDB, a GIN index on PostgreSQL. SQLite is refused.
     *
     * @param string|list<string> $columns
     */
    public function fullText(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex('fulltext', $columns, $name);
    }

    /**
     * Primary key over one or more columns (for tables without id()). SQLite
     * can only set it when the table is created.
     *
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex('primary', $columns, $name);
    }

    public function foreign(string $column, ?string $name = null): ForeignKeyDefinition
    {
        $foreign = new ForeignKeyDefinition($column, $name ?? $this->indexName('foreign', [$column]));
        $this->foreignKeys[] = $foreign;

        return $foreign;
    }

    // Changes to existing tables

    /**
     * @param string|list<string> ...$names dropColumn('a', 'b') or dropColumn(['a', 'b'])
     */
    public function dropColumn(string|array ...$names): self
    {
        $this->assertAltering('dropColumn');

        foreach ($names as $name) {
            foreach (is_array($name) ? $name : [$name] as $column) {
                $this->droppedColumns[] = $column;
            }
        }

        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        $this->assertAltering('renameColumn');
        $this->renamedColumns[] = ['from' => $from, 'to' => $to];

        return $this;
    }

    public function dropIndex(string $name): self
    {
        $this->assertAltering('dropIndex');
        $this->droppedIndexes[] = $name;

        return $this;
    }

    // Read access for grammars

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<array{type: string, columns: list<string>, name: string}>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(): array
    {
        foreach ($this->foreignKeys as $foreign) {
            if ($foreign->referencedTable === '') {
                throw DatabaseException::invalidQuery(sprintf('Foreign key on "%s.%s" needs ->on(table).', $this->table, $foreign->column));
            }
        }

        return $this->foreignKeys;
    }

    /**
     * @return list<string>
     */
    public function droppedColumns(): array
    {
        return $this->droppedColumns;
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function renamedColumns(): array
    {
        return $this->renamedColumns;
    }

    /**
     * @return list<string>
     */
    public function droppedIndexes(): array
    {
        return $this->droppedIndexes;
    }

    /**
     * Index names: "{table}_{columns}_{type}", shortened with a hash above 60 characters
     * so they fit every database's identifier limit.
     *
     * @param list<string> $columns
     */
    public function indexName(string $type, array $columns): string
    {
        $name = strtolower($this->table . '_' . implode('_', $columns) . '_' . $type);
        $name = preg_replace('/[^a-z0-9_]/', '_', $name) ?? $name;

        return strlen($name) > 60 ? substr($name, 0, 51) . '_' . substr(md5($name), 0, 8) : $name;
    }

    /**
     * @param array<string, int> $options
     */
    private function addColumn(string $name, string $type, array $options = []): ColumnDefinition
    {
        foreach ($this->columns as $existing) {
            if ($existing->name === $name) {
                throw DatabaseException::invalidQuery(sprintf('Column "%s" is defined twice for table "%s".', $name, $this->table));
            }
        }

        $column = new ColumnDefinition($this, $name, $type, $options);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @param string|list<string> $columns
     */
    private function addIndex(string $type, string|array $columns, ?string $name): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;

        if ($columns === []) {
            throw DatabaseException::invalidQuery('An index needs at least one column.');
        }

        $this->indexes[] = ['type' => $type, 'columns' => $columns, 'name' => $name ?? $this->indexName($type, $columns)];

        return $this;
    }

    private function assertAltering(string $method): void
    {
        if ($this->creating) {
            throw DatabaseException::invalidQuery(sprintf('%s() can only be used when changing an existing table.', $method));
        }
    }
}
