<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Tokens;

use SensitiveParameter;

/**
 * A token that was just created. $plainText is the only copy of the secret:
 * show it to the user once; it cannot be recovered later.
 */
final class NewAccessToken
{
    public function __construct(
        public readonly AccessToken $token,
        #[SensitiveParameter]
        public readonly string $plainText,
    ) {}
}
