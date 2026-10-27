<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Console;

use VeyroPHP\Auth\Passwords\PasswordBroker;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('auth:clear-resets', 'Delete expired password reset tokens')]
final class ClearResetsCommand extends Command
{
    public function __construct(private readonly PasswordBroker $broker) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $output->success(sprintf('Deleted %d expired password reset tokens.', $this->broker->deleteExpired()));

        return self::SUCCESS;
    }
}
