<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Database;

enum UserStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
}

enum Priority: int
{
    case Low = 1;
    case High = 9;
}
