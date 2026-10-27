<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

use RuntimeException;
use VeyroPHP\Exception\HasErrorDetails;
use VeyroPHP\Exception\HasHttpStatus;

/**
 * Invalid input. Rendered as 422 with the field errors.
 */
final class ValidationException extends RuntimeException implements HasHttpStatus, HasErrorDetails
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The submitted data failed validation.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    public function getDetails(): array
    {
        return ['errors' => $this->errors];
    }
}
