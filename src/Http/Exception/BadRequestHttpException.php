<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Exception;

use Throwable;

final class BadRequestHttpException extends HttpException
{
    public function __construct(string $message = '', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }
}
