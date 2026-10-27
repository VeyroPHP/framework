<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;

#[AsCommand('make:model', 'Create an ORM model class in app/Models')]
final class MakeModelCommand extends Command
{
    public function __construct(
        private readonly Generator $generator,
        private readonly MakeMigrationCommand $migration,
    ) {}

    public function definition(): array
    {
        return [
            Argument::required('name', 'Class name, e.g. Post or Blog/Post'),
            Option::value('table', 'Table name (default: snake_case plural of the class)'),
            Option::flag('migration', 'Also create a create_<table>_table migration'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        [$namespace, $class, $path] = Generator::target((string) $input->argument('name'), 'App\\Models', 'app/Models');
        $table = $input->option('table') ?? Generator::tableName($class);

        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new UsageException(sprintf('Invalid table name "%s"; use lowercase letters, digits and "_".', $table));
        }

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use VeyroPHP\\Orm\\Model;

            final class {$class} extends Model
            {
                protected const TABLE = '{$table}';

                // ULID column used in URLs instead of the id; filled on insert.
                // Remove this line if the table has no public_id column.
                protected const PUBLIC_ID = 'public_id';

                // Attributes that fill() / Orm::create() may set from request data.
                protected const FILLABLE = [];

                // Attributes never included in toArray() or JSON.
                protected const HIDDEN = [];
            }

            PHP;

        $output->success('Created ' . $this->generator->write($path, $contents));

        if ($input->flag('migration')) {
            $output->success('Created ' . $this->migration->create('create_' . $table . '_table'));
        }

        return self::SUCCESS;
    }
}
