<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Console;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Port\Convert\ConvertedFile;
use VeyroPHP\Port\Convert\LaravelConverter;
use VeyroPHP\Port\Convert\ProjectWriter;
use VeyroPHP\Port\LaravelAnalyzer;
use VeyroPHP\Port\PortException;
use VeyroPHP\Port\SourceTree;

/**
 * Converts parts of a Laravel project into a separate VeyroPHP application.
 * The source is only read; files are added to the target for review.
 */
#[AsCommand('port:convert', 'Convert Laravel migrations, models and routes into a new VeyroPHP application')]
final class PortConvertCommand extends Command
{
    public function definition(): array
    {
        return [
            Argument::required('source', 'The Laravel project (only read)'),
            Argument::required('target', 'A separate VeyroPHP application to write into'),
            Option::value('only', 'Comma-separated: ' . implode(', ', LaravelConverter::AREAS)),
            Option::flag('dry-run', 'Show what would be written; write nothing'),
            Option::flag('force', 'Replace files this command wrote earlier (never other files)'),
            Option::value('format', 'table or json', 'table'),
        ];
    }

    public function help(): string
    {
        return "The source project is never run or changed. Every converted file says where it came from and lists\n"
            . "TODO(port) items; review them (git diff in the target) before using the code. Converted code that\n"
            . 'parses is not proven to work: run the migrations on a copy of the data and test the application.';
    }

    public function execute(Input $input, Output $output): int
    {
        $format = $input->option('format') ?? 'table';
        $areas = $input->option('only') === null ? LaravelConverter::AREAS : array_map('trim', explode(',', (string) $input->option('only')));

        if (!in_array($format, ['table', 'json'], true) || array_diff($areas, LaravelConverter::AREAS) !== []) {
            $output->error('--format must be table or json; --only takes ' . implode(', ', LaravelConverter::AREAS) . '.');

            return self::USAGE;
        }

        try {
            $tree = new SourceTree((string) $input->argument('source'));

            if (!(new LaravelAnalyzer())->detects($tree)) {
                throw new PortException("{$tree->root} does not look like a Laravel project (only Laravel can be converted so far).");
            }

            $writer = new ProjectWriter((string) $input->argument('target'), $tree->root);
            [$files, $notes] = (new LaravelConverter())->convert($tree, $areas);
            $dryRun = $input->flag('dry-run');
            $force = $input->flag('force');
            $results = [];

            foreach ($files as $file) {
                $status = $dryRun ? $writer->plan($file, $force) : $writer->write($file, $force);
                $results[] = [$file, $dryRun ? 'would be ' . $status : $status];
            }
        } catch (PortException $e) {
            $output->error($e->getMessage());

            return self::USAGE;
        }

        $broken = array_values(array_filter($files, static fn(ConvertedFile $f): bool => !$f->parses()));
        $conflicts = $writer->tableConflicts($files);
        $routeConflicts = $writer->routeConflicts($files);

        if ($format === 'json') {
            $output->write(json_encode([
                'source' => $tree->root,
                'target' => $writer->root,
                'dry_run' => $dryRun,
                'files' => array_map(static fn(array $r): array => [
                    'kind' => $r[0]->kind,
                    'source' => $r[0]->source,
                    'target' => $r[0]->target,
                    'status' => $r[1],
                    'parses' => $r[0]->parses(),
                    'todos' => array_map(static fn(string $t): string => (string) preg_replace('/\s+/', ' ', $t), $r[0]->todos),
                ], $results),
                'table_conflicts' => array_map(static fn(array $c): array => ['converted' => $c[0], 'existing' => $c[1]], $conflicts),
                'route_conflicts' => $routeConflicts,
                'notes' => $notes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        } else {
            $rows = array_map(static fn(array $r): array => [$r[0]->kind, $r[0]->source, $r[0]->target, $r[1], (string) count($r[0]->todos), $r[0]->parses() ? 'yes' : 'NO'], $results);
            $output->table(['Kind', 'From', 'To', 'Status', 'TODOs', 'Parses'], $rows);

            foreach ($notes as $note) {
                $output->line('- ' . $note);
            }

            $output->line(sprintf('%d files, %d TODO(port) items. %s', count($files), array_sum(array_map(static fn(ConvertedFile $f): int => count($f->todos), $files)), $dryRun ? 'Nothing was written (--dry-run).' : 'Review them in the target before use.'));
            $output->line('Parsing only checks syntax: run the migrations on a copy of the data and test the application.');
        }

        foreach ($conflicts as $table => [$converted, $existing]) {
            $output->warning("Table \"{$table}\" is created by {$converted} and by {$existing}: keep one (merge the columns you need) before running the migrations.");
        }

        foreach ($routeConflicts as $conflict) {
            $output->warning('Route conflict (approximate check): ' . $conflict . '.');
        }

        foreach ($results as [$file, $status]) {
            if (str_ends_with($status, 'exists')) {
                $output->warning("{$file->target} already exists and was left unchanged.");
            }
        }

        if ($broken !== []) {
            $output->error(count($broken) . ' converted file(s) do not parse: ' . implode(', ', array_map(static fn(ConvertedFile $f): string => $f->target, $broken)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
