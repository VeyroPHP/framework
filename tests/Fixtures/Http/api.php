<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for API tests: records requests and gives a fixed answer.
 */
final class FakeMailApi implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private ResponseInterface|\Throwable $answer = new Response(200, [], '{"id":"1"}')) {}

    public function answer(ResponseInterface|\Throwable $answer): void
    {
        $this->answer = $answer;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }

    public function last(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1];
    }
}

final class FakeNetworkError extends \RuntimeException implements ClientExceptionInterface {}
