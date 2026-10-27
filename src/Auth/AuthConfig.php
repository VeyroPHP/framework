<?php

declare(strict_types=1);

namespace VeyroPHP\Auth;

use InvalidArgumentException;
use VeyroPHP\Orm\Model;

/**
 * Authentication settings.
 *
 * - model: the user model class
 * - identifier: column used to log in (e.g. "email")
 * - passwordColumn: column holding the password hash
 * - loginPath: where Authenticate sends browsers that are not logged in
 */
final class AuthConfig
{
    /**
     * @param class-string<Model> $model
     */
    public function __construct(
        public readonly string $model,
        public readonly string $identifier = 'email',
        public readonly string $passwordColumn = 'password',
        public readonly string $loginPath = '/login',
    ) {
        if (!is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException(sprintf('auth.model "%s" must be a class extending %s.', $model, Model::class));
        }

        if (!str_starts_with($loginPath, '/') || str_starts_with($loginPath, '//')) {
            throw new InvalidArgumentException('auth.login_path must be a path on this site, such as "/login".');
        }
    }
}
