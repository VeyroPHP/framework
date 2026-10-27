<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Exception;

/**
 * 405 response with the required "Allow" header.
 */
final class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(private readonly array $allowedMethods)
    {
        parent::__construct(405, '', ['Allow' => implode(', ', $allowedMethods)]);
    }

    /**
     * @return list<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
