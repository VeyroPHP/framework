<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Console\Argument;
use VeyroPHP\Console\ConsoleException;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Suggest;
use VeyroPHP\Console\UsageException;

#[CoversClass(Input::class)]
#[CoversClass(Argument::class)]
#[CoversClass(Option::class)]
#[CoversClass(Suggest::class)]
final class InputTest extends TestCase
{
    /**
     * @return list<Argument|Option>
     */
    private static function definition(): array
    {
        return [
            Argument::required('name'),
            Argument::optional('email', '', 'none'),
            Option::flag('force'),
            Option::value('step', '', '1'),
            Option::value('role'),
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private static function parse(array $tokens): Input
    {
        return Input::parse($tokens, self::definition());
    }

    public function testDefaults(): void
    {
        $input = self::parse(['Asha']);

        self::assertSame('Asha', $input->argument('name'));
        self::assertSame('none', $input->argument('email'));
        self::assertFalse($input->flag('force'));
        self::assertSame('1', $input->option('step'));
        self::assertNull($input->option('role'));
    }

    public function testOptionForms(): void
    {
        $input = self::parse(['--force', 'Asha', '--step=3', 'a@b.test', '--role', 'admin']);

        self::assertSame('Asha', $input->argument('name'));
        self::assertSame('a@b.test', $input->argument('email'));
        self::assertTrue($input->flag('force'));
        self::assertSame(3, $input->positiveInt('step'));
        self::assertSame('admin', $input->option('role'));
    }

    public function testValuesMayContainEqualsAndBeEmpty(): void
    {
        $input = self::parse(['x', '--role=a=b', '--step=']);

        self::assertSame('a=b', $input->option('role'));
        self::assertSame('', $input->option('step'));
    }

    public function testRepeatedOptionKeepsLastValue(): void
    {
        self::assertSame('editor', self::parse(['x', '--role=admin', '--role=editor'])->option('role'));
    }

    public function testDoubleDashMakesEverythingPositional(): void
    {
        $input = self::parse(['--', '--force', '-x']);

        self::assertSame('--force', $input->argument('name'));
        self::assertSame('-x', $input->argument('email'));
        self::assertFalse($input->flag('force'));
    }

    public function testSingleDashIsAValue(): void
    {
        self::assertSame('-', self::parse(['-'])->argument('name'));
        self::assertSame('-5', self::parse(['x', '--role', '-5'])->option('role'));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function usageErrors(): iterable
    {
        yield 'missing argument' => [[], 'Missing required argument <name>.'];
        yield 'too many' => [['a', 'b', 'c'], 'Too many arguments: "c" was not expected.'];
        yield 'unknown option' => [['a', '--colour'], 'Unknown option "--colour".'];
        yield 'typo suggestion' => [['a', '--forse'], 'Unknown option "--forse". Did you mean --force?'];
        yield 'short option' => [['a', '-f'], 'Unknown option "-f". Options are written as --name.'];
        yield 'flag with value' => [['a', '--force=yes'], 'The --force option does not take a value.'];
        yield 'missing value at end' => [['a', '--role'], 'The --role option needs a value.'];
        yield 'missing value before option' => [['a', '--role', '--force'], 'The --role option needs a value.'];
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('usageErrors')]
    public function testUsageErrors(array $tokens, string $message): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage($message);
        self::parse($tokens);
    }

    public function testPositiveInt(): void
    {
        self::assertSame(12, self::parse(['x', '--step=12'])->positiveInt('step'));
        self::assertNull(self::parse(['x'])->positiveInt('role'));

        foreach (['0', '-1', '1.5', 'abc', '007x'] as $bad) {
            try {
                self::parse(['x', '--step=' . $bad])->positiveInt('step');
                self::fail('Expected rejection of ' . $bad);
            } catch (UsageException $e) {
                self::assertSame('The --step option must be a positive whole number.', $e->getMessage());
            }
        }
    }

    public function testAccessingUndefinedNamesIsAProgrammingError(): void
    {
        $input = self::parse(['x']);

        foreach ([static fn() => $input->argument('nope'), static fn() => $input->option('nope'), static fn() => $input->flag('step'), static fn() => $input->option('force')] as $call) {
            try {
                $call();
                self::fail('Expected ConsoleException');
            } catch (ConsoleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testReadLine(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, "secret-pass\r\nsecond\n");
        rewind($stdin);

        $input = Input::parse([], [], $stdin);

        self::assertSame('secret-pass', $input->readLine());
        self::assertSame('second', $input->readLine());
        self::assertNull($input->readLine());
    }

    public function testDefinitionNamesAreValidated(): void
    {
        foreach ([static fn() => Argument::required('Bad Name'), static fn() => Option::flag('-x'), static fn() => Option::flag('help')] as $make) {
            try {
                $make();
                self::fail('Expected ConsoleException');
            } catch (ConsoleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSynopsis(): void
    {
        self::assertSame('<name>', Argument::required('name')->synopsis());
        self::assertSame('[<email>]', Argument::optional('email')->synopsis());
        self::assertSame('[--force]', Option::flag('force')->synopsis());
        self::assertSame('[--dry-run=DRY_RUN]', Option::value('dry-run')->synopsis());
    }

    public function testSuggest(): void
    {
        self::assertSame('migrate', Suggest::closest('migrat', ['migrate', 'migrate:status']));
        self::assertSame('migrate:status', Suggest::closest('migrate:stat', ['migrate', 'migrate:status']));
        self::assertSame('route:list', Suggest::closest('route:lst', ['route:list', 'serve']));
        self::assertNull(Suggest::closest('completely-different', ['migrate', 'serve']));
        self::assertNull(Suggest::closest('x', []));
    }
}
