<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Maintenance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\ClientIp;
use VeyroPHP\Http\ContentNegotiation;
use VeyroPHP\Http\Cookie;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\ResponseFactory;

/**
 * Answers 503 while MaintenanceMode is down ("php veyro down"), except for:
 *
 * - paths in $except (default "/up", so health checks keep reporting),
 * - client addresses in the allow list given to "down --allow",
 * - browsers that opened /_maintenance/<secret> once ("down --secret"): they get an
 *   HttpOnly cookie holding the secret, compared by hash on later requests.
 *
 * The 503 is built here, not thrown, so a long downtime does not write one error
 * log entry per request. It carries Retry-After and Cache-Control: no-store, so no
 * cache keeps the maintenance page after the site is back.
 *
 * Register it as a global middleware after TrustProxies (for the client address)
 * and SecurityHeaders, and before StartSession (no sessions are created while down).
 */
final class BlockDuringMaintenance implements MiddlewareInterface
{
    public const COOKIE = 'veyrophp_maintenance';

    public const BYPASS_PREFIX = '/_maintenance/';

    /**
     * @param list<string> $except exact paths (without the base path) that stay reachable
     */
    public function __construct(
        private readonly MaintenanceMode $mode,
        private readonly ClientIp $clientIp,
        private readonly ResponseFactory $responses,
        private readonly array $except = ['/up'],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // One read of the state per request; every question below is asked of it (HTTP-7).
        $state = $this->mode->current();

        if ($state === null) {
            return $handler->handle($request);
        }

        $path = $request->getAttribute(HttpKernel::PATH_ATTRIBUTE);
        $path = is_string($path) ? $path : $request->getUri()->getPath();

        if (str_starts_with($path, self::BYPASS_PREFIX)) {
            $secret = substr($path, strlen(self::BYPASS_PREFIX));

            if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret) === 1 && $state->matchesSecret($secret)) {
                $cookie = new Cookie(self::COOKIE, $secret, 12 * 3600, '/', null, $request->getUri()->getScheme() === 'https', true, 'lax');

                // Away from the secret URL at once, so it does not stay in the address bar or Referer.
                return $this->responses->redirect('/', 303)->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
            }

            return $this->unavailable($request, $state);
        }

        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;

        if (in_array($path, $this->except, true)
            || $state->allows($this->clientIp->of($request))
            || (is_string($cookie) && $state->matchesSecret($cookie))) {
            return $handler->handle($request);
        }

        return $this->unavailable($request, $state);
    }

    private function unavailable(ServerRequestInterface $request, MaintenanceState $state): ResponseInterface
    {
        $retry = $state->retry;
        $message = $state->message !== '' ? $state->message : 'This site is down for maintenance. Please try again soon.';
        $headers = ['Retry-After' => (string) $retry, 'Cache-Control' => 'no-store'];

        if (ContentNegotiation::prefersJson($request)) {
            return $this->responses->json(['error' => ['status' => 503, 'message' => $message]], 503, $headers);
        }

        $text = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>503 | Down for maintenance</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'font-family:system-ui,sans-serif;background:#f7f7f8;color:#3d4550;padding:1rem}main{max-width:32rem;text-align:center}'
            . '@media (prefers-color-scheme:dark){body{background:#0d1117;color:#c9d1d9}}</style></head>'
            . '<body><main><h1>Down for maintenance</h1><p>' . $text . '</p></main></body></html>';

        return $this->responses->html($html, 503, $headers);
    }
}
