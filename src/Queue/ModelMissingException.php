<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

/**
 * A model the job was queued with was deleted before the job ran. The job
 * fails at once; retrying would not bring the model back.
 */
final class ModelMissingException extends UnrunnableJobException {}
