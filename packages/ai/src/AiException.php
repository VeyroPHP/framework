<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

use RuntimeException;
use Throwable;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * An AI call failed, was refused, or is misconfigured.
 *
 * Messages never contain the API key or the prompt.
 */
class AiException extends RuntimeException implements VeyroPHPException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        private readonly bool $refusal = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Trying again later may succeed (rate limit, overloaded provider, network error).
     * The provider client has already retried a few times before this is thrown.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * The model declined to answer; retrying the same request will not help.
     */
    public function isRefusal(): bool
    {
        return $this->refusal;
    }

    public static function refused(string $model): self
    {
        return new self(sprintf('The model "%s" declined to answer this request.', $model), refusal: true);
    }

    public static function missingPackage(string $driver, string $command): self
    {
        return new self(sprintf('The "%s" AI driver needs extra packages: run "%s".', $driver, $command));
    }
}
