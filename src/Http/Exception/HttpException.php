<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Exception;

use RuntimeException;
use Throwable;
use VeyroPHP\Exception\HasHttpStatus;

/**
 * An exception that maps to a specific HTTP status.
 *
 * The message is shown to the client, so it must not contain internal details.
 * When no message is given, the standard reason phrase is used.
 */
class HttpException extends RuntimeException implements HasHttpStatus
{
    private const REASONS = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        419 => 'Page Expired',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * @param array<string, string> $headers headers added to the error response
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException(sprintf('HTTP exception status must be 4xx or 5xx, %d given.', $statusCode));
        }

        parent::__construct($message !== '' ? $message : self::reasonPhrase($statusCode), 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function reasonPhrase(int $statusCode): string
    {
        return self::REASONS[$statusCode] ?? ($statusCode >= 500 ? 'Server Error' : 'Client Error');
    }
}
