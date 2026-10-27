<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Console;

use VeyroPHP\Auth\Remember\RememberTokens;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('auth:prune-remember', 'Delete expired "remember me" tokens')]
final class PruneRememberTokensCommand extends Command
{
    public function __construct(private readonly RememberTokens $tokens) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $output->success(sprintf('Deleted %d expired "remember me" tokens.', $this->tokens->prune()));

        return self::SUCCESS;
    }
}
