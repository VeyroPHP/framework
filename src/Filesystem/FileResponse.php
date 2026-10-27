<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends a stored file to the browser, for private files that need a
 * permission check first.
 *
 *     $router->get('/invoices/{id}/pdf', function (int $id, FileResponse $files, ...) {
 *         // ... check that the user may see invoice $id ...
 *         return $files->download($storage->disk(), "invoices/{$id}.pdf", "Invoice {$id}.pdf");
 *     });
 *
 * - The file name is sent safely (RFC 6266/5987), so names with quotes,
 *   line breaks or non-ASCII characters cannot break or inject headers.
 * - "X-Content-Type-Options: nosniff" stops browsers from treating an upload
 *   as HTML; inline() refuses types that browsers would run (HTML, SVG, XML).
 */
final class FileResponse
{
    private const UNSAFE_INLINE = ['text/html', 'image/svg+xml', 'application/xhtml+xml', 'text/xml', 'application/xml', 'application/javascript', 'text/javascript'];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /**
     * Asks the browser to save the file.
     */
    public function download(Disk $disk, string $path, ?string $name = null): ResponseInterface
    {
        return $this->respond($disk, $path, 'attachment', $name ?? basename($path));
    }

    /**
     * Shows the file in the browser (images, PDFs). Types that could run code are sent as downloads.
     */
    public function inline(Disk $disk, string $path, ?string $name = null): ResponseInterface
    {
        $type = $disk->mimeType($path);
        $disposition = in_array($type, self::UNSAFE_INLINE, true) ? 'attachment' : 'inline';

        return $this->respond($disk, $path, $disposition, $name ?? basename($path));
    }

    private function respond(Disk $disk, string $path, string $disposition, string $name): ResponseInterface
    {
        $type = $disk->mimeType($path);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) $disk->size($path))
            ->withHeader('Content-Disposition', self::disposition($disposition, $name))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streams->createStreamFromResource($disk->readStream($path)));
    }

    /**
     * An ASCII fallback plus the exact UTF-8 name.
     */
    public static function disposition(string $type, string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $name);
        $fallback = (string) preg_replace('/[^\x20-\x7E]|["%]/u', '_', $name);
        $fallback = $fallback === '' ? 'download' : $fallback;

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $fallback, rawurlencode($name));
    }
}
