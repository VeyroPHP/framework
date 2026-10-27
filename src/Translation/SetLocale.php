<?php

declare(strict_types=1);

namespace VeyroPHP\Translation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Session\Session;

/**
 * Global middleware (after StartSession) that picks the request's language
 * from, in this order:
 *
 * 1. "?lang=hi" in the URL (only a supported locale; remembered in the session),
 * 2. the session ("locale"; set it yourself for a language menu),
 * 3. the browser's Accept-Language header (q-values respected),
 * 4. the default locale.
 *
 * Only locales in the supported list are ever used. Adds Content-Language to the response.
 */
final class SetLocale implements MiddlewareInterface
{
    public const SESSION_KEY = 'locale';

    /**
     * @param list<string> $supported e.g. ['en', 'hi']
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $supported,
        private readonly string $default,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        $session = $session instanceof Session ? $session : null;
        $locale = $this->fromQuery($request, $session)
            ?? $this->supported($session?->get(self::SESSION_KEY))
            ?? $this->fromHeader($request->getHeaderLine('Accept-Language'))
            ?? $this->default;

        $this->translator->setLocale($locale);

        return $handler->handle($request)->withHeader('Content-Language', $locale);
    }

    private function fromQuery(ServerRequestInterface $request, ?Session $session): ?string
    {
        $locale = $this->supported($request->getQueryParams()['lang'] ?? null);

        if ($locale !== null && $session !== null) {
            $session->put(self::SESSION_KEY, $locale);
        }

        return $locale;
    }

    private function fromHeader(string $header): ?string
    {
        $candidates = [];

        foreach (explode(',', $header) as $i => $part) {
            if (preg_match('/^\s*([A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*)\s*(?:;\s*q=([01](?:\.\d{1,3})?))?\s*$/D', $part, $m) !== 1) {
                continue;
            }

            $quality = isset($m[2]) ? (float) $m[2] : 1.0;

            if ($quality > 0) {
                $candidates[] = [$m[1], $quality, $i];
            }
        }

        usort($candidates, static fn(array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($candidates as [$tag]) {
            // "hi-IN" matches "hi_IN" or "hi".
            $exact = $this->supported(str_replace('-', '_', $tag)) ?? $this->supported($tag);
            $language = $this->supported(strtolower(explode('-', $tag)[0]));

            if ($exact !== null || $language !== null) {
                return $exact ?? $language;
            }
        }

        return null;
    }

    private function supported(mixed $locale): ?string
    {
        if (!is_string($locale)) {
            return null;
        }

        foreach ($this->supported as $candidate) {
            if (strcasecmp($candidate, $locale) === 0) {
                return $candidate;
            }
        }

        return null;
    }
}
