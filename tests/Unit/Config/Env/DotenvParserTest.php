<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Config\Env;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Config\Env\DotenvParser;
use VeyroPHP\Config\Exception\EnvException;

#[CoversClass(DotenvParser::class)]
#[CoversClass(EnvException::class)]
final class DotenvParserTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function parse(string $contents): array
    {
        return (new DotenvParser())->parse($contents);
    }

    public function testParsesSimpleAssignments(): void
    {
        self::assertSame(
            ['APP_NAME' => 'VeyroPHP', 'APP_PORT' => '8080', 'EMPTY' => ''],
            self::parse("APP_NAME=VeyroPHP\nAPP_PORT=8080\nEMPTY=\n"),
        );
    }

    public function testIgnoresBlankLinesAndComments(): void
    {
        self::assertSame(['A' => '1'], self::parse("\n# comment\n   # indented comment\n\nA=1\n"));
    }

    public function testTrimsWhitespaceAroundNameAndUnquotedValue(): void
    {
        self::assertSame(['A' => 'hello world'], self::parse('  A  =   hello world   '));
    }

    public function testInlineCommentAfterUnquotedValue(): void
    {
        self::assertSame(['A' => 'value', 'URL' => 'http://x/#anchor'], self::parse("A=value # comment\nURL=http://x/#anchor"));
    }

    public function testExportPrefix(): void
    {
        self::assertSame(['A' => '1'], self::parse('export A=1'));
    }

    public function testValueMayContainEqualsSign(): void
    {
        self::assertSame(['KEY' => 'base64:abc=='], self::parse('KEY=base64:abc=='));
    }

    public function testSingleQuotedValueIsLiteral(): void
    {
        self::assertSame(['A' => 'it costs $5 \\n # not a comment'], self::parse("A='it costs \$5 \\n # not a comment' # comment"));
    }

    public function testDoubleQuotedValueSupportsEscapes(): void
    {
        self::assertSame(
            ['A' => "line1\nline2\t\"q\" \\ \$HOME \r"],
            self::parse('A="line1\nline2\t\"q\" \\\\ \$HOME \r"'),
        );
    }

    public function testDoubleQuotedValueKeepsSpacesAndHash(): void
    {
        self::assertSame(['A' => '  padded # kept  '], self::parse('A="  padded # kept  "'));
    }

    public function testHandlesWindowsAndOldMacLineEndings(): void
    {
        self::assertSame(['A' => '1', 'B' => '2', 'C' => '3'], self::parse("A=1\r\nB=2\rC=3"));
    }

    public function testStripsUtf8ByteOrderMark(): void
    {
        self::assertSame(['A' => '1'], self::parse("\xEF\xBB\xBFA=1"));
    }

    public function testLowercaseAndUnderscoreNames(): void
    {
        self::assertSame(['_private' => 'x', 'lower_1' => 'y'], self::parse("_private=x\nlower_1=y"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'missing equals' => ["A=1\nJUSTANAME", 'line 2: expected NAME=value'];
        yield 'name starts with digit' => ['1A=x', 'variable names must match'];
        yield 'name with dash' => ['APP-NAME=x', 'variable names must match'];
        yield 'empty name' => ['=x', 'variable names must match'];
        yield 'unterminated double quote' => ['A="abc', 'unterminated double-quoted'];
        yield 'unterminated single quote' => ["A='abc", 'unterminated single-quoted'];
        yield 'text after closing quote' => ['A="abc" def', 'unexpected characters after closing quote'];
        yield 'quote in middle of unquoted' => ['A=ab"c', 'quotes are only allowed around the whole value'];
        yield 'unknown escape' => ['A="\q"', 'unknown escape sequence'];
        yield 'interpolation unquoted' => ['A=${B}', 'interpolation is not supported'];
        yield 'interpolation double quoted' => ['A="x${B}"', 'interpolation is not supported'];
    }

    #[DataProvider('invalidInput')]
    public function testRejectsInvalidSyntax(string $contents, string $message): void
    {
        $this->expectException(EnvException::class);
        $this->expectExceptionMessage($message);

        self::parse($contents);
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->expectException(EnvException::class);
        $this->expectExceptionMessage('"A" is defined twice in .env (again on line 3)');

        self::parse("A=1\nB=2\nA=3");
    }

    public function testErrorMessagesDoNotContainValues(): void
    {
        try {
            self::parse('DB_PASSWORD="s3cr3t');
            self::fail('Expected an EnvException.');
        } catch (EnvException $e) {
            self::assertStringNotContainsString('s3cr3t', $e->getMessage());
        }
    }

    public function testSingleQuotedInterpolationSyntaxIsLiteral(): void
    {
        self::assertSame(['A' => '${B}'], self::parse("A='\${B}'"));
    }
}
