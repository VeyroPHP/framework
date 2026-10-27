<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Rbac\Console;

use VeyroPHP\Auth\Rbac\Rbac;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;

#[AsCommand('rbac:seed', 'Create the roles and permissions from config/rbac.php (never deletes)')]
final class RbacSeedCommand extends Command
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly Config $config,
    ) {}

    public function execute(Input $input, Output $output): int
    {
        $this->rbac->seed($this->config->array('rbac.seed')); // the shape is checked by seed()

        $output->success('Roles and permissions from config/rbac.php are in place.');

        return self::SUCCESS;
    }
}
