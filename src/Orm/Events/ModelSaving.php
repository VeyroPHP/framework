<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Events;

use VeyroPHP\Orm\Model;

/**
 * Before a model is inserted or updated, even when nothing changed. A listener may
 * set attributes (they are saved) or throw to stop the save: nothing is written.
 */
final class ModelSaving
{
    public function __construct(
        public readonly Model $model,
        public readonly bool $creating,
    ) {}
}
