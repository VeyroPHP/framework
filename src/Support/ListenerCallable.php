<?php

declare(strict_types=1);

namespace VeyroPHP\Support;

use Closure;
use Throwable;

/**
 * @internal the rules for turning a resolved listener into a callable, shared by the event
 * dispatcher and queued listeners (SVC-8): handle() if present, otherwise __invoke().
 */
final class ListenerCallable
{
    /**
     * @param Closure(string): Throwable $error builds the caller's exception from a message
     */
    public static function from(mixed $instance, string $class, Closure $error): Closure
    {
        if (!is_object($instance)) {
            throw $error(sprintf('Listener %s did not resolve to an object.', $class));
        }

        $callable = method_exists($instance, 'handle') ? [$instance, 'handle'] : $instance;

        if (!is_callable($callable)) {
            throw $error(sprintf('Listener %s needs a public handle() or __invoke() method.', $class));
        }

        return Closure::fromCallable($callable);
    }
}
