<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens\Console;

use VeyroPHP\Auth\Tokens\ApiTokens;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('token:prune', 'Delete expired API tokens')]
final class PruneCommand extends Command
{
    public function __construct(private readonly ApiTokens $tokens) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $output->success(sprintf('Deleted %d expired tokens.', $this->tokens->pruneExpired()));

        return self::SUCCESS;
    }
}
