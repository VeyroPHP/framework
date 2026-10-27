<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Events;

use VeyroPHP\Orm\Model;

/**
 * Before a model is deleted: soft-deleted ($force false) or removed ($force true).
 * Throw from a listener to stop the delete.
 */
final class ModelDeleting
{
    public function __construct(
        public readonly Model $model,
        public readonly bool $force,
    ) {}
}
