<?php

declare(strict_types=1);

namespace VeyroPHP\Security\Audit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * Checks every locked package version against OSV.dev (ecosystem Packagist):
 * one batch query for the matching advisory ids, then each advisory's details.
 * Withdrawn advisories are skipped. Branch versions (dev-main, 1.x-dev) have no
 * release to match, so they are listed as not checked.
 *
 * Uses the given PSR-18 client, or PHP's HTTP stream wrapper when none is given.
 */
final class OsvSource implements AuditSource
{
    private const BATCH = 1000;

    public function __construct(
        private readonly ?ClientInterface $client = null,
        private readonly string $endpoint = 'https://api.osv.dev',
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'osv';
    }

    public function scan(LockFile $lock, bool $includeDev): SourceResult
    {
        $queries = [];
        $notChecked = [];

        foreach ($lock->packages($includeDev) as $package) {
            $version = ltrim($package['version'], 'vV');

            if (str_starts_with($package['version'], 'dev-') || str_ends_with($version, '-dev')) {
                $notChecked[] = "{$package['name']} ({$package['version']}: branch version)";

                continue;
            }

            $queries[] = ['package' => ['ecosystem' => 'Packagist', 'name' => $package['name']], 'version' => $version];
        }

        try {
            /** @var array<string, list<string>> $matches advisory id => package names */
            $matches = [];

            foreach (array_chunk($queries, self::BATCH) as $chunk) {
                foreach ($this->batch($chunk) as $index => $ids) {
                    foreach ($ids as $id) {
                        $matches[$id][] = $chunk[$index]['package']['name'];
                    }
                }
            }

            $advisories = [];
            ksort($matches);

            foreach ($matches as $id => $packages) {
                $vuln = $this->request('GET', '/v1/vulns/' . rawurlencode($id), null);

                if (is_string($vuln['withdrawn'] ?? null)) {
                    continue;
                }

                foreach (array_unique($packages) as $package) {
                    $advisories[] = self::advisory($id, $package, $lock, $vuln);
                }
            }
        } catch (AuditException $e) {
            return SourceResult::failed($this->name(), $e->getMessage());
        }

        return SourceResult::ok($this->name(), $advisories, [], $notChecked);
    }

    /**
     * @param list<array{package: array{ecosystem: string, name: string}, version: string}> $queries
     * @return array<int, list<string>> query index => advisory ids
     */
    private function batch(array $queries): array
    {
        $ids = [];
        $data = $this->request('POST', '/v1/querybatch', ['queries' => $queries]);
        $results = $data['results'] ?? null;

        if (!is_array($results) || count($results) !== count($queries)) {
            throw new AuditException('OSV returned an unexpected batch response.');
        }

        foreach (array_values($results) as $index => $result) {
            $ids[$index] = [];

            // A query with very many advisories is paged; follow its token.
            while (is_array($result)) {
                foreach (is_array($result['vulns'] ?? null) ? $result['vulns'] : [] as $vuln) {
                    if (is_array($vuln) && is_string($vuln['id'] ?? null)) {
                        $ids[$index][] = $vuln['id'];
                    }
                }

                $token = $result['next_page_token'] ?? null;

                if (!is_string($token) || $token === '') {
                    break;
                }

                $page = $this->request('POST', '/v1/querybatch', ['queries' => [$queries[$index] + ['page_token' => $token]]]);
                $result = is_array($page['results'] ?? null) ? ($page['results'][0] ?? null) : null;
            }
        }

        return $ids;
    }

    /**
     * @param array<mixed> $vuln
     */
    private static function advisory(string $id, string $package, LockFile $lock, array $vuln): Advisory
    {
        $aliases = array_values(array_filter(is_array($vuln['aliases'] ?? null) ? $vuln['aliases'] : [], 'is_string'));
        $title = is_string($vuln['summary'] ?? null) ? $vuln['summary'] : '';

        if ($title === '' && is_string($vuln['details'] ?? null)) {
            $title = strtok($vuln['details'], "\n") ?: '';
        }

        $severity = Severity::Unknown;

        foreach ([$vuln['database_specific'] ?? null, ...(is_array($vuln['affected'] ?? null) ? array_column($vuln['affected'], 'database_specific') : [])] as $specific) {
            if (is_array($specific) && Severity::parse($specific['severity'] ?? null) !== Severity::Unknown) {
                $severity = Severity::parse($specific['severity']);

                break;
            }
        }

        return new Advisory(
            $package,
            $lock->version($package) ?? '',
            $id,
            $aliases,
            $title,
            $severity,
            'https://osv.dev/vulnerability/' . rawurlencode($id),
            null,
            ['osv'],
        );
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = rtrim($this->endpoint, '/') . $path;
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        [$status, $content] = $this->client !== null ? $this->viaClient($method, $url, $json) : $this->viaStream($method, $url, $json);

        if ($status !== 200) {
            throw new AuditException("OSV request {$method} {$path} failed with HTTP {$status}.");
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new AuditException("OSV request {$method} {$path} returned invalid JSON.");
        }

        return $data;
    }

    /**
     * @return array{int, string}
     */
    private function viaClient(string $method, string $url, ?string $json): array
    {
        \assert($this->client !== null);
        $factory = new Psr17Factory();
        $request = $factory->createRequest($method, $url)->withHeader('Accept', 'application/json');

        if ($json !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($factory->createStream($json));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            throw new AuditException('OSV could not be reached: ' . $e->getMessage(), 0, $e);
        }

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    /**
     * @return array{int, string}
     */
    private function viaStream(string $method, string $url, ?string $json): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Accept: application/json\r\n" . ($json !== null ? "Content-Type: application/json\r\n" : ''),
            'content' => $json ?? '',
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new AuditException('OSV could not be reached at ' . $this->endpoint . '.');
        }

        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
        $first = is_array($headers) && is_string($headers[0] ?? null) ? $headers[0] : '';
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $first, $m) === 1 ? (int) $m[1] : 0;

        return [$status, $content];
    }
}
