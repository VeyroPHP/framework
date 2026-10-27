<?php

declare(strict_types=1);

namespace VeyroPHP\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Http\ClientIp;

/**
 * Restores the original scheme (and port) when HTTPS ends at a proxy or load
 * balancer, using X-Forwarded-Proto and X-Forwarded-Port.
 *
 * Without it, an app behind a TLS-terminating proxy sees every request as
 * http: session cookies lose the Secure flag and HSTS is never sent.
 *
 * The headers are used only when the direct peer is a trusted proxy
 * (security.trusted_proxies), the same rule as for X-Forwarded-For. With no
 * trusted proxies this middleware changes nothing. X-Forwarded-Host is not
 * used, because a forged host would end up in redirects and generated URLs.
 *
 * Register it as the outermost global middleware, before SecurityHeaders and
 * StartSession, so they see the corrected scheme.
 */
final class TrustProxies implements MiddlewareInterface
{
    public function __construct(private readonly ClientIp $clientIp) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($this->apply($request));
    }

    public function apply(ServerRequestInterface $request): ServerRequestInterface
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!is_string($remote) || !$this->clientIp->isTrusted($remote)) {
            return $request;
        }

        // With several proxies the header is a list; the first entry was set by the outermost one.
        $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));

        if ($proto !== 'http' && $proto !== 'https') {
            return $request;
        }

        $uri = $request->getUri()->withScheme($proto);
        $port = trim(explode(',', $request->getHeaderLine('X-Forwarded-Port'))[0]);

        if (ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535) {
            $uri = $uri->withPort((int) $port);
        } elseif ($uri->getPort() !== null) {
            // The internal port (e.g. 8080) is not the port the client used.
            $uri = $uri->withPort(null);
        }

        return $request->withUri($uri, true);
    }
}
