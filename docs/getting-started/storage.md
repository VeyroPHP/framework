# File storage

`VeyroPHP\Filesystem` stores files on **disks**: local directories, or S3-compatible buckets (Amazon S3, Cloudflare R2, DigitalOcean Spaces, MinIO, Backblaze B2). Code stays the same when you move from local files to a bucket. Design: [ADR 0028](../architecture/adr/0028-file-storage.md).

## Setup

Register `VeyroPHP\Filesystem\FilesystemServiceProvider` (the starter app does) and configure `config/filesystems.php`:

| Disk | Where | Use |
| --- | --- | --- |
| `local` (default) | `storage/app/private` | Private files: invoices, exports. Serve them through a route that checks permissions. |
| `public` | `storage/app/public`, URL `APP_URL/storage/...` | Files anyone may see: avatars, product images. Run `php veyro storage:link` once per installation. |
| `s3` | a bucket | Either kind; needs `composer require guzzlehttp/guzzle` and `AWS_*` in `.env`. |

`storage:link` creates `public/storage` pointing to the `public` disk's `root` (by default `storage/app/public`): a symbolic link on Linux, and a directory junction on Windows, which needs no administrator rights. If the `public` disk is not local (for example S3), the command fails with a message, because there is nothing to link.

## Usage

```php
use VeyroPHP\Filesystem\Disk;
use VeyroPHP\Filesystem\FilesystemManager;

public function export(Disk $disk): void                       // the default disk
{
    $disk->put('exports/orders-2026-10.csv', $csv);
}

public function avatar(FilesystemManager $storage, string $path): string
{
    return $storage->disk('public')->url($path);                // https://example.com/storage/avatars/7.png
}
```

| Method | |
| --- | --- |
| `put($path, $contents)`, `putStream($path, $resource)` | Writes or replaces; missing directories are created. Local writes are atomic. |
| `get($path)`, `readStream($path)` | `FileNotFoundException` if missing. |
| `exists`, `delete`, `copy`, `move` | `delete()` returns false when there was nothing to delete. |
| `size`, `lastModified`, `mimeType` | The local type is detected from the content. |
| `files($dir, $recursive)`, `directories($dir)`, `deleteDirectory($dir)` | Paths relative to the disk, sorted. |
| `url($path)` | Public disks only. |
| `temporaryUrl($path, $seconds)` | S3 only: a presigned link to a private file, valid for up to 7 days. |

### Paths

Paths are relative, use `/`, and are checked **before** anything happens. `..` and `.` segments, backslashes, drive letters, control characters and null bytes throw `FilesystemException`. So a path built from user input cannot escape the disk, but still **never use user input as a path directly**; generate names, for example with `Ulid::generate()`.

### Uploads

Validate uploads first (rules `file`, `mimes`, `max_size`, `image`; see the hardening guide), then store them under a generated name, never the client's file name:

```php
$photo = $request->getUploadedFiles()['photo'];
$path = 'photos/' . Ulid::generate() . '.jpg';                 // after "mimes:jpg" validation
$disk->putStream($path, $photo->getStream()->detach());       // works for local and S3 disks
```

For local files only, `UploadStore` does the same in one call and derives the extension from the content.

## Private files

Do not put private files on the `public` disk. Serve them through a route that checks permissions first:

```php
$router->get('/invoices/{id}/pdf', function (int $id, Auth $auth, Disk $disk, FileResponse $files) {
    // ... check that $auth->user() may see invoice $id (404 otherwise) ...
    return $files->download($disk, "invoices/{$id}.pdf", "Invoice {$id}.pdf");
})->middleware(Authenticate::class);
```

`FileResponse::download()` and `inline()` send:

- `X-Content-Type-Options: nosniff`
- `Cache-Control: private, no-store`
- a safely encoded file name, so quotes, line breaks and non-English names cannot break the headers

`inline()` sends HTML, SVG and XML as downloads, because a browser would run scripts in them.

On S3 you can instead redirect to `$disk->temporaryUrl(...)` after the permission check. Keep the expiry short; anyone with the link can download until then.

## S3 and compatible services

```ini
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=my-app-files
AWS_ENDPOINT=                 # Cloudflare R2: https://<account>.r2.cloudflarestorage.com ; MinIO: http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=false   # true for MinIO
```

Requests are signed with AWS Signature Version 4 by VeyroPHP itself, with no AWS SDK. The signer reproduces Amazon's published example signatures exactly (`S3SignerTest`). Give the key access to this one bucket only. Error messages show S3's error code (`AccessDenied`, `NoSuchBucket`) but never the credentials.
