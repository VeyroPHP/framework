<?php

declare(strict_types=1);

namespace VeyroPHP\Encryption;

use JsonException;
use SensitiveParameter;
use VeyroPHP\Support\Base64Url;

/**
 * Authenticated encryption with AES-256-GCM (OpenSSL). No custom cryptography.
 *
 *     $token = $encrypter->encrypt(['user' => 5, 'until' => time() + 3600], 'invite');
 *     $data = $encrypter->decrypt($token, 'invite');   // DecryptException if changed or wrong purpose
 *
 * - Output is URL-safe base64 (usable in URLs and cookies): version byte,
 *   12-byte random nonce, 16-byte tag, ciphertext.
 * - Any change to the text is detected; decrypt() then throws DecryptException.
 * - $purpose is bound to the ciphertext: a value encrypted for "invite" does not
 *   decrypt as "password-reset". Use a different purpose for each kind of data.
 * - Values are stored as JSON, never with serialize(), so decrypting cannot
 *   create objects (no object injection). encryptString() skips JSON.
 * - Key rotation: new data uses the first key; older keys are only tried when
 *   decrypting, so data encrypted before a key change stays readable.
 * - Encryption does not expire anything: put an expiry time in the value and check it.
 */
final class Encrypter
{
    public const CIPHER = 'aes-256-gcm';

    private const VERSION = "\x01";

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    private const KEY_BYTES = 32;

    /** @var non-empty-list<string> */
    private readonly array $keys;

    /**
     * @param string $key raw 32-byte key used for new data
     * @param list<string> $previousKeys raw 32-byte keys still accepted when decrypting
     */
    public function __construct(#[SensitiveParameter] string $key, #[SensitiveParameter] array $previousKeys = [])
    {
        if (!extension_loaded('openssl')) {
            throw new EncryptionException('Encryption needs the PHP openssl extension.');
        }

        foreach ([$key, ...$previousKeys] as $candidate) {
            if (strlen($candidate) !== self::KEY_BYTES) {
                throw new EncryptionException('Encryption keys must be exactly 32 bytes. Create one with "php veyro key:generate".');
            }
        }

        $this->keys = [$key, ...$previousKeys];
    }

    /**
     * A new random key in the "base64:..." form used in APP_KEY.
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(self::KEY_BYTES));
    }

    /**
     * Decodes "base64:..." (the APP_KEY form) to the raw key.
     */
    public static function parseKey(#[SensitiveParameter] string $key): string
    {
        if ($key === '') {
            throw new EncryptionException('No encryption key is set. Run "php veyro key:generate" to create APP_KEY.');
        }

        if (!str_starts_with($key, 'base64:')) {
            throw new EncryptionException('Encryption keys must start with "base64:". Create one with "php veyro key:generate".');
        }

        $raw = base64_decode(substr($key, 7), true);

        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new EncryptionException('The encryption key must be 32 bytes, base64 encoded. Create one with "php veyro key:generate".');
        }

        return $raw;
    }

    /**
     * Encrypts any JSON-encodable value (array, string, number, bool, null).
     */
    public function encrypt(#[SensitiveParameter] mixed $value, string $purpose = ''): string
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new EncryptionException('Only JSON-encodable values can be encrypted: ' . $e->getMessage(), 0, $e);
        }

        return $this->encryptString($json, $purpose);
    }

    /**
     * @throws DecryptException when the text was changed, belongs to another purpose or key, or is not valid
     */
    public function decrypt(string $payload, string $purpose = ''): mixed
    {
        try {
            return json_decode($this->decryptString($payload, $purpose), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DecryptException('The decrypted value is not valid JSON; it was probably made with encryptString().');
        }
    }

    public function encryptString(#[SensitiveParameter] string $plaintext, string $purpose = ''): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->keys[0], OPENSSL_RAW_DATA, $nonce, $tag, self::aad($purpose), self::TAG_BYTES);

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new EncryptionException('Encryption failed.');
        }

        return Base64Url::encode(self::VERSION . $nonce . $tag . $ciphertext);
    }

    /**
     * @throws DecryptException when the text was changed, belongs to another purpose or key, or is not valid
     */
    public function decryptString(string $payload, string $purpose = ''): string
    {
        $raw = Base64Url::decode($payload);

        if ($raw === false || strlen($raw) < 1 + self::NONCE_BYTES + self::TAG_BYTES || $raw[0] !== self::VERSION) {
            throw new DecryptException('The encrypted value is not valid.');
        }

        $nonce = substr($raw, 1, self::NONCE_BYTES);
        $tag = substr($raw, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, 1 + self::NONCE_BYTES + self::TAG_BYTES);
        $aad = self::aad($purpose);

        foreach ($this->keys as $key) {
            $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw new DecryptException('The encrypted value could not be decrypted: it was changed, or made with another key or purpose.');
    }

    private static function aad(string $purpose): string
    {
        return 'veyrophp:v1:' . $purpose;
    }
}
