<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Convenience methods for common responses, built on PSR-17 factories.
 */
final class ResponseFactory
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function html(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->make($html, $status, $headers, 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function text(string $text, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->make($text, $status, $headers, 'text/plain; charset=utf-8');
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @throws JsonException when $data cannot be encoded
     */
    public function json(mixed $data, int $status = 200, array $headers = [], int $flags = self::JSON_FLAGS): ResponseInterface
    {
        $json = json_encode($data, $flags | JSON_THROW_ON_ERROR);

        return $this->make($json, $status, $headers, 'application/json');
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function empty(int $status = 204, array $headers = []): ResponseInterface
    {
        return $this->make('', $status, $headers);
    }

    /**
     * Redirect to a path on this site. Absolute and protocol-relative URLs
     * ("https://…", "//…", "/\…") are rejected unless $allowExternal is true,
     * which prevents open redirects from user-supplied targets such as "?next=".
     *
     * @throws \InvalidArgumentException for an unsafe target or a non-3xx status
     */
    public function redirect(string $location, int $status = 302, bool $allowExternal = false): ResponseInterface
    {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException('Redirect status must be 3xx.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $location) === 1) {
            throw new \InvalidArgumentException('Redirect target contains control characters.');
        }

        if (!$allowExternal && !self::isLocalPath($location)) {
            throw new \InvalidArgumentException('Redirect target must be a path on this site, such as "/dashboard".');
        }

        return $this->empty($status, ['Location' => $location]);
    }

    /**
     * True for paths like "/a/b?c" that stay on this site.
     */
    public static function isLocalPath(string $location): bool
    {
        return str_starts_with($location, '/')
            && !str_starts_with($location, '//')
            && !str_starts_with($location, '/\\')
            && !str_contains($location, '\\');
    }

    /**
     * Returns the response with an empty body, e.g. for HEAD requests.
     */
    public function withoutBody(ResponseInterface $response): ResponseInterface
    {
        return $response->withBody($this->streams->createStream(''));
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function make(string $body, int $status, array $headers, ?string $contentType = null): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withBody($this->streams->createStream($body));

        // The default type comes first, so a Content-Type in $headers (any case) replaces it.
        if ($contentType !== null) {
            $response = $response->withHeader('Content-Type', $contentType);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
