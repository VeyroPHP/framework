<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\ColumnDefinition;
use VeyroPHP\Database\Schema\ForeignKeyDefinition;
use VeyroPHP\Orm\Relation;

/**
 * What VeyroPHP supports, read from its own classes by reflection, so the
 * analyzer's verdicts follow the framework as it changes.
 */
final class Capabilities
{
    /**
     * Schema builder methods (Blueprint and the column/foreign-key definitions), lower-case.
     *
     * @return list<string>
     */
    public static function schemaMethods(): array
    {
        return array_values(array_unique([...self::tableMethods(), ...self::columnModifiers()]));
    }

    /**
     * Methods called on $table (Blueprint), lower-case.
     *
     * @return list<string>
     */
    public static function tableMethods(): array
    {
        return self::methods([Blueprint::class]);
    }

    /**
     * Methods chained on a column or foreign key ($table->string('a')->nullable()), lower-case.
     *
     * @return list<string>
     */
    public static function columnModifiers(): array
    {
        return self::methods([ColumnDefinition::class, ForeignKeyDefinition::class]);
    }

    /**
     * @param list<class-string> $classes
     * @return list<string>
     */
    private static function methods(array $classes): array
    {
        $methods = [];

        foreach ($classes as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isStatic() && !str_starts_with($method->getName(), '__')) {
                    $methods[] = strtolower($method->getName());
                }
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * Relation types of the ORM as written in code, e.g. hasMany.
     *
     * @return list<string>
     */
    public static function relationTypes(): array
    {
        $types = [];

        foreach ((new \ReflectionClass(Relation::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($method->isPublic()) {
                $types[] = $method->getName();
            }
        }

        return $types;
    }
}
