<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Http\Exception\EmitterException;

/**
 * Sends a PSR-7 response to the client through PHP's SAPI.
 *
 * Responses with status 1xx, 204 or 304 are sent without a body. The body is
 * streamed in chunks so large responses are not copied into memory at once.
 */
final class ResponseEmitter
{
    /** @var Closure(string, bool, int): void */
    private readonly Closure $header;

    /** @var Closure(): (array{string, int}|null) */
    private readonly Closure $headersSent;

    /** @var Closure(string): void */
    private readonly Closure $output;

    /** @var Closure(string): void */
    private readonly Closure $removeHeader;

    /**
     * The closures exist for testing; the defaults use header(), headers_sent(), echo and header_remove().
     *
     * @param Closure(string, bool, int): void|null $header
     * @param Closure(): (array{string, int}|null)|null $headersSent returns [file, line] when output already started
     * @param Closure(string): void|null $output
     * @param Closure(string): void|null $removeHeader
     */
    public function __construct(
        ?Closure $header = null,
        ?Closure $headersSent = null,
        ?Closure $output = null,
        private readonly int $chunkSize = 8192,
        ?Closure $removeHeader = null,
    ) {
        $this->removeHeader = $removeHeader ?? static function (string $name): void {
            header_remove($name);
        };
        $this->header = $header ?? static function (string $line, bool $replace, int $status): void {
            header($line, $replace, $status);
        };
        $this->headersSent = $headersSent ?? static function (): ?array {
            return headers_sent($file, $line) ? [$file, $line] : null;
        };
        $this->output = $output ?? static function (string $chunk): void {
            echo $chunk;
        };
    }

    /**
     * @throws EmitterException when headers were already sent
     */
    public function emit(ResponseInterface $response): void
    {
        $sent = ($this->headersSent)();

        if ($sent !== null) {
            throw EmitterException::headersAlreadySent($sent[0], $sent[1]);
        }

        // PHP adds "X-Powered-By: PHP/x.y.z" when expose_php is on; do not advertise versions.
        if (!$response->hasHeader('X-Powered-By')) {
            ($this->removeHeader)('X-Powered-By');
        }

        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase();

        ($this->header)(
            sprintf('HTTP/%s %d%s', $response->getProtocolVersion(), $status, $reason !== '' ? ' ' . $reason : ''),
            true,
            $status,
        );

        foreach ($response->getHeaders() as $name => $values) {
            $first = true;

            foreach ($values as $value) {
                ($this->header)(sprintf('%s: %s', $name, $value), $first, $status);
                $first = false;
            }
        }

        if ($status < 200 || $status === 204 || $status === 304) {
            return;
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read($this->chunkSize);

            if ($chunk === '') {
                break;
            }

            ($this->output)($chunk);
        }
    }
}
