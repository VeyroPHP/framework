<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Validation;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Cache\ArrayStore;
use VeyroPHP\Cache\Cache;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\Session\SessionHolder;
use VeyroPHP\Session\StartSession;
use VeyroPHP\Validation\ErrorBag;
use VeyroPHP\Validation\RedirectBackOnValidationError;
use VeyroPHP\Validation\Validator;
use VeyroPHP\View\View;

/**
 * C2: the opt-in middleware sends HTML forms back with their errors and safe input.
 */
#[CoversClass(RedirectBackOnValidationError::class)]
#[CoversClass(ErrorBag::class)]
final class RedirectBackOnValidationErrorTest extends TestCase
{
    private string $views;

    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/veyrophp-redirect-back-' . bin2hex(random_bytes(4));
        mkdir($this->views);
        file_put_contents($this->views . '/contact.php', 'email=<?= $e($old(\'email\')) ?>|city=<?= $e($old(\'address.city\', \'-\')) ?>|password=<?= $e($old(\'password\', \'none\')) ?>|token=<?= $e($old(\'api_token\', \'none\')) ?>|error=<?= $e($errors->first(\'email\') ?? \'\') ?>|count=<?= count($errors) ?>');

        $container = new Container();
        $container->scoped(SessionHolder::class);
        $container->instance(View::class, new View($this->views));
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);
        $container->instance(ResponseFactory::class, $responses);

        $router = new Router();
        $router->get('/contact', static fn(View $view): string => $view->render('contact'));
        $router->post('/contact', static function (ServerRequestInterface $request): string {
            (new Validator())->validate((array) $request->getParsedBody(), ['name' => 'required|string', 'email' => 'required|email', 'address.city' => 'string']);

            return 'sent';
        });
        $router->get('/search', static function (): string {
            (new Validator())->validate([], ['q' => 'required']);

            return 'never';
        });

        $this->kernel = new HttpKernel(
            $router,
            $container,
            new HandlerInvoker($container, $responses),
            new HttpErrorResponder($responses),
            $responses,
            [
                new StartSession(new Cache(new ArrayStore(), 'app'), $container->make(SessionHolder::class)),
                new RedirectBackOnValidationError($responses, $container, '/thanks'),
            ],
        );
    }

    protected function tearDown(): void
    {
        unlink($this->views . '/contact.php');
        rmdir($this->views);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function send(string $method, string $uri, ?array $body = null, array $headers = [], array $cookies = []): ResponseInterface
    {
        $request = (new ServerRequest($method, 'http://localhost' . $uri, $headers))->withCookieParams($cookies);

        return $this->kernel->handle($body === null ? $request : $request->withParsedBody($body));
    }

    private static function sessionCookie(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, 'veyrophp_session=')) {
                return substr(explode(';', $header)[0], strlen('veyrophp_session='));
            }
        }

        return '';
    }

    public function testInvalidFormGoesBackWithErrorsAndSafeInput(): void
    {
        $response = $this->send('POST', '/contact', [
            'name' => '',
            'email' => 'not-an-email<b>',
            'address' => ['city' => 'Alwar'],
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'api_token' => 'secret-token',
            '_token' => 'csrf-token',
        ], ['Referer' => 'http://localhost/contact?ref=ad']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/contact?ref=ad', $response->getHeaderLine('Location'));

        $page = (string) $this->send('GET', '/contact', null, [], ['veyrophp_session' => self::sessionCookie($response)])->getBody();

        self::assertStringContainsString('email=not-an-email&lt;b&gt;', $page, 'old input, escaped by the template');
        self::assertStringContainsString('city=Alwar', $page, 'nested old input');
        self::assertStringContainsString('password=none', $page, 'passwords are never flashed');
        self::assertStringContainsString('token=none', $page, 'tokens are never flashed');
        self::assertStringContainsString('error=The email field must be a valid email address.', $page);
        self::assertStringContainsString('count=2', $page, 'name and email');
    }

    public function testOnlySameOriginReferersAreFollowed(): void
    {
        foreach ([
            'https://evil.example/phish' => '/thanks',
            'http://localhost.evil.example/contact' => '/thanks',
            'http://localhost:8080/contact' => '/thanks',
            'https://localhost/contact' => '/thanks',
            '//evil.example/x' => '/thanks',
            'javascript:alert(1)' => '/thanks',
            '' => '/thanks',
            'http://localhost/contact' => '/contact',
            'http://localhost' => '/',
        ] as $referer => $expected) {
            $headers = $referer === '' ? [] : ['Referer' => $referer];
            $response = $this->send('POST', '/contact', ['name' => ''], $headers);

            self::assertSame($expected, $response->getHeaderLine('Location'), 'Referer: ' . $referer);
        }
    }

    public function testJsonClientsAndGetRequestsKeepThe422Response(): void
    {
        $json = $this->send('POST', '/contact', ['name' => ''], ['Accept' => 'application/json']);
        self::assertSame(422, $json->getStatusCode());
        self::assertStringContainsString('"errors"', (string) $json->getBody());

        self::assertSame(422, $this->send('GET', '/search')->getStatusCode(), 'a GET is not redirected');
    }

    public function testAValidFormIsUntouched(): void
    {
        $response = $this->send('POST', '/contact', ['name' => 'Asha', 'email' => 'asha@example.com'], ['Referer' => 'http://localhost/contact']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('sent', (string) $response->getBody());
    }

    public function testWorksInApplicationsWithoutViews(): void
    {
        $container = new Container();
        $container->scoped(SessionHolder::class);
        $psr17 = new Psr17Factory();
        $responses = new ResponseFactory($psr17, $psr17);
        $router = new Router();
        $router->post('/api-form', static function (): string {
            (new Validator())->validate([], ['name' => 'required']);

            return 'never';
        });
        $kernel = new HttpKernel($router, $container, new HandlerInvoker($container, $responses), new HttpErrorResponder($responses), $responses, [
            new StartSession(new Cache(new ArrayStore(), 'app'), $container->make(SessionHolder::class)),
            new RedirectBackOnValidationError($responses, $container),
        ]);

        $response = $kernel->handle(new ServerRequest('POST', 'http://localhost/api-form'));

        self::assertSame(303, $response->getStatusCode(), 'no View is registered: nothing is shared, the redirect still works');
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testTheFallbackMustBeLocal(): void
    {
        $psr17 = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        new RedirectBackOnValidationError(new ResponseFactory($psr17, $psr17), new Container(), 'https://evil.example/');
    }

    public function testErrorBag(): void
    {
        $bag = new ErrorBag(['email' => ['Bad email.', 'Second.'], 'name' => ['Required.']]);

        self::assertTrue($bag->any());
        self::assertTrue($bag->has('email'));
        self::assertFalse($bag->has('phone'));
        self::assertSame('Bad email.', $bag->first('email'));
        self::assertNull($bag->first('phone'));
        self::assertSame(['Bad email.', 'Second.'], $bag->get('email'));
        self::assertSame(2, count($bag));
        self::assertFalse((new ErrorBag())->any());
    }
}
