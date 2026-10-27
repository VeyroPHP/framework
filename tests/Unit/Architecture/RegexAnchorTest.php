<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * In PHP regexes, "$" also matches before a final "\n" unless the D modifier is set,
 * so /^[a-z]+$/ accepts "abc\n". Every pattern in the framework that ends in "$"
 * must therefore use D (or m, where per-line matching is meant). See VQ-001 in
 * docs/research/issue-registry.md.
 */
final class RegexAnchorTest extends TestCase
{
    /**
     * Literals that may end in "$" without D, as "relative path: literal" => reason.
     * Only where accepting a trailing newline is the safer outcome.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        "src/Port/SourceTree.php: '/\\.(pem|key|p12|pfx)\$/'" => 'deny-list of secret files: matching "key.pem\n" too keeps such a file out of a port',
    ];

    /**
     * String literals in $code that end a regex with an unescaped "$" but have neither D nor m.
     * Covers whole patterns ('/^\d+$/') and the closing part of built ones ('$#').
     *
     * @return list<array{int, string}> line and literal as written in the source
     */
    private static function unanchored(string $code): array
    {
        $found = [];

        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = self::value($token[1]);

            // "$", the closing delimiter, modifiers; the backslashes before "$" must be even (unescaped).
            // "%" is not treated as a delimiter: sprintf() formats such as '$2y$%02d$%s' end in "$%s".
            if (preg_match('{(\\\\*)\$[/#~!]([a-zA-Z]*)\z}', $value, $match) === 1
                && strlen($match[1]) % 2 === 0
                && !str_contains($match[2], 'D')
                && !str_contains($match[2], 'm')) {
                $found[] = [$token[2], $token[1]];
            }
        }

        return $found;
    }

    /**
     * The runtime value of a string literal without interpolation. Only the escapes
     * that matter for a regex are decoded: \\ and the quote in single quotes, stripcslashes() in double quotes.
     */
    private static function value(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? (string) preg_replace('{\\\\([\\\\\'])}', '$1', $body)
            : stripcslashes($body);
    }

    public function testDetectorFindsMissingDollarEndOnly(): void
    {
        $code = <<<'PHP'
            <?php
            preg_match('/^[a-z]+$/', $a);
            preg_match('/^[a-z]+$/u', $a);
            preg_match('#^' . $regex . '$#', $a);
            preg_match('/^[a-z]+$/D', $a);
            preg_match('/^[a-z]+$/uD', $a);
            preg_match('/^key=(.*)$/m', $a);
            preg_match('/^[a-z]+\z/', $a);
            echo 'costs $5';
            preg_match('/(?<!\\\\)\$/', $a);
            preg_match('/a\\\\$/', $a);
            preg_match("/^\\d+$/", $a);
            sprintf('$2y$%02d$%s', $cost, $salt);
            PHP;

        self::assertSame(
            [[2, "'/^[a-z]+\$/'"], [3, "'/^[a-z]+\$/u'"], [4, "'\$#'"], [11, "'/a\\\\\\\\\$/'"], [12, '"/^\\\\d+$/"']],
            self::unanchored($code),
            'line 10 ends in an escaped "$" (a literal dollar sign), not an anchor',
        );
    }

    public function testFrameworkPatternsEndOnlyAtTheEndOfTheSubject(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];
        $directories = array_merge([$root . '/src'], glob($root . '/packages/*/src') ?: []);

        foreach ($directories as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                \assert($file instanceof \SplFileInfo);

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                foreach (self::unanchored((string) file_get_contents($file->getPathname())) as [$line, $literal]) {
                    if (!array_key_exists($relative . ': ' . $literal, self::ALLOWED)) {
                        $violations[] = sprintf('%s:%d %s', $relative, $line, $literal);
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Add the D modifier (or use \z) so "$" does not accept a trailing newline.');
    }
}
