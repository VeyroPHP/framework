<?php

declare(strict_types=1);

namespace VeyroPHP\Console\Make;

use Closure;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;

#[AsCommand('make:migration', 'Create a migration file in database/migrations')]
final class MakeMigrationCommand extends Command
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock for tests; defaults to time()
     */
    public function __construct(
        private readonly Generator $generator,
        private readonly Config $config,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function definition(): array
    {
        return [Argument::required('name', 'snake_case description, e.g. create_posts_table or add_slug_to_posts')];
    }

    public function help(): string
    {
        return 'A name like create_<table>_table creates a migration that creates and drops that table.';
    }

    public function execute(Input $input, Output $output): int
    {
        $output->success('Created ' . $this->create((string) $input->argument('name')));

        return self::SUCCESS;
    }

    /**
     * @return string the written path relative to the base path
     *
     * @throws UsageException for an invalid or already used name
     */
    public function create(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
            throw new UsageException(sprintf('Invalid migration name "%s"; use snake_case such as create_posts_table.', $name));
        }

        $directory = $this->config->get('database.migrations', 'database/migrations');
        $directory = is_string($directory) ? rtrim($directory, '/') : 'database/migrations';

        foreach (glob($this->generator->path($directory) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $existing) {
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_' . $name . '\.php$/D', basename($existing)) === 1) {
                throw new UsageException(sprintf('A migration named %s already exists (%s).', $name, basename($existing)));
            }
        }

        $table = preg_match('/^create_([a-z0-9_]+)_table$/D', $name, $match) === 1 ? $match[1] : null;

        $up = $table === null
            ? "        // \$schema->table('posts', static function (Blueprint \$table): void { ... });"
            : "        \$schema->create('{$table}', static function (Blueprint \$table): void {\n"
                . "            \$table->id();\n"
                . "            \$table->ulid()->unique();\n"
                . "            \$table->timestamps();\n"
                . '        });';
        $down = $table === null ? '        //' : "        \$schema->drop('{$table}');";

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            use VeyroPHP\\Database\\Migration\\Migration;
            use VeyroPHP\\Database\\Schema\\Blueprint;
            use VeyroPHP\\Database\\Schema\\Schema;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP;

        return $this->generator->write($directory . '/' . gmdate('Y_m_d_His', ($this->clock)()) . '_' . $name . '.php', $contents);
    }
}
