<?php

declare(strict_types=1);

namespace VeyroPHP\View\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\View\TemplateLinter;

/**
 * Checks every template for output that is not escaped (see TemplateLinter).
 * Exits with 1 when an error is found, so it can run in CI.
 */
#[AsCommand('view:lint', 'Find template output that is not escaped (cross-site scripting risks)')]
final class LintCommand extends Command
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    public function definition(): array
    {
        return [
            Option::value('path', 'Template directory (default: config view.path, or resources/views)'),
            Option::flag('strict', 'Also fail on warnings ($e() inside href, src and other URL attributes)'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $directory = $input->option('path') ?? $this->app->basePath($this->config->has('view.path') ? $this->config->string('view.path') : 'resources/views');

        if (!is_dir($directory)) {
            $output->error(sprintf('Template directory "%s" does not exist.', $directory));

            return self::USAGE;
        }

        $linter = new TemplateLinter();
        $files = 0;
        $errors = 0;
        $warnings = 0;

        foreach (self::templates($directory) as $file) {
            $files++;
            $relative = str_replace('\\', '/', substr($file, strlen(rtrim($directory, '/\\')) + 1));
            $code = (string) file_get_contents($file);

            foreach ($linter->errors($code) as $finding) {
                $errors++;
                $output->error(sprintf('%s:%d  unescaped output: %s  (use $e(), or $view->url/json/attr)', $relative, $finding['line'], $finding['expression']));
            }

            foreach ($linter->warnings($code) as $finding) {
                $warnings++;
                $output->warning(sprintf('%s:%d  $e() in a URL attribute: %s  (use $view->url() to block javascript: URLs)', $relative, $finding['line'], $finding['expression']));
            }
        }

        $summary = sprintf('%d template(s) checked: %d error(s), %d warning(s).', $files, $errors, $warnings);

        if ($errors > 0 || ($warnings > 0 && $input->flag('strict'))) {
            $output->error($summary);

            return self::FAILURE;
        }

        $output->success($summary);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function templates(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
