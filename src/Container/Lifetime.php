<?php

declare(strict_types=1);

namespace VeyroPHP\Container;

/**
 * How long a resolved service is reused.
 */
enum Lifetime
{
    /** A new value on every resolution. */
    case Transient;

    /** One value for the lifetime of the container. */
    case Singleton;

    /** One value until Container::resetScope() is called, e.g. once per request. */
    case Scoped;
}
