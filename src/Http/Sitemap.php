<?php

declare(strict_types=1);

namespace VeyroPHP\Http;

use DateTimeInterface;

/**
 * Builds a sitemap.xml (sitemaps.org protocol) with correct XML escaping.
 *
 *     $router->get('/sitemap.xml', static function (ResponseFactory $responses, View $view) {
 *         $xml = (new Sitemap())
 *             ->add('https://example.com/', new DateTimeImmutable('2026-10-26'), 'weekly', 1.0)
 *             ->toXml();
 *
 *         return $responses->text($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
 *     });
 *
 * Locations must be absolute http(s) URLs ($urls->absolute() builds them).
 * At most 50,000 URLs, the protocol's limit for one file.
 */
final class Sitemap
{
    public const MAX_URLS = 50_000;

    private const FREQUENCIES = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /** @var list<array{string, ?DateTimeInterface, ?string, ?float}> */
    private array $urls = [];

    /**
     * @throws \InvalidArgumentException for a relative or non-http URL, an unknown frequency or a priority outside 0..1
     * @throws \LengthException above MAX_URLS
     */
    public function add(string $location, ?DateTimeInterface $lastModified = null, ?string $changeFrequency = null, ?float $priority = null): self
    {
        if (count($this->urls) >= self::MAX_URLS) {
            throw new \LengthException(sprintf('A sitemap holds at most %d URLs; split it with a sitemap index.', self::MAX_URLS));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $location) === 1 || filter_var($location, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($location, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException(sprintf('Sitemap locations must be absolute http or https URLs, "%s" given.', preg_replace('/[\x00-\x1F\x7F]/', '?', $location)));
        }

        if ($changeFrequency !== null && !in_array($changeFrequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException(sprintf('Change frequency must be one of %s.', implode(', ', self::FREQUENCIES)));
        }

        if ($priority !== null && ($priority < 0.0 || $priority > 1.0)) {
            throw new \InvalidArgumentException('Priority must be between 0.0 and 1.0.');
        }

        $this->urls[] = [$location, $lastModified, $changeFrequency, $priority];

        return $this;
    }

    public function toXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($this->urls as [$location, $lastModified, $frequency, $priority]) {
            $xml .= '  <url>' . "\n" . '    <loc>' . self::escape($location) . '</loc>' . "\n";

            if ($lastModified !== null) {
                $xml .= '    <lastmod>' . $lastModified->format(DateTimeInterface::ATOM) . '</lastmod>' . "\n";
            }

            if ($frequency !== null) {
                $xml .= '    <changefreq>' . $frequency . '</changefreq>' . "\n";
            }

            if ($priority !== null) {
                $xml .= '    <priority>' . rtrim(rtrim(number_format($priority, 1, '.', ''), '0'), '.') . '</priority>' . "\n";
            }

            $xml .= '  </url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
