<?php

declare(strict_types=1);

namespace VeyroPHP\Orm;

use VeyroPHP\Orm\Exception\OrmException;

/**
 * Definition of a relation between two models, returned from Model::relations().
 *
 * Relations are only ever loaded explicitly (with() or Orm::load()); see Orm.
 */
final class Relation
{
    public const BELONGS_TO = 'belongsTo';
    public const HAS_ONE = 'hasOne';
    public const HAS_MANY = 'hasMany';
    public const BELONGS_TO_MANY = 'belongsToMany';
    public const HAS_MANY_THROUGH = 'hasManyThrough';
    public const MORPH_ONE = 'morphOne';
    public const MORPH_MANY = 'morphMany';
    public const MORPH_TO = 'morphTo';

    /** Identifier names; a morph name becomes the columns "{name}_type" and "{name}_id". */
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** Values stored in a "{name}_type" column: short aliases, or class names of existing data. */
    private const MORPH_ALIAS = '/^[A-Za-z_][A-Za-z0-9_.\\\\-]{0,254}$/D';

    /**
     * @param class-string<Model> $related
     * @param class-string<Model>|null $through
     * @param array<string, class-string<Model>> $morphMap
     */
    private function __construct(
        public readonly string $type,
        public readonly string $related,
        public readonly string $foreignKey,
        public readonly string $ownerKey,
        public readonly ?string $pivotTable = null,
        public readonly ?string $relatedPivotKey = null,
        public readonly ?string $through = null,
        public readonly ?string $throughKey = null,
        public readonly ?string $secondKey = null,
        public readonly ?string $morphType = null,
        public readonly ?string $morphAlias = null,
        public readonly array $morphMap = [],
        public readonly ?string $parentKey = null,
    ) {}

    /**
     * This model holds the foreign key: posts.user_id → users.id.
     *
     * @param class-string<Model> $related
     */
    public static function belongsTo(string $related, string $foreignKey, string $ownerKey = 'id'): self
    {
        return new self(self::BELONGS_TO, $related, $foreignKey, $ownerKey);
    }

    /**
     * The related model holds the foreign key; at most one row.
     *
     * @param class-string<Model> $related
     */
    public static function hasOne(string $related, string $foreignKey, string $localKey = 'id'): self
    {
        return new self(self::HAS_ONE, $related, $foreignKey, $localKey);
    }

    /**
     * The related model holds the foreign key: users.id ← posts.user_id.
     *
     * @param class-string<Model> $related
     */
    public static function hasMany(string $related, string $foreignKey, string $localKey = 'id'): self
    {
        return new self(self::HAS_MANY, $related, $foreignKey, $localKey);
    }

    /**
     * Many-to-many through a pivot table: users ↔ role_user ↔ roles.
     *
     * The pivot columns hold each model's primary key (PRIMARY_KEY) unless other columns
     * are named: $parentKey on this model, $relatedKey on the related one. The related key
     * is stored as $ownerKey; $parentKey stays null for "this model's primary key", which
     * is resolved when the relation is used (DB-4).
     *
     * @param class-string<Model> $related
     * @param string $foreignPivotKey pivot column pointing to this model (role_user.user_id)
     * @param string $relatedPivotKey pivot column pointing to the related model (role_user.role_id)
     */
    public static function belongsToMany(string $related, string $pivotTable, string $foreignPivotKey, string $relatedPivotKey, ?string $parentKey = null, ?string $relatedKey = null): self
    {
        return new self(self::BELONGS_TO_MANY, $related, $foreignPivotKey, $relatedKey ?? $related::primaryKey(), $pivotTable, $relatedPivotKey, parentKey: $parentKey);
    }

    /**
     * Rows two steps away, loaded with one join: countries.id ← authors.country_id,
     * authors.id ← stories.author_id. Soft-deleted or scoped-out intermediate rows
     * hide their related rows.
     *
     * @param class-string<Model> $related the model at the end (Story)
     * @param class-string<Model> $through the model in between (Author)
     * @param string $firstKey column on the intermediate table pointing to this model (authors.country_id)
     * @param string $secondKey column on the related table pointing to the intermediate model (stories.author_id)
     */
    public static function hasManyThrough(string $related, string $through, string $firstKey, string $secondKey, string $localKey = 'id', string $throughKey = 'id'): self
    {
        return new self(self::HAS_MANY_THROUGH, $related, $firstKey, $localKey, through: $through, throughKey: $throughKey, secondKey: $secondKey);
    }

    /**
     * The related model points here with "{name}_type" = $alias and "{name}_id" = this key:
     * remarks.remarkable_type = 'photo', remarks.remarkable_id = photos.id.
     *
     * @param class-string<Model> $related
     */
    public static function morphMany(string $related, string $name, string $alias, string $localKey = 'id'): self
    {
        return self::morph(self::MORPH_MANY, $related, $name, $alias, $localKey);
    }

    /**
     * Like morphMany(), but at most one related row.
     *
     * @param class-string<Model> $related
     */
    public static function morphOne(string $related, string $name, string $alias, string $localKey = 'id'): self
    {
        return self::morph(self::MORPH_ONE, $related, $name, $alias, $localKey);
    }

    /**
     * This model points to one of several models: "{name}_type" holds an alias from
     * $map, "{name}_id" the key. Only classes in $map are ever loaded; a type that is
     * not in the map loads null, so a stored value can never choose a class.
     *
     * @param array<string, string> $map alias => model class; checked here, because the
     *        stored type selects from it
     */
    public static function morphTo(string $name, array $map): self
    {
        self::assertName($name);

        if ($map === []) {
            throw OrmException::invalidRelation(sprintf('morphTo("%s") needs at least one alias => model class.', $name));
        }

        $checked = [];

        foreach ($map as $alias => $class) {
            self::assertAlias((string) $alias);

            if (!is_subclass_of($class, Model::class)) {
                throw OrmException::invalidRelation(sprintf('morphTo("%s"): "%s" must map to a model class.', $name, $alias));
            }

            $checked[(string) $alias] = $class;
        }

        return new self(self::MORPH_TO, Model::class, $name . '_id', 'id', morphType: $name . '_type', morphMap: $checked);
    }

    public function isToMany(): bool
    {
        return in_array($this->type, [self::HAS_MANY, self::BELONGS_TO_MANY, self::HAS_MANY_THROUGH, self::MORPH_MANY], true);
    }

    /**
     * @param class-string<Model> $related
     */
    private static function morph(string $type, string $related, string $name, string $alias, string $localKey): self
    {
        self::assertName($name);
        self::assertAlias($alias);

        return new self($type, $related, $name . '_id', $localKey, morphType: $name . '_type', morphAlias: $alias);
    }

    private static function assertName(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw OrmException::invalidRelation(sprintf('Morph name "%s" must be letters, digits and "_": it names the columns {name}_type and {name}_id.', $name));
        }
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match(self::MORPH_ALIAS, $alias) !== 1) {
            throw OrmException::invalidRelation(sprintf('Morph alias "%s" must start with a letter and contain only letters, digits, "_", ".", "-" or "\\" (at most 255).', $alias));
        }
    }
}
