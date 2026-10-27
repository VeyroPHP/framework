<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Exception;

/**
 * Thrown when fill() receives an attribute that is not in the model's FILLABLE list.
 */
final class MassAssignmentException extends OrmException
{
    /**
     * @param list<string> $attributes
     */
    public static function forAttributes(string $model, array $attributes): self
    {
        return new self(sprintf(
            'Attribute(s) %s of %s are not mass assignable. Add them to FILLABLE, or set them individually with set() or forceFill() in trusted code.',
            implode(', ', array_map(static fn(string $a): string => '"' . $a . '"', $attributes)),
            $model,
        ));
    }
}
