<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Events;

use VeyroPHP\Orm\Model;

/**
 * After a model was soft-deleted ($force false) or removed ($force true).
 */
final class ModelDeleted
{
    public function __construct(
        public readonly Model $model,
        public readonly bool $force,
    ) {}
}
