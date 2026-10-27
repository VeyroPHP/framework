<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

/**
 * Runs "composer audit --locked --format=json" (Packagist's advisory database,
 * which includes GitHub and FriendsOfPHP advisories) on the lock file.
 *
 * Composer is started without a shell, with plugins and scripts disabled, no
 * interaction, stdin closed and a time limit, so auditing a project never runs
 * code from its dependencies or edits its composer.json. Composer exits 1
 * both for "vulnerable" and for many errors, so only valid JSON output with
 * the expected keys counts as a result.
 */
final class ComposerAuditSource implements AuditSource
{
    /** @var \Closure(list<string>, string, int): array{int, string, string} */
    private readonly \Closure $runner;

    /**
     * @param ?string $composer path to composer.phar or a composer executable; found on PATH when null
     * @param (\Closure(list<string>, string, int): array{int, string, string})|null $runner
     *        runs [command, working directory, timeout] and returns [exit code, stdout, stderr]
     */
    public function __construct(
        private readonly ?string $composer = null,
        private readonly int $timeout = 120,
        ?\Closure $runner = null,
    ) {
        $this->runner = $runner ?? self::run(...);
    }

    public function name(): string
    {
        return 'composer';
    }

    public function scan(LockFile $lock, bool $includeDev): SourceResult
    {
        try {
            $composer = $this->composer ?? self::locate();
        } catch (AuditException $e) {
            return SourceResult::failed($this->name(), $e->getMessage());
        }

        $command = [
            ...self::invocation($composer),
            'audit', '--locked', '--format=json', '--no-plugins', '--no-scripts', '--no-interaction',
            '--working-dir=' . $lock->directory(),
        ];

        if (!$includeDev) {
            $command[] = '--no-dev';
        }

        [$code, $stdout, $stderr] = ($this->runner)($command, $lock->directory(), $this->timeout);
        $data = json_decode($stdout, true);

        // 0 = nothing found; bit 1 = vulnerable, bit 2 = abandoned packages.
        if ($code < 0 || $code > 3 || !is_array($data) || !array_key_exists('advisories', $data)) {
            $reason = trim(preg_replace('/\e\[[0-9;]*m/', '', $stderr) ?? '');

            return SourceResult::failed($this->name(), "composer audit failed (exit {$code})" . ($reason !== '' ? ': ' . self::lastLine($reason) : ''));
        }

        $advisories = [];

        foreach (is_array($data['advisories']) ? $data['advisories'] : [] as $package => $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry)) {
                    $advisories[] = self::advisory((string) $package, $lock, $entry);
                }
            }
        }

        $abandoned = [];

        foreach (is_array($data['abandoned'] ?? null) ? $data['abandoned'] : [] as $package => $replacement) {
            $abandoned[(string) $package] = is_string($replacement) ? $replacement : '';
        }

        return SourceResult::ok($this->name(), $advisories, $abandoned);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function advisory(string $package, LockFile $lock, array $entry): Advisory
    {
        $string = static fn(string $key): ?string => is_string($entry[$key] ?? null) && $entry[$key] !== '' ? $entry[$key] : null;
        $aliases = [];

        if (($cve = $string('cve')) !== null) {
            $aliases[] = $cve;
        }

        foreach (is_array($entry['sources'] ?? null) ? $entry['sources'] : [] as $source) {
            if (is_array($source) && is_string($source['remoteId'] ?? null)) {
                $aliases[] = $source['remoteId'];
            }
        }

        $id = $string('advisoryId') ?? array_shift($aliases) ?? 'unknown';

        return new Advisory(
            $package,
            $lock->version($package) ?? '',
            $id,
            array_values(array_diff(array_unique($aliases), [$id])),
            $string('title') ?? '',
            Severity::parse($entry['severity'] ?? null),
            $string('link'),
            $string('affectedVersions'),
            ['composer'],
        );
    }

    /**
     * Finds Composer as a PHP script that can be run with this PHP binary:
     * $COMPOSER_BINARY (set while Composer runs scripts), then composer.phar or
     * a "composer" PHP script on PATH. Wrapper scripts (.bat, sh) are skipped
     * because they would need a shell.
     */
    public static function locate(): string
    {
        $candidates = [];
        $env = getenv('COMPOSER_BINARY');

        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'composer.phar';
                $candidates[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'composer';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && self::isPhpScript($candidate)) {
                return $candidate;
            }
        }

        throw new AuditException('Composer was not found: set audit.composer to the path of composer.phar.');
    }

    private static function isPhpScript(string $path): bool
    {
        if (str_ends_with(strtolower($path), '.phar')) {
            return true;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fgets($handle, 200);
        fclose($handle);

        return str_starts_with($head, '<?php') || (str_starts_with($head, '#!') && str_contains($head, 'php'));
    }

    /**
     * @return list<string>
     */
    private static function invocation(string $composer): array
    {
        return self::isPhpScript($composer) ? [PHP_BINARY, $composer] : [$composer];
    }

    private static function lastLine(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));

        return $lines === [] ? '' : $lines[count($lines) - 1];
    }

    /**
     * Output goes to temporary files and the process is polled, because pipes
     * cannot be read without blocking on Windows.
     *
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function run(array $command, string $cwd, int $timeout): array
    {
        $out = tempnam(sys_get_temp_dir(), 'veyrophp-audit');
        $err = tempnam(sys_get_temp_dir(), 'veyrophp-audit');

        if ($out === false || $err === false) {
            return [-1, '', 'could not create temporary files'];
        }

        try {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $cwd);

            if (!is_resource($process)) {
                return [-1, '', 'could not start ' . $command[0]];
            }

            fclose($pipes[0]);
            $deadline = microtime(true) + $timeout;

            while (($status = proc_get_status($process))['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    proc_close($process);

                    return [-1, '', "timed out after {$timeout} seconds"];
                }

                usleep(50_000);
            }

            proc_close($process);

            return [$status['exitcode'], (string) file_get_contents($out), (string) file_get_contents($err)];
        } finally {
            @unlink($out);
            @unlink($err);
        }
    }
}
