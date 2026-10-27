<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use JsonSerializable;
use Stringable;
use VeyroPHP\Orm\Exception\MassAssignmentException;
use VeyroPHP\Orm\Exception\OrmException;
use VeyroPHP\Orm\Exception\RelationNotLoadedException;

/**
 * Base class for ORM models. A model holds attribute values and loaded
 * relations; it never talks to the database itself. Use Orm to query and save.
 *
 *     final class User extends Model
 *     {
 *         protected const TABLE = 'users';
 *         protected const PUBLIC_ID = 'public_id';
 *         protected const FILLABLE = ['name', 'email'];
 *         protected const HIDDEN = ['password'];
 *         protected const CASTS = ['email_verified_at' => 'datetime'];
 *
 *         public static function relations(): array
 *         {
 *             return ['posts' => Relation::hasMany(Post::class, 'user_id')];
 *         }
 *     }
 *
 * Casts: int, float, bool, string, datetime ("Y-m-d H:i:s"), date ("Y-m-d"), json.
 *
 * Soft deletes: protected const SOFT_DELETES = 'deleted_at'; queries then skip
 * deleted rows unless they ask for them (ModelQuery::withTrashed(), onlyTrashed()).
 */
abstract class Model implements JsonSerializable
{
    /** Table name; empty means snake_case class name plus "s" (User → users). */
    protected const TABLE = '';

    protected const PRIMARY_KEY = 'id';

    /** Column filled with a ULID on insert, used for URLs; null disables. */
    protected const PUBLIC_ID = null;

    /** @var list<string> attributes fill() may set */
    protected const FILLABLE = [];

    /** @var list<string> attributes never included in toArray()/JSON */
    protected const HIDDEN = [];

    /** @var array<string, string> attribute => cast */
    protected const CASTS = [];

    /** Maintain created_at / updated_at. */
    protected const TIMESTAMPS = true;

    /** Column that marks a row as deleted (for example 'deleted_at'); null deletes rows for real. */
    protected const SOFT_DELETES = null;

    private const CAST_TYPES = ['int', 'float', 'bool', 'string', 'datetime', 'date', 'json'];

    /** @var array<string, mixed> raw values as stored in the database */
    private array $attributes = [];

    /** @var array<string, mixed> raw values as last loaded or saved */
    private array $original = [];

    /** @var array<string, Model|list<Model>|null> */
    private array $relations = [];

    private bool $exists = false;

    final public function __construct() {}

    /**
     * @return array<string, Relation>
     */
    public static function relations(): array
    {
        return [];
    }

    public static function table(): string
    {
        $table = self::stringConstant(static::TABLE, 'TABLE', true);

        if ($table !== '') {
            return $table;
        }

        $short = substr(strrchr('\\' . static::class, '\\') ?: static::class, 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . 's';
    }

    public static function primaryKey(): string
    {
        return self::stringConstant(static::PRIMARY_KEY, 'PRIMARY_KEY', false);
    }

    public static function publicIdColumn(): ?string
    {
        return static::PUBLIC_ID === null ? null : self::stringConstant(static::PUBLIC_ID, 'PUBLIC_ID', false);
    }

    public static function usesTimestamps(): bool
    {
        return is_bool(static::TIMESTAMPS) ? static::TIMESTAMPS : throw OrmException::invalidConstant(static::class, 'TIMESTAMPS', 'a bool');
    }

    public static function softDeleteColumn(): ?string
    {
        return static::SOFT_DELETES === null ? null : self::stringConstant(static::SOFT_DELETES, 'SOFT_DELETES', false);
    }

    /**
     * Scopes added to every query of this model, including find() and relation loading.
     * Lift them per query with ModelQuery::withoutGlobalScopes().
     *
     * @return list<Scope>
     */
    public static function globalScopes(): array
    {
        return [];
    }

    // PHP 8.2 has no typed class constants, so subclasses' overrides are checked here.
    private static function stringConstant(mixed $value, string $name, bool $allowEmpty): string
    {
        if (!is_string($value) || (!$allowEmpty && $value === '')) {
            throw OrmException::invalidConstant(static::class, $name, $allowEmpty ? 'a string' : 'a non-empty string');
        }

        return $value;
    }

    public static function relation(string $name): Relation
    {
        return static::relations()[$name] ?? throw OrmException::unknownRelation(static::class, $name);
    }

    /**
     * @internal creates a model from a database row
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;

        return $model;
    }

    // Attributes

    /**
     * The attribute value with its cast applied, or null when it is not set.
     */
    public function get(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;
        $cast = static::CASTS[$key] ?? null;

        if ($value === null || $cast === null) {
            return $value;
        }

        return match ($type = $this->castType($key, $cast)) {
            'datetime', 'date' => self::toDate($value, $key, $cast),
            'json' => self::decodeJson($value, $key),
            default => self::toScalar($type, $value, $key),
        };
    }

    /**
     * The value exactly as stored, without casts.
     */
    public function raw(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function set(string $key, mixed $value): static
    {
        $cast = static::CASTS[$key] ?? null;
        $this->attributes[$key] = $value === null || $cast === null ? $value : $this->toStorage($key, $cast, $value);

        return $this;
    }

    /**
     * Sets attributes listed in FILLABLE. Any other key throws, so request data
     * cannot set columns such as is_admin by accident.
     *
     * @param array<string, mixed> $attributes
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): static
    {
        $forbidden = array_values(array_diff(array_keys($attributes), static::FILLABLE));

        if ($forbidden !== []) {
            throw MassAssignmentException::forAttributes(static::class, $forbidden);
        }

        return $this->forceFill($attributes);
    }

    /**
     * Sets attributes without the FILLABLE check. Use only with trusted data.
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * @return array<string, mixed> raw values
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string, mixed> raw values that differ from the last load or save
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(): bool
    {
        return $this->getDirty() !== [];
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Whether the model is soft-deleted. It still exists(): its row is only marked.
     */
    public function trashed(): bool
    {
        $column = static::softDeleteColumn();

        return $column !== null && $this->raw($column) !== null;
    }

    public function getKey(): int|string|null
    {
        $key = $this->attributes[static::primaryKey()] ?? null;

        return is_int($key) || is_string($key) ? $key : null;
    }

    public function getPublicId(): ?string
    {
        $column = static::publicIdColumn();
        $value = $column === null ? null : ($this->attributes[$column] ?? null);

        return is_string($value) ? $value : null;
    }

    /**
     * @internal called by Orm after insert or update; with keys, only those columns were written
     */
    public function markPersisted(string ...$keys): void
    {
        $this->exists = true;

        if ($keys === []) {
            $this->original = $this->attributes;

            return;
        }

        foreach ($keys as $key) {
            $this->original[$key] = $this->attributes[$key] ?? null;
        }
    }

    /**
     * @internal called by Orm after delete
     */
    public function markDeleted(): void
    {
        $this->exists = false;
    }

    // Relations

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * @return Model|list<Model>|null
     *
     * @throws RelationNotLoadedException when the relation was not eager loaded
     */
    public function getRelation(string $name): Model|array|null
    {
        static::relation($name);

        if (!$this->relationLoaded($name)) {
            throw RelationNotLoadedException::for(static::class, $name);
        }

        return $this->relations[$name];
    }

    /**
     * @internal called by Orm when loading relations
     *
     * @param Model|list<Model>|null $value
     */
    public function setRelation(string $name, Model|array|null $value): void
    {
        $this->relations[$name] = $value;
    }

    /**
     * @internal called by Orm when a loaded relation becomes stale
     */
    public function unsetRelation(string $name): void
    {
        unset($this->relations[$name]);
    }

    // Output

    /**
     * Attributes (casts applied, HIDDEN removed) and loaded relations. Never runs queries.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $output = [];

        foreach (array_keys($this->attributes) as $key) {
            if (in_array($key, static::HIDDEN, true)) {
                continue;
            }

            $value = $this->get($key);
            $output[$key] = $value instanceof DateTimeInterface
                ? $value->format(($this->castType($key, static::CASTS[$key] ?? '') === 'date') ? 'Y-m-d' : DateTimeInterface::ATOM)
                : $value;
        }

        foreach ($this->relations as $name => $related) {
            $output[$name] = match (true) {
                $related instanceof Model => $related->toArray(),
                is_array($related) => array_map(static fn(Model $model): array => $model->toArray(), $related),
                default => null,
            };
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Attribute or loaded relation. Unloaded relations throw; nothing is queried.
     */
    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->attributes) && array_key_exists($name, static::relations())) {
            return $this->getRelation($name);
        }

        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    private function castType(string $key, string $cast): string
    {
        if (!in_array($cast, self::CAST_TYPES, true)) {
            throw OrmException::unknownCast(static::class, $key, $cast);
        }

        return $cast;
    }

    private function toStorage(string $key, string $cast, mixed $value): mixed
    {
        return match ($type = $this->castType($key, $cast)) {
            'datetime' => self::toDate($value, $key, $cast)->format('Y-m-d H:i:s'),
            'date' => self::toDate($value, $key, $cast)->format('Y-m-d'),
            'json' => self::encodeJson($value, $key),
            default => self::toScalar($type, $value, $key),
        };
    }

    /**
     * The int, float, bool and string casts, the same when reading and when storing (DB-10).
     */
    private static function toScalar(string $cast, mixed $value, string $key): int|float|bool|string
    {
        return match ($cast) {
            'int' => is_numeric($value) ? (int) $value : throw OrmException::invalidCastValue(static::class, $key, $cast),
            'float' => is_numeric($value) ? (float) $value : throw OrmException::invalidCastValue(static::class, $key, $cast),
            'bool' => is_bool($value) ? $value : in_array($value, [1, '1', 't', 'true'], true),
            default => is_scalar($value) || $value instanceof Stringable ? (string) $value : throw OrmException::invalidCastValue(static::class, $key, $cast),
        };
    }

    private static function toDate(mixed $value, string $key, string $cast): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value)) {
            $date = DateTimeImmutable::createFromFormat($cast === 'date' ? '!Y-m-d' : 'Y-m-d H:i:s', $value)
                ?: (strtotime($value) !== false ? new DateTimeImmutable($value) : false);
        } else {
            $date = false;
        }

        if ($date === false) {
            throw OrmException::invalidCastValue(static::class, $key, $cast);
        }

        return $cast === 'date' ? $date->setTime(0, 0) : $date;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeJson(mixed $value, string $key): array
    {
        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $decoded = null;
        }

        return is_array($decoded) ? $decoded : throw OrmException::invalidCastValue(static::class, $key, 'json');
    }

    private static function encodeJson(mixed $value, string $key): string
    {
        if (!is_array($value) && !$value instanceof JsonSerializable) {
            throw OrmException::invalidCastValue(static::class, $key, 'json');
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw OrmException::invalidCastValue(static::class, $key, 'json');
        }
    }
}
