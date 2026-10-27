<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Orm;

use PHPUnit\Framework\Attributes\CoversClass;
use VeyroPHP\Orm\ModelQuery;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Orm\Relation;

#[CoversClass(Orm::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(Relation::class)]
final class SqliteOrmFeaturesTest extends OrmFeaturesTestCase
{
    protected static function settings(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:'];
    }
}
