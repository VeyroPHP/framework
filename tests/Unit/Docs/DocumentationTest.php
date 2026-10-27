<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keeps the guides honest: code examples may only import classes that exist and
 * call method names that the framework (or PSR/PHP) defines, and relative links
 * must point at existing files. The quickstart once used a query method that
 * did not exist; this test would have caught it.
 */
#[CoversNothing]
final class DocumentationTest extends TestCase
{
    /** Methods of application objects used in examples (not framework API). */
    private const EXAMPLE_METHODS = ['warmup', 'builddaily', 'rows', 'complete', 'inactive', 'greet'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string> markdown files relative to the root
     */
    private static function documents(): array
    {
        $root = self::root();
        $files = [];
        foreach (['*.md', 'docs/*.md', 'docs/*/*.md', 'docs/*/*/*.md'] as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $file) {
                $files[] = substr($file, strlen($root) + 1);
            }
        }

        return $files;
    }

    /**
     * @return list<array{string, string}> [document, code] for every ```php block
     */
    private static function codeBlocks(): array
    {
        $blocks = [];
        foreach (self::documents() as $doc) {
            preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(self::root() . '/' . $doc), $m);
            foreach ($m[1] as $code) {
                $blocks[] = [$doc, $code];
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, true> lower-case public method names available to examples
     */
    private static function knownMethods(): array
    {
        $known = [];
        // The core and the first-party packages developed in packages/.
        foreach ([self::root() . '/src', ...(glob(self::root() . '/packages/*/src') ?: [])] as $src) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
                \assert($file instanceof \SplFileInfo);
                if ($file->getExtension() === 'php') {
                    preg_match_all('/(?:public|protected) (?:static )?function (\w+)/', (string) file_get_contents($file->getPathname()), $m);
                    foreach ($m[1] as $name) {
                        $known[strtolower($name)] = true;
                    }
                }
            }
        }

        $external = [
            \Psr\Http\Message\ServerRequestInterface::class, \Psr\Http\Message\ResponseInterface::class,
            \Psr\Http\Message\UploadedFileInterface::class, \Psr\Http\Message\UriInterface::class,
            \Psr\Http\Message\StreamInterface::class, \Psr\Http\Server\RequestHandlerInterface::class,
            \Psr\Log\LoggerInterface::class, \Psr\SimpleCache\CacheInterface::class,
            \Psr\Container\ContainerInterface::class, \DateTimeImmutable::class, \Throwable::class,
        ];
        foreach ($external as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $known[strtolower($method->getName())] = true;
            }
        }

        foreach (self::EXAMPLE_METHODS as $name) {
            $known[$name] = true;
        }

        return $known;
    }

    public function testExamplesImportExistingClasses(): void
    {
        $missing = [];

        foreach (self::codeBlocks() as [$doc, $code]) {
            preg_match_all('/\\\\?(VeyroPHP\\\\[A-Z][\w\\\\]*\w)/', $code, $m);
            foreach (array_unique($m[1]) as $class) {
                if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
                    $missing[] = $doc . ': ' . $class;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testExamplesCallExistingMethods(): void
    {
        $known = self::knownMethods();
        $unknown = [];

        foreach (self::codeBlocks() as [$doc, $code]) {
            preg_match_all('/(?:->|::)(\w+)\(/', $code, $m);
            foreach (array_unique($m[1]) as $name) {
                if (!isset($known[strtolower($name)])) {
                    $unknown[] = $doc . ': ' . $name . '()';
                }
            }
        }

        self::assertSame([], $unknown, 'A guide calls a method that does not exist. If it belongs to an example application object, add it to EXAMPLE_METHODS.');
    }

    public function testRelativeLinksPointAtExistingFiles(): void
    {
        $broken = [];

        foreach (self::documents() as $doc) {
            preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', (string) file_get_contents(self::root() . '/' . $doc), $m);
            foreach ($m[1] as $link) {
                if (preg_match('#^(https?:|mailto:)#', $link) !== 1 && !file_exists(self::root() . '/' . dirname($doc) . '/' . $link)) {
                    $broken[] = $doc . ' -> ' . $link;
                }
            }
        }

        self::assertSame([], $broken);
    }
}
