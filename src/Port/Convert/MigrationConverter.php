<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

use VeyroPHP\Port\Capabilities;
use VeyroPHP\Port\Patterns;

/**
 * Converts a Laravel migration into a VeyroPHP migration. The schema builder
 * uses Laravel's method names, so most lines stay as they are; methods
 * VeyroPHP lacks are commented out or removed with a TODO(port) note.
 */
final class MigrationConverter
{
    private const USES = [
        'Illuminate\Database\Migrations\Migration' => 'VeyroPHP\Database\Migration\Migration',
        'Illuminate\Database\Schema\Blueprint' => 'VeyroPHP\Database\Schema\Blueprint',
        'Illuminate\Support\Facades\Schema' => 'VeyroPHP\Database\Schema\Schema',
    ];

    private const SCHEMA_METHODS = ['create', 'table', 'drop', 'dropIfExists', 'rename', 'hasTable', 'hasColumn'];

    /** Laravel's own tables => what VeyroPHP does instead */
    private const FRAMEWORK_TABLES = [
        'jobs' => 'the starter creates VeyroPHP\'s own queue tables (QueueSchema)',
        'failed_jobs' => 'the starter creates VeyroPHP\'s own queue tables (QueueSchema)',
        'password_reset_tokens' => 'the starter creates VeyroPHP\'s own table (PasswordResetSchema)',
        'password_resets' => 'the starter creates VeyroPHP\'s own table (PasswordResetSchema)',
        'personal_access_tokens' => 'the starter creates VeyroPHP\'s own table (ApiTokenSchema)',
        'notifications' => 'the starter creates VeyroPHP\'s own table (DatabaseNotifications)',
        'sessions' => 'VeyroPHP keeps sessions in the cache store, not in a table',
        'cache' => 'VeyroPHP\'s cache uses files or Redis, not a table',
        'cache_locks' => 'VeyroPHP\'s cache uses files or Redis, not a table',
        'job_batches' => 'VeyroPHP has no job batches',
    ];

    /** @var list<string> */
    private array $todos = [];

    public function convert(string $source, string $code): ConvertedFile
    {
        $this->todos = [];
        $supported = ['table' => Capabilities::tableMethods(), 'column' => Capabilities::columnModifiers()];
        $lines = Php::lines($code);
        $out = [];

        foreach ($lines as $line) {
            foreach ($this->convertLine($line, $supported) as $converted) {
                $out[] = $converted;
            }
        }

        $content = implode("\n", $out);
        $content = (string) preg_replace('/function\s+(up|down)\s*\(\s*\)(\s*:\s*void)?/', 'function $1(Schema $schema): void', $content);
        $content = (string) preg_replace('/\bSchema::(' . implode('|', self::SCHEMA_METHODS) . ')\s*\(/', '$schema->$1(', $content);
        $content = (string) preg_replace('/\bfunction\s*\(\s*Blueprint\s+\$table\s*\)/', 'static function (Blueprint $table): void', $content);

        // Tables Laravel itself needs: VeyroPHP has its own version, or does not use the table at all.
        foreach (array_intersect(Patterns::createdTables($content, '$schema'), array_keys(self::FRAMEWORK_TABLES)) as $table) {
            $note = "\"{$table}\" is a Laravel framework table: " . self::FRAMEWORK_TABLES[$table] . '; remove it here';
            $this->todos[] = $note;
            $content = (string) preg_replace_callback('/^([ \t]*)(\$schema->create\(\s*[\'"]' . $table . '[\'"])/m', static fn(array $m): string => "{$m[1]}// TODO(port): " . Php::comment($note) . "\n{$m[1]}{$m[2]}", $content, 1);
        }

        // VeyroPHP's Migration requires down(); Laravel's does not.
        if (preg_match('/function\s+down\s*\(/', $content) !== 1) {
            $created = Patterns::createdTables($content, '$schema');
            $body = $created === []
                ? "        // TODO(port): the Laravel migration had no down(); write the reverse of up()\n"
                : implode('', array_map(static fn(string $t): string => "        \$schema->dropIfExists('{$t}');\n", array_reverse($created)));
            $this->todos[] = $created === [] ? 'down() was missing: write it' : 'down() was missing: generated from the tables up() creates';
            $content = (string) preg_replace('/\n\};\s*$/D', "\n\n    public function down(Schema \$schema): void\n    {\n{$body}    }\n};", rtrim($content), 1);
        }

        if (!str_contains($content, 'declare(strict_types=1)')) {
            $content = (string) preg_replace('/^<\?php\s*/', "<?php\n\ndeclare(strict_types=1);\n\n", $content, 1);
        }

        // A callback, not a replacement string: "$1" or "\1" in a source path must stay text.
        $marker = "\n// " . Php::comment(ConvertedFile::MARKER . " from {$source}. Review every TODO(port).") . "\n";
        $content = (string) preg_replace_callback('/^(<\?php\n\ndeclare\(strict_types=1\);\n)/', static fn(array $m): string => $m[1] . $marker, $content, 1);

        return new ConvertedFile('migration', $source, $source, rtrim($content) . "\n", $this->todos);
    }

    /**
     * @param array{table: list<string>, column: list<string>} $supported
     * @return list<string>
     */
    private function convertLine(string $line, array $supported): array
    {
        // A line can hold a multi-line string literal (Php::lines()): the indent is the first line's.
        $indent = preg_match('/^[ \t]*/', $line, $i) === 1 ? $i[0] : '';

        // use statements
        if (preg_match('/^\s*use\s+([\w\\\\]+)\s*;/', $line, $m) === 1) {
            if (isset(self::USES[$m[1]])) {
                return [$indent . 'use ' . self::USES[$m[1]] . ';'];
            }

            if (str_starts_with($m[1], 'Illuminate\\')) {
                return [$this->todo($indent, "{$m[1]} is a Laravel class; replace its use", $line)];
            }
        }

        // Schema:: calls VeyroPHP's Schema does not have
        if (preg_match('/\bSchema::(\w+)\s*\(/', $line, $m) === 1 && !in_array($m[1], self::SCHEMA_METHODS, true)) {
            return [$this->todo($indent, "Schema::{$m[1]}() has no VeyroPHP equivalent", $line)];
        }

        // Data changes inside migrations: DB::table() and friends run on the migration's connection.
        if (preg_match('/\bDB::/', $line) === 1) {
            $line = (string) preg_replace('/\bDB::(table|select|statement|insert|update|delete)\s*\(/', '$schema->connection()->$1(', $line);
            $line = (string) preg_replace('/\bDB::raw\s*\(/', 'new \VeyroPHP\Database\Query\Expression(', $line);
            // lazyById() orders by id itself and refuses another orderBy().
            $line = (string) preg_replace('/->orderBy\(\s*[\x27"]id[\x27"]\s*\)->cursor\(\)/', '->lazyById()', $line);
            $line = (string) preg_replace('/->cursor\(\)/', '->lazyById()', $line);
            $note = preg_match('/\bDB::(\w+)/', $line, $left) === 1
                ? "DB::{$left[1]}() has no automatic equivalent"
                : 'data change converted to $schema->connection(): rows are arrays ($row[\'id\'], not $row->id); check the query methods';
            $this->todos[] = $note;

            return [$indent . '// TODO(port): ' . Php::comment($note), $line];
        }

        if (preg_match('/\b(Artisan|Log|Cache)::/', $line, $m) === 1) {
            return [$this->todo($indent, "uses the {$m[1]} facade; not available in migrations", $line)];
        }

        if (preg_match('/\$table->(\w+)\s*\(/', $line, $m) !== 1) {
            return [$line];
        }

        // An unsupported column type: the whole column cannot be created.
        if (!in_array(strtolower($m[1]), $supported['table'], true)) {
            return [$this->todo($indent, "{$m[1]}() has no VeyroPHP equivalent; choose a supported column type", $line)];
        }

        // foreignId('user_id')->constrained(): Laravel infers the table from the column name.
        if (preg_match('/foreignId\(\s*[\'"](\w+?)_id[\'"]\s*\).*->constrained\(\s*\)/', $line, $f) === 1) {
            $table = Words::plural($f[1]);
            $line = (string) preg_replace('/->constrained\(\s*\)/', "->constrained('{$table}')", $line);
            $this->todos[] = "constrained() table \"{$table}\" inferred from {$f[1]}_id as Laravel does";
        }

        // Unsupported modifiers in a chain are removed; the column itself stays.
        $removed = [];
        preg_match_all('/->(\w+)\s*\(/', $line, $calls);

        foreach (array_slice($calls[1], 1) as $method) {
            if (!in_array(strtolower($method), $supported['column'], true)) {
                $removed[] = $method;
                $line = (string) preg_replace('/->' . preg_quote($method, '/') . '\s*\((?:[^()\'"]|\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*"|\((?:[^()]|\([^()]*\))*\))*\)/', '', $line, 1);
            }
        }

        if ($removed !== []) {
            $note = 'removed ' . implode('(), ', $removed) . '(): no VeyroPHP equivalent';
            $this->todos[] = $note;

            return [$indent . '// TODO(port): ' . Php::comment($note), $line];
        }

        return [$line];
    }

    private function todo(string $indent, string $note, string $line): string
    {
        $this->todos[] = $note;

        // The commented-out line is source code: it must not end the comment or PHP mode.
        return $indent . '// TODO(port): ' . Php::comment($note . ': ' . trim($line));
    }
}
