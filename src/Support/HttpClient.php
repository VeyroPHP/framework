<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;

/**
 * @internal the PSR-18 client for features that call HTTP APIs (mail and SMS gateways):
 * the one bound as ClientInterface, else Guzzle when it is installed.
 */
final class HttpClient
{
    /**
     * @param \Closure(string): \Throwable $error builds the caller's exception from a message
     */
    public static function resolve(ContainerInterface $container, string $feature, \Closure $error, float $timeout = 15.0): ClientInterface
    {
        if ($container->has(ClientInterface::class)) {
            $client = $container->get(ClientInterface::class);

            if ($client instanceof ClientInterface) {
                return $client;
            }
        }

        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client(['timeout' => $timeout, 'http_errors' => false]);
        }

        throw $error(sprintf('%s needs an HTTP client: run "composer require guzzlehttp/guzzle" or bind a PSR-18 ClientInterface.', $feature));
    }
}
