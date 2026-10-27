# ADR 0021: Encryption

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 2. Password reset links, email verification, queued jobs and API tokens need a way to protect values that leave the server. Laravel uses AES-256-CBC with an HMAC (or AES-GCM) and `serialize()`.

## Decisions

- **AES-256-GCM through OpenSSL** (`ext-openssl` becomes a requirement). Authenticated encryption in one standard construction; no hand-made encrypt-then-MAC. Libsodium's XChaCha20-Poly1305 has a larger nonce, but the sodium extension is missing on some hosts, while OpenSSL is everywhere PHP runs.
- **Format**: URL-safe base64 of version byte `0x01`, 12-byte random nonce, 16-byte tag and ciphertext. The version byte allows a different algorithm later without breaking old values.
- **Purpose as associated data** (`veyrophp:v1:<purpose>`): values cannot be moved between features. Laravel has no equivalent in its encrypter.
- **JSON instead of `serialize()`**: decrypting can never create objects, so a leaked key does not become code execution through object injection (a known problem with Laravel's `APP_KEY` leaks).
- **Key format** `base64:` + 32 bytes, as in Laravel, so it is familiar. Raw or short keys are rejected with a message naming `key:generate`.
- **Rotation**: `APP_PREVIOUS_KEYS` (comma-separated); all keys are tried when decrypting, only the first encrypts. `key:generate --force` moves the old key there instead of discarding it.
- **Lazy**: the service is created on first use, so applications without a key still boot.
- **No key id in the payload**: trying a few keys costs microseconds, and a key id would tell an attacker which key was used.

## Verified

`EncrypterTest` (31 tests): round trips for every JSON type and binary strings; independent decryption with plain OpenSSL to confirm the documented format; every single changed byte detected; truncation and extension detected; purpose binding; wrong key; rotation; invalid payloads; no objects recreated; errors never contain key or value; key parsing; the service provider including a missing key. `KeyGenerateTest` (7 tests) on real `.env` files, including CRLF line endings (which exposed a bug: `$` in a multiline regex does not match before `\r`), refusal without `--force`, and rotation that keeps old data readable.

## Not done

- Encrypted cookies and encrypted model attributes (casts). Sessions are stored server-side and only carry a random id, so they do not need encryption.
- Signed (not encrypted) URLs: planned with email verification.
