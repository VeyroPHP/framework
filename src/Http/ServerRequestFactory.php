<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creates the PSR-7 server request from PHP's superglobals.
 *
 * This is the only place in the framework that reads $_SERVER, $_GET, $_POST,
 * $_COOKIE and $_FILES.
 *
 * The scheme and port come from the server (HTTPS, REQUEST_SCHEME, SERVER_PORT),
 * never from a client's X-Forwarded-Proto. The header stays in the request, and
 * TrustProxies applies it when the direct peer is a trusted proxy.
 */
final class ServerRequestFactory
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $request = $creator->fromGlobals();

        // nyholm/psr7-server takes the scheme from X-Forwarded-Proto of any client and then
        // ignores HTTPS and SERVER_PORT (VQ-068). Build the URI again without that header.
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $server = $_SERVER;
            unset($server['HTTP_X_FORWARDED_PROTO']);
            $server['REQUEST_METHOD'] ??= 'GET';
            $request = $request->withUri($creator->fromArrays($server)->getUri(), true);
        }

        return $request;
    }
}
