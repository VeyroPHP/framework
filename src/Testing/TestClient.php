<?php

declare(strict_types=1);

namespace VeyroPHP\Testing;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Sends requests to the application in-process, like a browser: cookies are
 * kept between requests and the CSRF token of the last page can be reused.
 * No web server and no network are involved.
 *
 *     $client = new TestClient($app->container()->make(HttpKernel::class));
 *     $client->get('/login');
 *     $response = $client->post('/login', ['email' => 'a@b.test', 'password' => 'secret'], withToken: true);
 *     $client->follow($response); // GET the Location
 *
 * It does not depend on PHPUnit, so it works with any test runner.
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    private string $lastBody = '';

    /**
     * @param array<string, string> $server server params for every request, e.g. REMOTE_ADDR
     */
    public function __construct(
        private readonly RequestHandlerInterface $kernel,
        private readonly array $server = ['REMOTE_ADDR' => '127.0.0.1'],
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, null, $headers);
    }

    /**
     * @param array<string, mixed> $body form fields
     * @param array<string, string> $headers
     * @param bool $withToken add "_token" from the last page's form
     */
    public function post(string $uri, array $body = [], array $headers = [], bool $withToken = false): ResponseInterface
    {
        if ($withToken) {
            $body += ['_token' => $this->formToken()];
        }

        return $this->request('POST', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>|null $body parsed body (form fields), or null
     * @param array<string, string> $headers
     */
    public function request(string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri, $headers, null, '1.1', $this->server))->withCookieParams($this->cookies);

        // Like a web server filling $_GET: route handlers read query parameters, not the raw URI.
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        if ($body !== null) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        $response = $this->kernel->handle($request);
        $this->storeCookies($response);
        $this->lastBody = (string) $response->getBody();

        return $response;
    }

    /**
     * Follows a redirect with GET.
     *
     * @throws RuntimeException when the response is not a redirect
     */
    public function follow(ResponseInterface $response): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');

        if ($location === '' || $response->getStatusCode() < 300 || $response->getStatusCode() > 399) {
            throw new RuntimeException(sprintf('Expected a redirect, got status %d.', $response->getStatusCode()));
        }

        $path = (string) parse_url($location, PHP_URL_PATH);
        $query = parse_url($location, PHP_URL_QUERY);

        return $this->get(($path === '' ? '/' : $path) . (is_string($query) ? '?' . $query : ''));
    }

    /**
     * The value of the hidden "_token" field on the last page.
     *
     * @throws RuntimeException when the last page has no token field
     */
    public function formToken(): string
    {
        if (preg_match('/<input[^>]*name="_token"[^>]*value="([^"]*)"/', $this->lastBody, $match) !== 1
            && preg_match('/<input[^>]*value="([^"]*)"[^>]*name="_token"/', $this->lastBody, $match) !== 1) {
            throw new RuntimeException('The last response has no <input name="_token"> field; GET a page with a form first.');
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @return array<string, string> cookies currently stored
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    /**
     * Drops one cookie, e.g. the session cookie to test "remember me" after a browser restart.
     */
    public function forgetCookie(string $name): void
    {
        unset($this->cookies[$name]);
    }

    private function storeCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $parts = array_map('trim', explode(';', $header));
            $pair = explode('=', (string) array_shift($parts), 2);

            if (count($pair) !== 2 || $pair[0] === '') {
                continue;
            }

            [$name, $value] = $pair;
            $expired = $value === '';

            foreach ($parts as $attribute) {
                if (preg_match('/^max-age=(-?\d+)$/iD', $attribute, $m) === 1 && (int) $m[1] <= 0) {
                    $expired = true;
                }
            }

            if ($expired) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }
    }
}
