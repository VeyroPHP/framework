<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens\Console;

use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Auth\Tokens\ApiTokens;
use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Orm\Orm;

#[AsCommand('token:list', 'List a user\'s API tokens (never the secrets)')]
final class ListCommand extends TokenCommand
{
    public function __construct(Orm $orm, AuthConfig $config, private readonly ApiTokens $tokens)
    {
        parent::__construct($orm, $config);
    }

    public function definition(): array
    {
        return [Argument::required('user', 'The user\'s login identifier, usually the email')];
    }

    public function execute(Input $input, Output $output): int
    {
        $tokens = $this->tokens->tokensFor($this->user((string) $input->argument('user')));

        if ($tokens === []) {
            $output->line('No tokens.');

            return self::SUCCESS;
        }

        $date = static fn(?int $time): string => $time === null ? '-' : gmdate('Y-m-d H:i', $time);
        $rows = [];

        foreach ($tokens as $token) {
            $rows[] = [(string) $token->id, $token->name, implode(',', $token->abilities), $date($token->createdAt), $date($token->lastUsedAt), $date($token->expiresAt)];
        }

        $output->table(['ID', 'Name', 'Abilities', 'Created (UTC)', 'Last used', 'Expires'], $rows);

        return self::SUCCESS;
    }
}
