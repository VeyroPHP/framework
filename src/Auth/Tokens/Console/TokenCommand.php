<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens\Console;

use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\UsageException;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;

/**
 * Shared user lookup for the token commands.
 *
 * @internal
 */
abstract class TokenCommand extends Command
{
    public function __construct(
        protected readonly Orm $orm,
        protected readonly AuthConfig $config,
    ) {}

    protected function user(string $identifier): Model
    {
        return $this->orm->query($this->config->model)->where($this->config->identifier, $identifier)->first()
            ?? throw new UsageException(sprintf('No user with %s "%s".', $this->config->identifier, $identifier));
    }
}
