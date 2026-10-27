<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Events;

use VeyroPHP\Orm\Model;

/**
 * After a soft-deleted model was restored.
 */
final class ModelRestored
{
    public function __construct(
        public readonly Model $model,
    ) {}
}
