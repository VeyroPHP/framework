<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Console;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Security\Audit\Advisory;
use VeyroPHP\Security\Audit\AuditDiff;
use VeyroPHP\Security\Audit\AuditException;
use VeyroPHP\Security\Audit\AuditMail;
use VeyroPHP\Security\Audit\Auditor;
use VeyroPHP\Security\Audit\AuditPolicy;
use VeyroPHP\Security\Audit\AuditReport;
use VeyroPHP\Security\Audit\AuditSource;
use VeyroPHP\Security\Audit\ComposerAuditSource;
use VeyroPHP\Security\Audit\Events\AuditChanged;
use VeyroPHP\Security\Audit\LockFile;
use VeyroPHP\Security\Audit\OsvSource;
use VeyroPHP\Security\Audit\PolicyResult;

/**
 * Checks the locked dependency versions against published advisories
 * (composer audit and OSV.dev) and applies the policy in config "audit".
 *
 * Exit codes: 0 policy passed, 1 findings fail the policy, 2 usage error,
 * 3 no failing findings but the scan was incomplete.
 *
 * With --notify (for the scheduler) the report is compared with the previous
 * one saved at config "audit.report"; when something changed, an email goes
 * to "audit.notify.mail" and an AuditChanged event is dispatched.
 */
#[AsCommand('security:audit', 'Check dependencies against published security advisories')]
final class AuditCommand extends Command
{
    public const INCOMPLETE = 3;

    /** @var array<string, AuditSource>|null sources by name; built from config when null */
    private ?array $sources = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    /**
     * Replaces the configured sources (tests, custom databases).
     *
     * @param list<AuditSource> $sources
     */
    public function useSources(array $sources): void
    {
        $this->sources = [];

        foreach ($sources as $source) {
            $this->sources[$source->name()] = $source;
        }
    }

    public function definition(): array
    {
        return [
            Option::value('path', 'Project directory containing composer.lock (default: the application)'),
            Option::value('sources', 'Comma-separated sources: composer, osv (default: config audit.sources)'),
            Option::flag('no-dev', 'Skip packages-dev'),
            Option::value('format', 'table or json', 'table'),
            Option::value('output', 'Also write the JSON report to this file'),
            Option::value('check', 'Do not scan: check that this saved JSON report is still current'),
            Option::value('max-age', 'Days a saved report stays current (with --check)', '7'),
            Option::flag('notify', 'Compare with the previous saved report and notify when something changed'),
        ];
    }

    public function help(): string
    {
        return "Only composer.lock is read: no package code is installed or run.\n"
            . "Exit codes: 0 passed, 1 findings fail the policy, 2 usage error, 3 scan incomplete.\n"
            . 'A passing audit is not a guarantee that dependencies are safe: it only covers published advisories.';
    }

    public function execute(Input $input, Output $output): int
    {
        $path = $input->option('path') ?? $this->app->basePath();
        $now = new \DateTimeImmutable();

        try {
            $lock = LockFile::read(rtrim($path, '/\\') . '/composer.lock');

            if (($check = $input->option('check')) !== null) {
                return $this->check($check, $lock, $now, $input, $output);
            }

            $policy = AuditPolicy::fromConfig($this->settings());
            $sources = $this->selectSources($input->option('sources'));
        } catch (AuditException $e) {
            $output->error($e->getMessage());

            return self::USAGE;
        }

        $format = $input->option('format') ?? 'table';

        if (!in_array($format, ['table', 'json'], true)) {
            $output->error('--format must be table or json.');

            return self::USAGE;
        }

        $report = (new Auditor($sources))->audit($lock, !$input->flag('no-dev'), $now);
        $result = $policy->evaluate($report, $now);
        $json = json_encode($report->toArray($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        $notify = $input->flag('notify');
        $file = $input->option('output') ?? ($notify ? $this->reportPath() : null);
        $previous = $notify && $file !== null ? $this->previousReport($file, $output) : null;

        if ($file !== null) {
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0o775, true) || file_put_contents($file, $json) === false) {
                $output->error("Could not write {$file}.");

                return self::FAILURE;
            }
        }

        if ($format === 'json') {
            $output->write($json);
        } else {
            $this->table($report, $result, $output);
        }

        $code = match (true) {
            $result->failures !== [] => self::FAILURE,
            $result->incomplete => self::INCOMPLETE,
            default => self::SUCCESS,
        };

        if ($notify && !$this->notify(AuditDiff::between($previous, $report), $report, $result, $output, $format === 'json')) {
            return $code === self::SUCCESS ? self::FAILURE : $code;
        }

        return $code;
    }

    private function reportPath(): string
    {
        $path = $this->settings()['report'] ?? null;
        $path = is_string($path) && $path !== '' ? $path : 'storage/security/audit.json';

        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1 ? $path : $this->app->basePath() . '/' . $path;
    }

    /**
     * @return array<mixed>|null
     */
    private function previousReport(string $file, Output $output): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data) || ($data['schema'] ?? null) !== AuditReport::SCHEMA) {
            $output->warning("The previous report {$file} is unreadable; every finding is treated as new.");

            return null;
        }

        return $data;
    }

    /**
     * Sends the change by mail and as an event. Returns false when a
     * configured notification could not be sent.
     */
    private function notify(AuditDiff $diff, AuditReport $report, PolicyResult $result, Output $output, bool $json): bool
    {
        // With --format=json, stdout carries only the report.
        $say = static function (string $text) use ($output, $json): void {
            $json ? $output->warning($text) : $output->line($text);
        };

        if (!$diff->changed()) {
            $say('No changes since the previous audit: nothing to notify.');

            return true;
        }

        $container = $this->app->container();
        $sent = [];
        $ok = true;

        if ($container->bound(EventDispatcherInterface::class)) {
            $container->make(EventDispatcherInterface::class)->dispatch(new AuditChanged($report, $result, $diff));
            $sent[] = 'AuditChanged event';
        }

        $notify = $this->settings()['notify'] ?? null;
        $mail = is_array($notify) && is_array($notify['mail'] ?? null) ? array_values(array_filter($notify['mail'], 'is_string')) : [];

        if ($mail !== []) {
            try {
                if (!$container->bound(Mailer::class)) {
                    throw new AuditException('audit.notify.mail is set, but mail is not configured (MailServiceProvider).');
                }

                $name = $this->config->get('app.name', 'VeyroPHP');
                $container->make(Mailer::class)->send(AuditMail::build(is_string($name) ? $name : 'VeyroPHP', $mail, $report, $result, $diff));
                $sent[] = 'mail to ' . implode(', ', $mail);
            } catch (\Throwable $e) {
                $output->error('The audit notification email could not be sent: ' . $e->getMessage());
                $ok = false;
            }
        }

        if ($sent === [] && $ok) {
            $output->warning("Changed ({$diff->summary()}), but nobody was notified: set audit.notify.mail or listen for AuditChanged.");
        } elseif ($sent !== []) {
            $say("Notified ({$diff->summary()}): " . implode(', ', $sent) . '.');
        }

        return $ok;
    }

    /**
     * @return array<mixed>
     */
    private function settings(): array
    {
        $settings = $this->config->get('audit', []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * @return list<AuditSource>
     */
    private function selectSources(?string $option): array
    {
        $available = $this->sources ?? $this->configuredSources();
        $names = $option !== null ? explode(',', $option) : ($this->settings()['sources'] ?? ['composer', 'osv']);
        $selected = [];

        foreach (is_array($names) ? $names : [] as $name) {
            $name = is_string($name) ? trim($name) : '';

            if (!isset($available[$name])) {
                throw new AuditException("Unknown audit source \"{$name}\": use " . implode(', ', array_keys($available)) . '.');
            }

            $selected[] = $available[$name];
        }

        if ($selected === []) {
            throw new AuditException('No audit sources selected.');
        }

        return $selected;
    }

    /**
     * @return array<string, AuditSource>
     */
    private function configuredSources(): array
    {
        $settings = $this->settings();
        $composer = $settings['composer'] ?? null;
        $endpoint = $settings['osv_endpoint'] ?? null;
        $timeout = is_int($settings['timeout'] ?? null) ? $settings['timeout'] : 120;
        $container = $this->app->container();
        $client = $container->bound(ClientInterface::class) ? $container->make(ClientInterface::class) : null;

        return [
            'composer' => new ComposerAuditSource(is_string($composer) ? $composer : null, $timeout),
            'osv' => new OsvSource($client, is_string($endpoint) ? $endpoint : 'https://api.osv.dev', $timeout),
        ];
    }

    private function check(string $file, LockFile $lock, \DateTimeImmutable $now, Input $input, Output $output): int
    {
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($saved)) {
            $output->error("{$file} is not a saved audit report.");

            return self::FAILURE;
        }

        $problems = AuditReport::staleness($saved, $lock, $now, $input->positiveInt('max-age') ?? 7);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $output->error("Stale report: {$problem}.");
            }
            $output->line('Run php veyro security:audit again.');

            return self::FAILURE;
        }

        $output->success('The saved report is current for this composer.lock.');

        return self::SUCCESS;
    }

    /**
     * The first CVE and GHSA id, the identifiers people search for; the JSON
     * report keeps every identifier.
     */
    private static function label(Advisory $advisory): string
    {
        $ids = [$advisory->id, ...$advisory->aliases];
        $pick = static fn(string $prefix): array => array_slice(array_values(array_filter($ids, static fn(string $id): bool => str_starts_with($id, $prefix))), 0, 1);
        $label = [...$pick('CVE-'), ...$pick('GHSA-')];

        return $label === [] ? $advisory->id : implode(' ', $label);
    }

    private function table(AuditReport $report, PolicyResult $result, Output $output): void
    {
        $count = count($report->lock->packages($report->includeDev));
        $output->line(sprintf('Checked %d packages from %s%s.', $count, $report->lock->path, $report->includeDev ? '' : ' (without dev)'));

        foreach ($report->results as $source) {
            if ($source->succeeded()) {
                $output->line("  {$source->source}: ok, " . count($source->advisories) . ' advisories');
            } else {
                $output->error("  {$source->source}: FAILED: {$source->error}");
            }

            foreach ($source->notChecked as $package) {
                $output->warning("  {$source->source}: not checked: {$package}");
            }
        }

        $output->line();

        if ($report->advisories !== []) {
            $rows = [];

            foreach ($report->advisories as $a) {
                $rows[] = [$a->severity->value, $a->package . ' ' . $a->version, self::label($a), $a->title];
            }

            $output->table(['Severity', 'Package', 'Advisory', 'Title'], $rows);
        }

        foreach ($report->abandoned as $package => $replacement) {
            $output->warning("{$package} is abandoned" . ($replacement !== '' ? "; suggested replacement: {$replacement}" : '') . '.');
        }

        foreach ($result->ignored as $ignored) {
            $output->line("Ignored {$ignored['id']} ({$ignored['package']}): {$ignored['reason']}" . ($ignored['until'] !== null ? " until {$ignored['until']}" : ''));
        }

        foreach ($result->expiredIgnores as $expired) {
            $output->warning("Ignore rule expired: {$expired}.");
        }

        if ($result->failures !== []) {
            $output->error(count($result->failures) . ' finding(s) fail the audit policy.');
        } elseif ($result->incomplete) {
            $output->error('The scan is incomplete: a source failed, so the result cannot be trusted.');
        } elseif (!$report->complete()) {
            $output->warning('Passed the policy, but a source failed (audit.fail_on_source_error is off).');
        } elseif ($report->advisories === []) {
            $output->success('No known vulnerabilities found in the sources that ran.');
        } else {
            $output->success('The findings are below the policy threshold.');
        }

        $output->line(AuditReport::NOTICE);
    }
}
