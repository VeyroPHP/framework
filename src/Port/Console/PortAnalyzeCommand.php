<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Console;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Port\PortAnalyzer;
use VeyroPHP\Port\PortException;
use VeyroPHP\Port\Report;
use VeyroPHP\Port\Status;

/**
 * Reads a Laravel or CodeIgniter project and reports how it maps to
 * VeyroPHP. The project is never run or modified.
 */
#[AsCommand('port:analyze', 'Analyze a Laravel or CodeIgniter project for porting to VeyroPHP (read-only)')]
final class PortAnalyzeCommand extends Command
{
    public function definition(): array
    {
        return [
            Argument::required('path', 'The project to analyze'),
            Option::value('from', 'laravel, codeigniter or auto', 'auto'),
            Option::value('format', 'table or json', 'table'),
            Option::value('output', 'Also write the JSON report to this file (outside the analyzed project)'),
        ];
    }

    public function help(): string
    {
        return "The project is only read: no file in it is executed or changed, and .env files are never opened.\n"
            . "Statuses: direct (same concept, mostly renaming), manual (concept exists, rewrite needed), unsupported (no equivalent yet).\n"
            . 'Example: php veyro port:analyze ../my-laravel-app --output=build/port-report.json';
    }

    public function execute(Input $input, Output $output): int
    {
        $format = $input->option('format') ?? 'table';

        if (!in_array($format, ['table', 'json'], true)) {
            $output->error('--format must be table or json.');

            return self::USAGE;
        }

        try {
            $report = (new PortAnalyzer())->analyze((string) $input->argument('path'), $input->option('from') ?? 'auto');
        } catch (PortException $e) {
            $output->error($e->getMessage());

            return self::USAGE;
        }

        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        if (($file = $input->option('output')) !== null && !$this->write($file, $json, $report, $output)) {
            return self::FAILURE;
        }

        if ($format === 'json') {
            $output->write($json);
        } else {
            $this->table($report, $output);
        }

        return self::SUCCESS;
    }

    private function write(string $file, string $json, Report $report, Output $output): bool
    {
        $dir = dirname($file);

        // Checked before anything is created: not even a folder may appear in the project.
        $existing = $dir;

        while (!is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $target = str_replace('\\', '/', (string) realpath($existing));

        if ($target === $report->root || str_starts_with($target . '/', $report->root . '/')) {
            $output->error('The report is not written inside the analyzed project: choose another --output.');

            return false;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true)) {
            $output->error("Could not create {$dir}.");

            return false;
        }

        if (file_put_contents($file, $json) === false) {
            $output->error("Could not write {$file}.");

            return false;
        }

        return true;
    }

    private function table(Report $report, Output $output): void
    {
        $output->line(sprintf('%s %s at %s', ucfirst($report->framework), $report->version ?? '(version unknown)', $report->root));
        $output->line();

        foreach ($report->warnings() as $warning) {
            $output->warning($warning);
        }

        $rows = [];

        foreach ($report->findings() as $f) {
            $item = mb_strlen($f->item) > 70 ? mb_substr($f->item, 0, 67) . '...' : $f->item;
            $rows[] = [$f->status->value, $f->area, $item, (string) $f->count, $f->note];
        }

        if ($rows !== []) {
            $output->table(['Status', 'Area', 'Item', 'Count', 'VeyroPHP'], $rows);
        }

        $summary = $report->summary();
        $output->line();
        $output->line(sprintf('%d direct, %d manual, %d unsupported. Full details (files, guide links): --format=json.', $summary[Status::Direct->value], $summary[Status::Manual->value], $summary[Status::Unsupported->value]));
        $output->line(Report::NOTICE);
    }
}
