# Encryption

`VeyroPHP\Encryption\Encrypter` encrypts values so they can be stored or sent where people might read them (a URL, a cookie, a database column) and comes back unchanged or not at all. Design: [ADR 0021](../architecture/adr/0021-encryption.md).

It uses AES-256-GCM from PHP's OpenSSL extension: the content is secret, and any change to the encrypted text is detected. No custom cryptography.

## Setup

Register `VeyroPHP\Encryption\EncryptionServiceProvider` (the starter app does) and create the key:

```bash
php veyro key:generate
```

This writes `APP_KEY=base64:...` (32 random bytes) to `.env`. `composer create-project` runs it for you. The starter's `config/app.php` reads it:

```php
'key' => $env->string('APP_KEY', ''),
'previous_keys' => $env->string('APP_PREVIOUS_KEYS', ''),
```

**Keep `APP_KEY` secret** like a database password: anyone with it can read and forge encrypted values. Never commit it. Every server of one application needs the same key.

## Usage

```php
use VeyroPHP\Encryption\DecryptException;
use VeyroPHP\Encryption\Encrypter;

public function invite(Encrypter $encrypter, int $teamId): string
{
    $token = $encrypter->encrypt(['team' => $teamId, 'until' => time() + 86400], 'team-invite');

    return '/join/' . $token;   // URL-safe
}

public function join(Encrypter $encrypter, string $token): Response
{
    try {
        $data = $encrypter->decrypt($token, 'team-invite');
    } catch (DecryptException) {
        throw new HttpException(404);
    }

    if (!is_array($data) || ($data['until'] ?? 0) < time()) {
        throw new HttpException(404);   // expired
    }
    // ...
}
```

| Method | Use |
| --- | --- |
| `encrypt($value, $purpose = '')` | Arrays, strings, numbers, booleans, null (stored as JSON) |
| `decrypt($payload, $purpose = '')` | The value; objects come back as arrays |
| `encryptString($text, $purpose = '')` / `decryptString(...)` | Raw strings, also binary, without JSON |
| `Encrypter::generateKey()` | A new `base64:...` key |

`decrypt()` and `decryptString()` throw `DecryptException` when the value was changed, cut off, made with another key or purpose, or is not an encrypted value. Treat it like invalid input (400 or 404). The message never contains the value or the key.

## Purposes

The second argument binds the encrypted value to one use. A value encrypted for `'team-invite'` cannot be decrypted as `'password-reset'`, so a token issued for one feature can never be replayed against another. Use a different purpose for each kind of data.

## Things encryption does not do

- **Expiry**: an encrypted value is valid forever. Put a time in it and check it, as in the example.
- **One-time use**: the same token can be used again. Store a used flag or a nonce in the database if it must work only once.
- **Passwords**: never encrypt passwords; hash them with `PasswordHasher`.
- **Searching**: the same value encrypts differently each time, so encrypted columns cannot be searched or indexed.
- **Safe forever under one key**: with random 12-byte nonces, rotate the key long before 4 billion values have been encrypted with it.

## Changing the key (rotation)

```bash
php veyro key:generate --force
```

The new key encrypts new values; the old key moves to `APP_PREVIOUS_KEYS` and is only used to decrypt. Re-encrypt stored data in your own time, then remove the old key. If the key leaked, rotate, re-encrypt everything, and remove the old key at once.

Without `--force` the command refuses to replace a key, because data encrypted with it would become unreadable. `--show` prints a key without changing files. After changing `.env` on a server with a cached configuration, run `php veyro config:cache` again.
