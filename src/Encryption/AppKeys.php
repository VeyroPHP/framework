<?php

declare(strict_types=1);

namespace VeyroPHP\Encryption;

use VeyroPHP\Config\Config;

/**
 * @internal reads config "app.key" and "app.previous_keys" for every user of APP_KEY
 * (encryption, URL signing, session fingerprints), so they agree on the rules (SVC-10):
 * keys are trimmed, empty entries (a trailing comma) are ignored, wrong types fail.
 */
final class AppKeys
{
    /**
     * @return array{string, list<string>} the current key ('' when not set) and the previous keys, still encoded
     *
     * @throws EncryptionException when a value has the wrong type
     */
    public static function fromConfig(Config $config): array
    {
        $key = $config->get('app.key', '');
        $previous = $config->get('app.previous_keys', []);

        if (is_string($previous)) {
            $previous = explode(',', $previous);
        }

        if (!is_string($key) || !is_array($previous)) {
            throw new EncryptionException('config app.key must be a string and app.previous_keys a list or a comma-separated string.');
        }

        $previousKeys = [];

        foreach ($previous as $old) {
            if (!is_string($old)) {
                throw new EncryptionException('config app.previous_keys must contain "base64:..." strings.');
            }

            if (trim($old) !== '') {
                $previousKeys[] = trim($old);
            }
        }

        return [trim($key), $previousKeys];
    }
}
