<?php

declare(strict_types=1);

namespace VeyroPHP\Exception\Renderer;

use Throwable;
use VeyroPHP\Exception\ThrowableFormatter;

/**
 * Self-contained HTML error page. All dynamic values are HTML-escaped.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class HtmlRenderer implements ExceptionRenderer
{
    public function render(Throwable $throwable, bool $debug): string
    {
        $body = $debug ? self::debugBody($throwable) : self::productionBody();
        $title = $debug ? self::e($throwable::class) : 'Server Error';

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$title}</title>
            <style>
            body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f7f7f8;color:#1f2328}
            main{max-width:960px;margin:0 auto;padding:48px 16px}
            h1{font-size:1.5rem;margin:0 0 8px}
            .muted{color:#59636e}
            pre{background:#fff;border:1px solid #d1d9e0;border-radius:6px;padding:16px;overflow-x:auto;font-size:.85rem;line-height:1.5}
            section+section{margin-top:32px}
            </style>
            </head>
            <body>
            <main>
            {$body}
            </main>
            </body>
            </html>
            HTML;
    }

    public function contentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    private static function productionBody(): string
    {
        return '<h1>500 | Server Error</h1><p class="muted">Something went wrong on our side. Please try again later.</p>';
    }

    private static function debugBody(Throwable $throwable): string
    {
        $sections = [];
        $current = $throwable;

        for ($depth = 0; $current !== null && $depth <= 10; $depth++) {
            $sections[] = sprintf(
                '<section><p class="muted">%s</p><h1>%s</h1><p>%s</p><p class="muted">%s:%d</p><pre>%s</pre></section>',
                $depth === 0 ? 'Uncaught exception' : 'Caused by',
                self::e($current::class),
                self::e($current->getMessage()),
                self::e($current->getFile()),
                $current->getLine(),
                self::e(implode("\n", ThrowableFormatter::frames($current))),
            );
            $current = $current->getPrevious();
        }

        return implode("\n", $sections);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
