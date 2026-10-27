<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

use VeyroPHP\Port\Patterns;
use VeyroPHP\Port\SourceTree;

/**
 * Produces VeyroPHP files from a Laravel project: migrations, model
 * skeletons and routes. Reads the project through SourceTree (never
 * executing it); writing is left to ProjectWriter.
 */
final class LaravelConverter
{
    public const AREAS = ['migrations', 'models', 'routes', 'views', 'controllers', 'public'];

    /** File names in public/ that a web server may execute, or that configure it. */
    private const SERVER_FILE = '/(\.(php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp|shtml)|^\.htaccess|^\.user\.ini|^web\.config)$/iD';

    /**
     * @param list<string> $areas
     * @return array{list<ConvertedFile>, list<string>} [files, notes about what was not converted]
     */
    public function convert(SourceTree $tree, array $areas = self::AREAS): array
    {
        $files = [];
        $notes = [];

        if (in_array('migrations', $areas, true)) {
            $converter = new MigrationConverter();

            foreach ($tree->under('database/migrations') as $file) {
                $files[] = $converter->convert($file, $tree->read($file));
            }
        }

        if (in_array('models', $areas, true)) {
            $converter = new ModelConverter();

            foreach ($tree->under('app') as $file) {
                $code = $tree->read($file);

                if (preg_match(Patterns::ELOQUENT_MODEL, $code) === 1) {
                    $converted = $converter->convert($file, $code);

                    if ($converted !== null) {
                        $files[] = $converted;
                    }
                }
            }
        }

        if (in_array('routes', $areas, true)) {
            $converter = new RouteConverter();

            foreach (['routes/web.php', 'routes/api.php'] as $file) {
                if ($tree->exists($file)) {
                    $files[] = $converter->convert($file, $tree->read($file));
                }
            }

            foreach (['routes/console.php', 'routes/channels.php'] as $file) {
                if ($tree->exists($file)) {
                    $notes[] = "{$file} was not converted: console commands and schedules go to app/Console and routes/schedule.php, broadcasting has no equivalent";
                }
            }
        }

        if (in_array('views', $areas, true)) {
            $converter = new BladeConverter();

            foreach ($tree->under('resources/views', '.blade.php') as $file) {
                $files[] = $converter->convert($file, $tree->read($file));
            }
        }

        if (in_array('controllers', $areas, true)) {
            $converter = new ControllerConverter();

            foreach ($tree->under('app/Http/Controllers') as $file) {
                $converted = $converter->convert($file, $tree->read($file));

                if ($converted !== null) {
                    $files[] = $converted;
                }
            }
        }

        if (in_array('public', $areas, true)) {
            // Static files (CSS, JS, images, fonts). The front controller and server
            // configuration are VeyroPHP's own; files the target already has are kept.
            foreach ($tree->under('public', '') as $file) {
                if (in_array(substr($file, strlen('public/')), ['index.php', '.htaccess', 'web.config', 'mix-manifest.json', 'hot'], true) || str_starts_with($file, 'public/storage/') || preg_match('#/(filament|livewire)/#', $file) === 1) {
                    continue;
                }

                // Never place code a web server could run, or its configuration, in the
                // target's web root (VQ-016).
                if (preg_match(self::SERVER_FILE, basename($file)) === 1) {
                    $notes[] = "{$file} was not copied: the web server could execute it or it configures the server. Port it by hand if it is really needed.";

                    continue;
                }

                // read() returns "" above its size limit; writing that would leave an empty file (VQ-048).
                if ($tree->tooLarge($file)) {
                    $notes[] = "{$file} was not copied: it is too large for the converter. Copy it by hand.";

                    continue;
                }

                $files[] = new ConvertedFile('asset', $file, $file, $tree->read($file), []);
            }
        }

        $notes[] = 'Middleware, form requests, jobs, mail, notifications and console commands are not converted by this version: port them by hand (php veyro port:analyze lists them).';

        return self::withoutDuplicateTargets($files, $notes);
    }

    /**
     * Two converted files must never share a target: the second would overwrite the first
     * (CORE-3). The first is kept and the others are reported.
     *
     * @param list<ConvertedFile> $files
     * @param list<string> $notes
     *
     * @return array{list<ConvertedFile>, list<string>}
     */
    private static function withoutDuplicateTargets(array $files, array $notes): array
    {
        $kept = [];

        foreach ($files as $file) {
            if (isset($kept[$file->target])) {
                $notes[] = "{$file->source} was not converted: {$kept[$file->target]->source} already becomes {$file->target}. Port it by hand.";

                continue;
            }

            $kept[$file->target] = $file;
        }

        return [array_values($kept), $notes];
    }
}
