<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use VeyroPHP\Exception\HasErrorDetails;
use VeyroPHP\Exception\HasHttpStatus;
use VeyroPHP\Exception\Renderer\HtmlRenderer;
use VeyroPHP\Exception\ThrowableFormatter;
use VeyroPHP\Http\Exception\HttpException;

/**
 * Converts exceptions thrown while handling a request into error responses.
 *
 * - HasHttpStatus exceptions (HttpException, ModelNotFoundException, ...): their
 *   status and message (meant for clients); HttpException headers are added.
 * - Anything else: 500. Logged at "critical" level. Without debug, the
 *   response contains no message, class, path or trace.
 * - HttpException with a 5xx status is logged at "error" level; 4xx is not logged.
 * - JSON is returned when the Accept header prefers it, otherwise HTML.
 */
final class HttpErrorResponder
{
    public function __construct(
        private readonly ResponseFactory $responses,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $debug = false,
    ) {}

    public function respond(Throwable $throwable, ServerRequestInterface $request): ResponseInterface
    {
        $isHttp = $throwable instanceof HasHttpStatus;
        $status = $isHttp ? $throwable->getStatusCode() : 500;
        $headers = $throwable instanceof HttpException ? $throwable->getHeaders() : [];
        $message = $isHttp ? $throwable->getMessage() : HttpException::reasonPhrase(500);

        $this->report($throwable, $isHttp, $status, $request);

        $details = $throwable instanceof HasErrorDetails ? $throwable->getDetails() : [];

        if (ContentNegotiation::prefersJson($request)) {
            $error = ['status' => $status, 'message' => $message] + $details;

            if ($this->debug && !$isHttp) {
                $error['exception'] = ThrowableFormatter::toArray($throwable);
            }

            return $this->responses->json(['error' => $error], $status, $headers, ResponseFactory::JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $html = $this->debug && !$isHttp
            ? (new HtmlRenderer())->render($throwable, true)
            : self::page($status, $message, self::errorList($details));

        return $this->responses->html($html, $status, $headers);
    }

    private function report(Throwable $throwable, bool $isHttp, int $status, ServerRequestInterface $request): void
    {
        if ($isHttp && $status < 500) {
            return;
        }

        $this->logger->log(
            $isHttp ? 'error' : 'critical',
            'Request {method} {path} failed: {class}: {message}',
            [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'class' => $throwable::class,
                'message' => $throwable->getMessage(),
                'exception' => $throwable,
            ],
        );
    }

    /**
     * @param string $extra already-escaped HTML shown under the title
     */
    private static function page(int $status, string $message, string $extra = ''): string
    {
        $title = htmlspecialchars(sprintf('%d | %s', $status, $message), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f7f7f8;color:#59636e}
            h1{font-size:1.125rem;font-weight:500;letter-spacing:.02em}
            @media (prefers-color-scheme:dark){body{background:#0d1117;color:#9198a1}}
            </style>
            </head>
            <body><main><h1>{$title}</h1>{$extra}</main></body>
            </html>
            HTML;
    }

    /**
     * Escaped HTML list of validation-style errors (['errors' => [field => [messages]]]).
     *
     * @param array<string, mixed> $details
     */
    private static function errorList(array $details): string
    {
        $errors = $details['errors'] ?? null;

        if (!is_array($errors) || $errors === []) {
            return '';
        }

        $items = '';
        foreach ($errors as $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                if (is_string($message)) {
                    $items .= '<li>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</li>';
                }
            }
        }

        return '<ul>' . $items . '</ul>';
    }
}
