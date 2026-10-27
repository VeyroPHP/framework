# ADR 0028: File storage

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 9. Laravel's `Storage` wraps Flysystem, and S3 needs the AWS SDK (about 50 MB). VeyroPHP had only `UploadStore` for local uploads.

## Decisions

- **A small `Disk` interface** (read, write, streams, copy/move/delete, metadata, listing, URLs). There are two implementations: `LocalDisk` and `S3Disk`. Other backends can implement the interface.
- **Paths are validated, not repaired** (`PathNormalizer`): `..`, `.`, empty segments, backslashes, drive letters and control characters are errors before any file system call. So `../../.env` can never resolve to anything.
- **Local writes are atomic**: written to a temporary file in the same directory, then renamed into place.
- **Own SigV4 signer instead of the AWS SDK.** About 150 lines, through any PSR-18 client, so `psr/http-client` (interfaces only) becomes a dependency and Guzzle is suggested.
  - It is verified against Amazon's published examples: GET object, PUT object with a `$` key, bucket lifecycle, list objects, and a presigned URL. All five signatures match exactly.
  - It works with S3-compatible services (endpoint and path-style options).
- **Downloads through `FileResponse`:**
  - RFC 6266/5987 file names with an ASCII fallback, so header injection through names is impossible.
  - `nosniff` and `no-store` headers.
  - Inline display refused for HTML, SVG, XML and JavaScript, which browsers would execute.
- **`storage:link`** uses a symlink, or on Windows a directory junction, because symlinks there need administrator rights or developer mode.
- Not done:
  - visibility/ACL management
  - multipart uploads for files over 5 GB, and streaming uploads (S3 bodies are read into memory)
  - an FTP/SFTP driver

## Verified

- `S3SignerTest`: the five Amazon example signatures, and presign limits.
- `S3DiskTest` (5 tests) against an in-memory S3 that requires signed requests with correct payload hashes:
  - all operations, including server-side copy and encoded keys
  - listing with three pages, prefix, delimiter and path style
  - recursive directory delete
  - public and temporary URLs
  - errors with the S3 code and without secrets
  - network failures
  - invalid settings and paths
- `LocalDiskTest` (18 tests):
  - read, write and metadata; streams of 100 KB; no temporary files left behind
  - copy, move and delete; listings; recursive delete; the root protected
  - URL encoding
  - nine unsafe paths rejected for every operation, with the secret file next to the root untouched
  - missing files
  - download and inline responses, including a hostile file name and an SVG
  - provider configuration
  - `storage:link` on Windows (junction created, file reachable, link removed without touching the files)

**`storage:link` verified on Linux** (2026-10-26, Ubuntu 24.04 in WSL2): it created a real symlink, a file written to `storage/app/public` was read through `public/storage`, and removing the link left the file in place.

Not verified against a real S3-compatible server. MinIO no longer publishes community binaries (download returned 410). SeaweedFS 4.51 was downloaded (SHA-256 checked) but running it needs the owner's permission. A real S3/R2 bucket needs an account.
