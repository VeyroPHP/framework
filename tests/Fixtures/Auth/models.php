<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Auth;

use VeyroPHP\Orm\Model;

/**
 * @property string $name
 * @property string $email
 */
final class AuthUser extends Model
{
    protected const TABLE = 'users';
    protected const FILLABLE = ['name', 'email'];
    protected const HIDDEN = ['password'];
}
