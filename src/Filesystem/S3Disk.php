<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use Closure;
use DateTimeImmutable;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SimpleXMLElement;
use VeyroPHP\Support\Extensions;

/**
 * Files in an S3 bucket or an S3-compatible service (Cloudflare R2,
 * DigitalOcean Spaces, MinIO, Backblaze B2), signed with SigV4 and sent through
 * any PSR-18 HTTP client (for example Guzzle). No AWS SDK needed.
 *
 * - Files are private unless the bucket policy says otherwise; share them with
 *   temporaryUrl() (presigned, up to 7 days).
 * - url() needs a public base URL (bucket website, CDN) in the config.
 * - Errors become FilesystemException with S3's error code; credentials never appear.
 */
final class S3Disk implements Disk
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private readonly string $base;

    /**
     * @param string $endpoint e.g. "https://s3.ap-south-1.amazonaws.com" or an R2/MinIO endpoint
     * @param bool $pathStyle "endpoint/bucket/key" instead of "bucket.endpoint/key" (MinIO, some R2 setups)
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly S3Signer $signer,
        private readonly string $bucket,
        string $endpoint,
        bool $pathStyle = false,
        private readonly string $prefix = '',
        private readonly ?string $publicUrl = null,
        ?Closure $clock = null,
    ) {
        // S3 answers in XML; fail when the disk is built, not on its first listing.
        Extensions::require('simplexml', 'the S3 disk');

        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1) {
            throw new FilesystemException(sprintf('Invalid bucket name "%s".', $bucket));
        }

        $parts = parse_url($endpoint);

        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || !isset($parts['host'])) {
            throw new FilesystemException('The S3 endpoint must be an http(s) URL.');
        }

        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->base = $pathStyle
            ? $parts['scheme'] . '://' . $host . '/' . $bucket
            : $parts['scheme'] . '://' . $bucket . '.' . $host;
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    public function exists(string $path): bool
    {
        $response = $this->send('HEAD', $this->key(PathNormalizer::file($path)));

        return match ($response->getStatusCode()) {
            200 => true,
            404 => false,
            default => throw $this->error($response, 'check', $path),
        };
    }

    public function get(string $path): string
    {
        return (string) $this->fetch($path)->getBody();
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new FilesystemException('Could not open a temporary stream.');
        }

        $body = $this->fetch($path)->getBody();

        while (!$body->eof()) {
            fwrite($stream, $body->read(1 << 16));
        }

        rewind($stream);

        return $stream;
    }

    public function put(string $path, string $contents): void
    {
        $this->expect($this->send('PUT', $this->key(PathNormalizer::file($path)), $contents, [
            'Content-Type' => self::guessType($path),
        ]), [200], 'write', $path);
    }

    public function putStream(string $path, $stream): void
    {
        if (!is_resource($stream)) {
            throw new FilesystemException('putStream() needs an open stream.');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new FilesystemException(sprintf('Could not read the stream for "%s".', $path));
        }

        $this->put($path, $contents);
    }

    public function delete(string $path): bool
    {
        $key = $this->key(PathNormalizer::file($path));

        if (!$this->exists($path)) {
            return false;
        }

        $this->expect($this->send('DELETE', $key), [204, 200], 'delete', $path);

        return true;
    }

    public function copy(string $from, string $to): void
    {
        $source = '/' . $this->bucket . '/' . self::encode($this->key(PathNormalizer::file($from)));
        $response = $this->send('PUT', $this->key(PathNormalizer::file($to)), '', ['x-amz-copy-source' => $source]);

        if ($response->getStatusCode() === 404) {
            throw FileNotFoundException::at($from);
        }

        $this->expect($response, [200], 'copy', $from);
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function size(string $path): int
    {
        return (int) $this->head($path)->getHeaderLine('Content-Length');
    }

    public function lastModified(string $path): int
    {
        $time = strtotime($this->head($path)->getHeaderLine('Last-Modified'));

        return $time === false ? 0 : $time;
    }

    public function mimeType(string $path): string
    {
        $type = $this->head($path)->getHeaderLine('Content-Type');

        return $type === '' ? 'application/octet-stream' : strtolower(trim(explode(';', $type)[0]));
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        [$files] = $this->listObjects($directory, $recursive);

        return $files;
    }

    public function directories(string $directory = ''): array
    {
        [, $directories] = $this->listObjects($directory, false);

        return $directories;
    }

    public function deleteDirectory(string $directory): void
    {
        if (PathNormalizer::directory($directory) === '') {
            throw new FilesystemException('Refusing to delete the root of the disk.');
        }

        foreach ($this->files($directory, true) as $file) {
            $this->expect($this->send('DELETE', $this->key($file)), [204, 200], 'delete', $file);
        }
    }

    public function url(string $path): string
    {
        if ($this->publicUrl === null) {
            throw new FilesystemException('This disk has no public "url"; use temporaryUrl() for private files.');
        }

        return rtrim($this->publicUrl, '/') . '/' . self::encode(PathNormalizer::file($path));
    }

    /**
     * A link that works without credentials for $seconds (at most 7 days).
     */
    public function temporaryUrl(string $path, int $seconds = 3600): string
    {
        return $this->signer->presign('GET', $this->base . '/' . self::encode($this->key(PathNormalizer::file($path))), $seconds, ($this->clock)());
    }

    private function key(string $path): string
    {
        $prefix = trim($this->prefix, '/');

        return $prefix === '' ? $path : ($path === '' ? $prefix : $prefix . '/' . $path);
    }

    private function fetch(string $path): ResponseInterface
    {
        $response = $this->send('GET', $this->key(PathNormalizer::file($path)));

        if ($response->getStatusCode() === 404) {
            throw FileNotFoundException::at($path);
        }

        return $this->expect($response, [200], 'read', $path);
    }

    private function head(string $path): ResponseInterface
    {
        $response = $this->send('HEAD', $this->key(PathNormalizer::file($path)));

        if ($response->getStatusCode() === 404) {
            throw FileNotFoundException::at($path);
        }

        return $this->expect($response, [200], 'read', $path);
    }

    /**
     * @return array{list<string>, list<string>} files and directories, relative to the disk
     */
    private function listObjects(string $directory, bool $recursive): array
    {
        $relative = PathNormalizer::directory($directory);
        $prefix = $this->key($relative);
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $strip = trim($this->prefix, '/') === '' ? 0 : strlen(trim($this->prefix, '/')) + 1;
        $files = $directories = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if (!$recursive) {
                $query['delimiter'] = '/';
            }
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $response = $this->expect($this->send('GET', '', '', [], $query), [200], 'list', $directory);
            $xml = self::xml((string) $response->getBody());

            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) $object->Key;
                if (!str_ends_with($key, '/')) {
                    $files[] = substr($key, $strip);
                }
            }

            foreach ($xml->CommonPrefixes ?? [] as $common) {
                $directories[] = rtrim(substr((string) $common->Prefix, $strip), '/');
            }

            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        sort($files);
        sort($directories);

        return [$files, $directories];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function send(string $method, string $key, string $body = '', array $headers = [], array $query = []): ResponseInterface
    {
        $url = $this->base . '/' . self::encode($key);

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requests->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== '' || $method === 'PUT') {
            $request = $request->withBody($this->streams->createStream($body));
        }

        $request = $this->signer->sign($request, hash('sha256', $body), ($this->clock)());

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FilesystemException('The storage service could not be reached: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<int> $statuses
     */
    private function expect(ResponseInterface $response, array $statuses, string $action, string $path): ResponseInterface
    {
        return in_array($response->getStatusCode(), $statuses, true) ? $response : throw $this->error($response, $action, $path);
    }

    private function error(ResponseInterface $response, string $action, string $path): FilesystemException
    {
        $code = '';
        $body = (string) $response->getBody();

        if ($body !== '' && str_starts_with(ltrim($body), '<')) {
            try {
                $code = (string) self::xml($body)->Code;
            } catch (FilesystemException) {
            }
        }

        return new FilesystemException(sprintf('Could not %s "%s": S3 answered %d%s.', $action, $path, $response->getStatusCode(), $code === '' ? '' : ' ' . $code));
    }

    private static function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        return $xml instanceof SimpleXMLElement ? $xml : throw new FilesystemException('The storage service sent an invalid answer.');
    }

    private static function encode(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private static function guessType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'json' => 'application/json',
            'html' => 'text/html; charset=utf-8',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
