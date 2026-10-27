<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleException;
use VeyroPHP\Console\Output;
use VeyroPHP\Container\Container;
use VeyroPHP\Tests\Fixtures\Console\BadNameCommand;
use VeyroPHP\Tests\Fixtures\Console\CacheClearCommand;
use VeyroPHP\Tests\Fixtures\Console\CacheWarmCommand;
use VeyroPHP\Tests\Fixtures\Console\DuplicateGreetCommand;
use VeyroPHP\Tests\Fixtures\Console\EchoLineCommand;
use VeyroPHP\Tests\Fixtures\Console\FailingCommand;
use VeyroPHP\Tests\Fixtures\Console\GreetCommand;
use VeyroPHP\Tests\Fixtures\Console\NotACommand;
use VeyroPHP\Tests\Fixtures\Console\ReservedNameCommand;
use VeyroPHP\Tests\Fixtures\Console\UnnamedCommand;

#[CoversClass(Console::class)]
#[CoversClass(Output::class)]
#[CoversClass(AsCommand::class)]
#[CoversClass(Command::class)]
final class ConsoleTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;

    protected function setUp(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $this->stdout = $out;
        $this->stderr = $err;
        GreetCommand::$constructed = 0;
        $this->logged = new \ArrayObject();
    }

    private function console(bool $debug = false): Console
    {
        $logger = new class ($this->logged) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, string> $logged
             */
            public function __construct(private readonly \ArrayObject $logged) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = (is_string($level) ? $level : '') . ' ' . $message;
            }
        };

        return new Console(
            new Container(),
            [GreetCommand::class, CacheWarmCommand::class, CacheClearCommand::class, FailingCommand::class, EchoLineCommand::class],
            'veyro',
            '1.2.3',
            $logger,
            $debug,
        );
    }

    /**
     * @param list<string> $arguments
     * @param resource|null $stdin
     *
     * @return array{int, string, string}
     */
    private function call(array $arguments, bool $debug = false, $stdin = null): array
    {
        ftruncate($this->stdout, 0);
        ftruncate($this->stderr, 0);
        rewind($this->stdout);
        rewind($this->stderr);

        $code = $this->console($debug)->run($arguments, new Output($this->stdout, $this->stderr), $stdin);
        rewind($this->stdout);
        rewind($this->stderr);

        return [$code, (string) stream_get_contents($this->stdout), (string) stream_get_contents($this->stderr)];
    }

    public function testRunsACommandWithAutowiredDependencies(): void
    {
        [$code, $out, $err] = $this->call(['greet', 'Asha', '--shout', '--times=2']);

        self::assertSame(0, $code);
        self::assertSame("HELLO, ASHA!\nHELLO, ASHA!\n", str_replace(PHP_EOL, "\n", $out));
        self::assertSame('', $err);
    }

    public function testListIsDefaultSortedGroupedAndConstructsNothing(): void
    {
        foreach ([[], ['list'], ['help'], ['--help'], ['-h']] as $arguments) {
            [$code, $out] = $this->call($arguments);
            self::assertSame(0, $code);
            self::assertStringContainsString('VeyroPHP 1.2.3', $out);
            self::assertStringContainsString('php veyro <command> [arguments] [options]', $out);
            self::assertMatchesRegularExpression('/ cache\R  cache:clear\s+Clear the cache\R  cache:warm\s+Warm the cache\R/', $out);
            self::assertMatchesRegularExpression('/  greet\s+Greet someone/', $out);
            // Commands without a group come before the first group heading.
            self::assertMatchesRegularExpression('/commands:\R  echo-line .*\R  fail .*\R  greet .*\R  help .*\R  list .*\R cache\R/', $out);
        }

        self::assertSame(0, GreetCommand::$constructed, 'listing must not construct commands');
        self::assertSame(['cache:clear', 'cache:warm', 'echo-line', 'fail', 'greet'], array_keys($this->console()->commands()));
    }

    public function testVersion(): void
    {
        self::assertSame([0, 'VeyroPHP 1.2.3' . PHP_EOL, ''], $this->call(['--version']));
        self::assertSame(0, $this->call(['-V'])[0]);
    }

    public function testCommandHelp(): void
    {
        foreach ([['help', 'greet'], ['greet', '--help'], ['greet', 'x', '-h']] as $arguments) {
            [$code, $out] = $this->call($arguments);
            self::assertSame(0, $code);
            self::assertStringContainsString('Greet someone', $out);
            self::assertStringContainsString('php veyro greet <name> [<punctuation>] [--shout] [--times=TIMES]', $out);
            self::assertMatchesRegularExpression('/name\s+Who to greet/', $out);
            self::assertMatchesRegularExpression('/punctuation\s+Ending \[default: !\]/', $out);
            self::assertMatchesRegularExpression('/--times=TIMES\s+Repeat count \[default: 1\]/', $out);
            self::assertMatchesRegularExpression('/-h, --help\s+Show this help/', $out);
            self::assertStringContainsString('Example: php veyro greet Asha --times=2', $out);
        }
    }

    public function testHelpAfterDoubleDashIsAnArgument(): void
    {
        [$code, $out] = $this->call(['greet', '--', '--help']);

        self::assertSame(0, $code);
        self::assertSame('Hello, --help!' . PHP_EOL, $out);
    }

    public function testUnknownCommandSuggestsAndExitsWith2(): void
    {
        [$code, $out, $err] = $this->call(['gret']);

        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('Command "gret" is not defined. Did you mean "greet"?', $err);
        self::assertStringContainsString('Run "php veyro list" to see all commands.', $err);

        self::assertStringNotContainsString('Did you mean', $this->call(['zzzzzzzzzz'])[2]);
        self::assertSame(2, $this->call(['help', 'nope'])[0]);
    }

    public function testUsageErrorsExitWith2(): void
    {
        [$code, , $err] = $this->call(['greet']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Missing required argument <name>.', $err);
        self::assertStringContainsString('Run "php veyro help greet" for usage.', $err);

        [$code, , $err] = $this->call(['greet', 'x', '--times=0']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--times option must be a positive whole number', $err);

        [$code, , $err] = $this->call(['fail', '--mode=usage']);
        self::assertSame(2, $code);
        self::assertStringContainsString('The --mode value is not allowed here.', $err);
    }

    public function testExceptionsExitWith1AndAreLoggedWithoutTraceUnlessDebug(): void
    {
        [$code, $out, $err] = $this->call(['fail']);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('Error: Disk is full', $err);
        self::assertStringNotContainsString('RuntimeException', $err);
        self::assertStringNotContainsString('#0 ', $err);
        self::assertSame(['error Console command {command} failed: {message}'], $this->logged->getArrayCopy());

        [, , $err] = $this->call(['fail'], true);
        self::assertStringContainsString('RuntimeException at ', $err);
        self::assertStringContainsString('#0 ', $err);
    }

    public function testValidationErrorsListTheMessages(): void
    {
        [$code, , $err] = $this->call(['fail', '--mode=invalid']);

        self::assertSame(1, $code);
        self::assertStringContainsString('  The email field must be a valid email address.', $err);

        // Input mistakes are not bugs: no trace even in debug mode.
        self::assertStringNotContainsString('#0 ', $this->call(['fail', '--mode=invalid'], true)[2]);
    }

    public function testExitCodes(): void
    {
        self::assertSame(3, $this->call(['cache:warm'])[0]);

        [$code, , $err] = $this->call(['fail', '--mode=code']);
        self::assertSame(1, $code);
        self::assertStringContainsString('returned exit code 300', $err);
    }

    public function testReadsStandardInput(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, "first\n");
        rewind($stdin);

        self::assertSame('got: first' . PHP_EOL . 'then: (eof)' . PHP_EOL, $this->call(['echo-line'], false, $stdin)[1]);
    }

    public function testInvalidRegistrationsAreRejected(): void
    {
        $cases = [
            NotACommand::class => 'must be a class extending',
            UnnamedCommand::class => 'has no #[AsCommand] attribute',
            BadNameCommand::class => 'Invalid command name "Bad Name"',
            ReservedNameCommand::class => 'is reserved',
            DuplicateGreetCommand::class => 'registered twice',
            'Missing\\Command' => 'must be a class extending',
        ];

        foreach ($cases as $class => $message) {
            $commands = $class === DuplicateGreetCommand::class ? [GreetCommand::class, $class] : [$class];

            try {
                /** @phpstan-ignore argument.type (invalid on purpose) */
                new Console(new Container(), $commands);
                self::fail('Expected rejection of ' . $class);
            } catch (ConsoleException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testOutputStylesOnlyWhenDecorated(): void
    {
        $plain = new Output($this->stdout, $this->stderr);
        $colored = new Output($this->stdout, $this->stderr, true);

        self::assertSame('ok', $plain->style('ok', 'success'));
        self::assertSame("\033[32mok\033[0m", $colored->style('ok', 'success'));

        $this->expectException(ConsoleException::class);
        $plain->style('x', 'blink');
    }

    public function testTableAlignsMultibyteText(): void
    {
        (new Output($this->stdout, $this->stderr))->table(['Name', 'City'], [['Ünal', 'Jaipur'], ['Li', 'Alwar']]);
        rewind($this->stdout);

        self::assertSame(
            ['Name  City', 'Ünal  Jaipur', 'Li    Alwar', ''],
            explode(PHP_EOL, (string) stream_get_contents($this->stdout)),
        );
    }

    public function testWarningsAndErrorsGoToStderr(): void
    {
        $output = new Output($this->stdout, $this->stderr);
        $output->info('i');
        $output->success('s');
        $output->warning('w');
        $output->error('e');
        rewind($this->stdout);
        rewind($this->stderr);

        self::assertSame('i' . PHP_EOL . 's' . PHP_EOL, stream_get_contents($this->stdout));
        self::assertSame('w' . PHP_EOL . 'e' . PHP_EOL, stream_get_contents($this->stderr));
    }
}
