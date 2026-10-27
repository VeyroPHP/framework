<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Exception;

/**
 * Thrown when code reads a relation that was not eager loaded. The ORM never
 * loads relations lazily, so a missing ->with() is visible instead of turning
 * into hidden N+1 queries.
 */
final class RelationNotLoadedException extends OrmException
{
    public static function for(string $model, string $relation): self
    {
        return new self(sprintf(
            'Relation "%s" on %s is not loaded. Load it with ->with(\'%s\') on the query, or $orm->load($model, \'%s\').',
            $relation,
            $model,
            $relation,
            $relation,
        ));
    }
}
