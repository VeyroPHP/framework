<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Exception;

use RuntimeException;
use VeyroPHP\Exception\HasHttpStatus;

/**
 * No record matched. Rendered as 404 by the HTTP layer. The client-facing
 * message is generic; the model class is available through getModel().
 */
final class ModelNotFoundException extends RuntimeException implements HasHttpStatus
{
    public function __construct(private readonly string $model)
    {
        parent::__construct('Not Found');
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
