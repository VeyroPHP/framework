<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens\Console;

use VeyroPHP\Auth\AuthConfig;
use VeyroPHP\Auth\Tokens\ApiTokens;
use VeyroPHP\Auth\Tokens\TokenException;
use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Orm\Orm;

#[AsCommand('token:create', 'Create an API token for a user (shown once)')]
final class CreateCommand extends TokenCommand
{
    public function __construct(Orm $orm, AuthConfig $config, private readonly ApiTokens $tokens)
    {
        parent::__construct($orm, $config);
    }

    public function definition(): array
    {
        return [
            Argument::required('user', 'The user\'s login identifier, usually the email'),
            Argument::required('name', 'What the token is for, e.g. "Mobile app"'),
            Option::value('abilities', 'Comma-separated abilities, e.g. orders.read,orders.write (default: *)'),
            Option::value('days', 'Expire after this many days (default: never)'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $user = $this->user((string) $input->argument('user'));
        $abilities = array_values(array_filter(array_map('trim', explode(',', $input->option('abilities') ?? '*'))));
        $days = $input->positiveInt('days');

        try {
            $new = $this->tokens->create($user, (string) $input->argument('name'), $abilities, $days === null ? null : time() + $days * 86400);
        } catch (TokenException $e) {
            $output->error($e->getMessage());

            return self::USAGE;
        }

        $output->success(sprintf('Token %d created. Copy it now; it is not shown again:', $new->token->id));
        $output->line($new->plainText);

        return self::SUCCESS;
    }
}
