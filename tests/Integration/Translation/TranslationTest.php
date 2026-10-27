<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Application\Application;
use VeyroPHP\Cache\CacheServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Output;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\HttpServiceProvider;
use VeyroPHP\Routing\Router;
use VeyroPHP\Session\SessionServiceProvider;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Testing\TestClient;
use VeyroPHP\Translation\Console\MissingCommand;
use VeyroPHP\Translation\PluralSelector;
use VeyroPHP\Translation\SetLocale;
use VeyroPHP\Translation\TranslationException;
use VeyroPHP\Translation\TranslationLoader;
use VeyroPHP\Translation\TranslationServiceProvider;
use VeyroPHP\Translation\Translator;
use VeyroPHP\Validation\ValidationException;
use VeyroPHP\Validation\Validator;
use VeyroPHP\View\View;
use VeyroPHP\View\ViewServiceProvider;

#[CoversClass(Translator::class)]
#[CoversClass(TranslationLoader::class)]
#[CoversClass(PluralSelector::class)]
#[CoversClass(SetLocale::class)]
#[CoversClass(TranslationServiceProvider::class)]
#[CoversClass(MissingCommand::class)]
#[CoversClass(TranslationException::class)]
final class TranslationTest extends TestCase
{
    private string $root;

    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-lang-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/lang/en', 0777, true);
        mkdir($this->root . '/lang/hi', 0777, true);
        mkdir($this->root . '/views', 0777, true);

        file_put_contents($this->root . '/lang/en/shop.php', "<?php return [\n"
            . "'greeting' => 'Hello, :name! :NAME? :Name.',\n"
            . "'cart' => ['items' => '{0} Your cart is empty|{1} One item|[2,*] :count items', 'title' => 'Cart'],\n"
            . "'apples' => 'apple|apples',\n"
            . "'only_en' => 'English only',\n"
            . "'names' => ':name and :name_full',\n"
            . '];');
        file_put_contents($this->root . '/lang/hi/shop.php', "<?php return [\n"
            . "'greeting' => 'नमस्ते, :name!',\n"
            . "'cart' => ['items' => '{0} आपकी टोकरी खाली है|[1,*] :count सामान'],\n"
            . "'apples' => 'सेब (एक)|सेब (कई)',\n"
            . '];');
        file_put_contents($this->root . '/lang/hi.json', json_encode(['Welcome back, :name' => 'वापसी पर स्वागत है, :name'], JSON_UNESCAPED_UNICODE));
        file_put_contents($this->root . '/lang/en/validation.php', "<?php return ['required' => 'The :field field is required.'];");
        file_put_contents($this->root . '/lang/hi/validation.php', "<?php return ['required' => ':field भरना ज़रूरी है।', 'min.string' => ':field में कम से कम :min अक्षर', 'attributes' => ['email' => 'ईमेल']];");
        file_put_contents($this->root . '/views/page.php', '<p><?= $e($t("shop.greeting", ["name" => $name])) ?> <?= $e($tc("shop.cart.items", $count)) ?></p>');

        $this->app = new Application($this->root);
        $this->app->container()->instance(Config::class, new Config([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'locales' => ['en', 'hi']],
            'cache' => ['default' => 'array', 'prefix' => 't', 'stores' => ['array' => ['driver' => 'array']]],
            'view' => ['path' => 'views'],
        ]));
        foreach ([new CacheServiceProvider(), new SessionServiceProvider(), new HttpServiceProvider([], [StartSession::class, SetLocale::class]), new ViewServiceProvider(), new TranslationServiceProvider(), new ConsoleServiceProvider(TranslationServiceProvider::COMMANDS)] as $provider) {
            $this->app->register($provider);
        }
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    private function t(): Translator
    {
        return $this->app->container()->make(Translator::class);
    }

    public function testLookupFallbackAndReplacements(): void
    {
        $t = $this->t();

        self::assertSame('Hello, asha! ASHA? Asha.', $t->get('shop.greeting', ['name' => 'asha']));
        self::assertSame('Cart', $t->get('shop.cart.title'));
        self::assertSame('Asha and Asha Verma', $t->get('shop.names', ['name' => 'Asha', 'name_full' => 'Asha Verma']), ':name does not break :name_full');

        $t->setLocale('hi');
        self::assertSame('नमस्ते, आशा!', $t->get('shop.greeting', ['name' => 'आशा']));
        self::assertSame('Cart', $t->get('shop.cart.title'), 'missing in hi: falls back to en');
        self::assertSame('English only', $t->get('shop.only_en'));
        self::assertSame('वापसी पर स्वागत है, Ravi', $t->get('Welcome back, :name', ['name' => 'Ravi']), 'JSON key');
        self::assertSame('Untranslated text', $t->get('Untranslated text'), 'unknown text is returned as is');
        self::assertSame('shop.nothing', $t->get('shop.nothing'));
        self::assertSame('shop.cart', $t->get('shop.cart'), 'an array is not a message');
        self::assertTrue($t->has('shop.greeting'));
        self::assertFalse($t->has('shop.only_en'));
        self::assertSame('Hello, x! X? X.', $t->get('shop.greeting', ['name' => 'x'], 'en'));
    }

    public function testPlurals(): void
    {
        $t = $this->t();

        self::assertSame('Your cart is empty', $t->choice('shop.cart.items', 0));
        self::assertSame('One item', $t->choice('shop.cart.items', 1));
        self::assertSame('5 items', $t->choice('shop.cart.items', 5));
        self::assertSame('apple', $t->choice('shop.apples', 1));
        self::assertSame('apples', $t->choice('shop.apples', 0), 'English: 0 is plural');

        $t->setLocale('hi');
        self::assertSame('आपकी टोकरी खाली है', $t->choice('shop.cart.items', 0));
        self::assertSame('1 सामान', $t->choice('shop.cart.items', 1));
        self::assertSame('सेब (एक)', $t->choice('shop.apples', 0), 'Hindi: 0 and 1 are singular');
        self::assertSame('सेब (कई)', $t->choice('shop.apples', 2));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function pluralCases(): iterable
    {
        yield 'explicit list' => ['{1,2,3} few|[4,*] many', 2, 'en', 'few'];
        yield 'open start range' => ['[*,0] none|[1,*] some', -3, 'en', 'none'];
        yield 'english one' => ['one|other', 1, 'en_GB', 'one'];
        yield 'french zero is one' => ['un|plusieurs', 0, 'fr', 'un'];
        yield 'japanese single form' => ['個|個たち', 5, 'ja', '個'];
        yield 'more forms than needed' => ['a|b|c', 7, 'en', 'b'];
        yield 'only explicit, no match' => ['{0} none|{1} one', 9, 'en', '{1} one'];
    }

    #[DataProvider('pluralCases')]
    public function testPluralSelector(string $message, int $count, string $locale, string $expected): void
    {
        self::assertSame($expected, PluralSelector::choose($message, $count, $locale));
    }

    public function testInvalidNamesAreRejected(): void
    {
        $loader = $this->app->container()->make(TranslationLoader::class);

        foreach ([static fn() => $loader->group('../..', 'x'), static fn() => $loader->group('en', '../secrets'), static fn() => $loader->json('en/../../x'), fn() => $this->t()->setLocale('en; DROP')] as $call) {
            try {
                $call();
                self::fail('expected TranslationException');
            } catch (TranslationException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame('../secrets.key', $this->t()->get('../secrets.key'), 'keys that are not "group.key" are plain text');
        self::assertSame(['en', 'hi'], $loader->locales());
    }

    // Requests

    public function testSetLocaleSources(): void
    {
        $router = $this->app->container()->make(Router::class);
        $router->get('/hello', static fn(Translator $t): string => $t->locale() . ':' . $t->get('shop.greeting', ['name' => 'A']));
        $client = new TestClient($this->app->container()->make(HttpKernel::class));

        $response = $client->get('/hello');
        self::assertSame('en:Hello, A! A? A.', (string) $response->getBody());
        self::assertSame('en', $response->getHeaderLine('Content-Language'));

        self::assertSame('hi', explode(':', (string) $client->get('/hello', ['Accept-Language' => 'fr-FR, hi-IN;q=0.8, en;q=0.5'])->getBody())[0], 'best supported browser language');
        self::assertSame('en', explode(':', (string) $client->get('/hello', ['Accept-Language' => 'hi;q=0, en;q=0.1'])->getBody())[0], 'q=0 means "not this one"');
        self::assertSame('en', explode(':', (string) $client->get('/hello?lang=xx', ['Accept-Language' => 'de'])->getBody())[0], 'unsupported values are ignored');

        self::assertSame('hi:नमस्ते, A!', (string) $client->get('/hello?lang=hi')->getBody());
        self::assertSame('hi', explode(':', (string) $client->get('/hello', ['Accept-Language' => 'en'])->getBody())[0], 'remembered in the session, ahead of the header');
    }

    public function testViewsGetTranslationHelpers(): void
    {
        $view = $this->app->container()->make(View::class);

        self::assertSame('<p>Hello, &lt;b&gt;! &lt;B&gt;? &lt;b&gt;. 3 items</p>', $view->render('page', ['name' => '<b>', 'count' => 3]));

        $this->t()->setLocale('hi');
        self::assertSame('<p>नमस्ते, Asha! आपकी टोकरी खाली है</p>', $view->render('page', ['name' => 'Asha', 'count' => 0]));

        $this->expectException(\VeyroPHP\View\Exception\ViewException::class);
        $view->render('page', ['t' => 'shadowing the helper']);
    }

    public function testValidationMessagesAreTranslated(): void
    {
        $validate = fn(): array => $this->app->container()->make(Validator::class)->errors([], ['email' => 'required', 'full_name' => 'required']);

        self::assertSame(['email' => ['The email field is required.'], 'full_name' => ['The full name field is required.']], $validate());

        $this->t()->setLocale('hi');
        self::assertSame(['email' => ['ईमेल भरना ज़रूरी है।'], 'full_name' => ['full name भरना ज़रूरी है।']], $validate());
        self::assertSame(['email' => ['ईमेल में कम से कम 8 अक्षर']], $this->app->container()->make(Validator::class)->errors(['email' => 'a@b'], ['email' => 'string|min:8']), 'flat keys with dots');

        $custom = $this->app->container()->make(Validator::class);
        try {
            $custom->validate([], ['email' => 'required'], ['email.required' => 'Tell us your email.']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['email' => ['Tell us your email.']], $e->errors(), 'custom messages still win');
        }
    }

    public function testMissingCommand(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $this->app->container()->make(Console::class)->run(['lang:missing'], new Output($out, $err));
        rewind($out);
        rewind($err);
        $text = stream_get_contents($out) . stream_get_contents($err);

        self::assertSame(1, $code);
        self::assertStringContainsString('hi: 3 missing', (string) $text);
        self::assertStringContainsString('shop.cart.title', (string) $text);
        self::assertStringContainsString('shop.only_en', (string) $text);
        self::assertStringContainsString('shop.names', (string) $text);
    }
}
