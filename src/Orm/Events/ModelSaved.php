<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Events;

use VeyroPHP\Orm\Model;

/**
 * After a model was inserted or updated; not sent when save() had nothing to write.
 * The row is already written: an exception from a listener reaches the caller, and
 * only a surrounding transaction can undo the write.
 */
final class ModelSaved
{
    public function __construct(
        public readonly Model $model,
        public readonly bool $creating,
    ) {}
}
