<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use Closure;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal sends one request to a third-party HTTP API (mail, SMS) and turns a network
 * error or an answer outside 2xx into the caller's exception, with the API's own short
 * error text and no control characters.
 */
final class ApiCall
{
    /**
     * @param Closure(string $message, int $httpStatus, ?Throwable $previous): Throwable $error
     *        $httpStatus is 0 when the API could not be reached
     */
    public static function send(ClientInterface $client, RequestInterface $request, string $service, string $what, Closure $error): ResponseInterface
    {
        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw $error(sprintf('%s could not be reached: %s', $service, self::clean($e->getMessage())), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        throw $error(sprintf('%s refused the %s (HTTP %d): %s', $service, $what, $status, self::reason($response)), $status, null);
    }

    /**
     * The API's own error text ("message", "Message" or "error" in a JSON body), shortened.
     */
    private static function reason(ResponseInterface $response): string
    {
        $body = substr((string) $response->getBody(), 0, 2000);
        $data = json_decode($body, true);

        if (is_array($data)) {
            foreach (['message', 'Message', 'error'] as $key) {
                if (is_string($data[$key] ?? null)) {
                    return self::clean($data[$key]);
                }
            }
        }

        return $body === '' ? $response->getReasonPhrase() : self::clean($body);
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '...' : $text;
    }
}
