<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Minimal Accept-header negotiation between HTML and JSON.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class ContentNegotiation
{
    /**
     * True when the client ranks JSON (application/json or any +json type)
     * strictly above HTML. Without an Accept header, HTML is preferred.
     */
    public static function prefersJson(ServerRequestInterface $request): bool
    {
        $json = 0.0;
        $html = 0.0;

        foreach (explode(',', $request->getHeaderLine('Accept')) as $entry) {
            $parts = array_map('trim', explode(';', $entry));
            $type = strtolower(array_shift($parts));
            $quality = 1.0;

            foreach ($parts as $parameter) {
                if (preg_match('/^q=([01](?:\.\d{0,3})?)$/iD', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }

            if ($type === 'application/json' || (str_starts_with($type, 'application/') && str_ends_with($type, '+json'))) {
                $json = max($json, $quality);
            } elseif ($type === 'text/html' || $type === 'application/xhtml+xml') {
                $html = max($html, $quality);
            }
        }

        return $json > $html;
    }
}
