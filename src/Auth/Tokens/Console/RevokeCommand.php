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

#[AsCommand('token:revoke', 'Revoke one API token of a user, or all of them')]
final class RevokeCommand extends TokenCommand
{
    public function __construct(Orm $orm, AuthConfig $config, private readonly ApiTokens $tokens)
    {
        parent::__construct($orm, $config);
    }

    public function definition(): array
    {
        return [
            Argument::required('user', 'The user\'s login identifier, usually the email'),
            Argument::required('id', 'Token id (see token:list), or "all"'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $user = $this->user((string) $input->argument('user'));
        $id = (string) $input->argument('id');

        if ($id === 'all') {
            $output->success(sprintf('Revoked %d tokens.', $this->tokens->revokeAll($user)));

            return self::SUCCESS;
        }

        if (!ctype_digit($id) || !$this->tokens->revoke($user, (int) $id)) {
            $output->error(sprintf('This user has no token %s.', $id));

            return self::FAILURE;
        }

        $output->success(sprintf('Revoked token %s.', $id));

        return self::SUCCESS;
    }
}
