<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A handler parameter type that is built from the current request, such as a
 * FormRequest. The handler invoker calls fromRequest() for it instead of taking an
 * instance from the container.
 */
interface FromRequest
{
    public static function fromRequest(ServerRequestInterface $request, ContainerInterface $container): static;
}
