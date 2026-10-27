<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Exception;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * Programming errors in model definitions or ORM usage.
 */
class OrmException extends LogicException implements VeyroPHPException
{
    public static function unknownRelation(string $model, string $relation): self
    {
        return new self(sprintf('Model %s has no relation "%s". Define it in relations().', $model, $relation));
    }

    public static function unknownCast(string $model, string $attribute, string $cast): self
    {
        return new self(sprintf('Unknown cast "%s" for %s::$%s. Use int, float, bool, string, datetime, date or json.', $cast, $model, $attribute));
    }

    public static function invalidCastValue(string $model, string $attribute, string $cast): self
    {
        return new self(sprintf('Value of %s::$%s cannot be cast to %s.', $model, $attribute, $cast));
    }

    public static function notPersisted(string $model, string $action): self
    {
        return new self(sprintf('Cannot %s: the %s has not been saved yet.', $action, $model));
    }

    public static function relationMismatch(string $relation, string $expected): self
    {
        return new self(sprintf('Relation "%s" expects %s.', $relation, $expected));
    }

    public static function invalidConstant(string $model, string $constant, string $expected): self
    {
        return new self(sprintf('%s::%s must be %s.', $model, $constant, $expected));
    }

    public static function noPublicId(string $model): self
    {
        return new self(sprintf('Model %s has no PUBLIC_ID column configured.', $model));
    }

    public static function invalidRelation(string $message): self
    {
        return new self($message);
    }

    public static function noSoftDeletes(string $model, string $action): self
    {
        return new self(sprintf('Cannot %s: %s does not use soft deletes. Set protected const SOFT_DELETES = \'deleted_at\' on the model.', $action, $model));
    }
}
