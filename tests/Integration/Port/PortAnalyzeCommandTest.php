<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Port;

use PHPUnit\Framework\TestCase;
use VeyroPHP\Testing\TestApplication;

final class PortAnalyzeCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Port';

    private TestApplication $test;

    protected function setUp(): void
    {
        $this->test = TestApplication::boot();
    }

    protected function tearDown(): void
    {
        $this->test->cleanUp();
    }

    public function testTableReport(): void
    {
        [$code, $out] = $this->test->command(['port:analyze', self::FIXTURES . '/laravel-app']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Laravel v12.1.0 at ', $out);
        self::assertMatchesRegularExpression('/unsupported\s+Facades\s+Http::\s+1\s+no HTTP client is built in/', $out);
        self::assertMatchesRegularExpression('/\d+ direct, \d+ manual, \d+ unsupported\./', $out);
        self::assertStringContainsString('Analysis only: the project was read, not run or changed', $out);
    }

    public function testJsonReportToStdoutAndFile(): void
    {
        $file = $this->test->basePath() . '/build/port.json';
        [$code, $out] = $this->test->command(['port:analyze', self::FIXTURES . '/ci4-app', '--format=json', '--output=' . $file]);

        self::assertSame(0, $code);
        $report = json_decode($out, true);
        self::assertIsArray($report);
        self::assertSame($report, json_decode((string) file_get_contents($file), true));
        self::assertSame('codeigniter4', $report['framework']);
        self::assertIsArray($report['findings']);
        self::assertNotSame([], $report['findings']);
    }

    public function testNeverWritesIntoTheAnalyzedProject(): void
    {
        $project = sys_get_temp_dir() . '/veyrophp-port-' . bin2hex(random_bytes(4));
        mkdir($project . '/routes', 0777, true);
        file_put_contents($project . '/artisan', "<?php\n");
        mkdir($project . '/bootstrap');
        file_put_contents($project . '/bootstrap/app.php', "<?php\n");
        file_put_contents($project . '/routes/web.php', "<?php\nRoute::get('/', 'X');\n");

        try {
            [$code, , $err] = $this->test->command(['port:analyze', $project, '--output=' . $project . '/reports/new/port.json']);

            self::assertSame(1, $code);
            self::assertStringContainsString('not written inside the analyzed project', $err);
            self::assertDirectoryDoesNotExist($project . '/reports', 'Not even a folder may be created in the project.');
        } finally {
            foreach (['routes/web.php', 'bootstrap/app.php', 'artisan'] as $f) {
                @unlink($project . '/' . $f);
            }
            @rmdir($project . '/routes');
            @rmdir($project . '/bootstrap');
            @rmdir($project);
        }
    }

    public function testUsageErrors(): void
    {
        [$code, , $err] = $this->test->command(['port:analyze', self::FIXTURES . '/plain-app']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Could not recognize the framework', $err);

        [$code, , $err] = $this->test->command(['port:analyze', self::FIXTURES . '/laravel-app', '--format=xml']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--format must be table or json.', $err);
    }
}
