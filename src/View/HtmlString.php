<?php

declare(strict_types=1);

namespace VeyroPHP\View;

use Stringable;

/**
 * HTML that is already safe (rendered templates, slots, sections). $e() prints
 * it unchanged instead of escaping it a second time.
 *
 * Never wrap user input in it: that is how cross-site scripting happens.
 */
final class HtmlString implements Stringable
{
    public function __construct(private readonly string $html) {}

    public function __toString(): string
    {
        return $this->html;
    }
}
