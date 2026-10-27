<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use VeyroPHP\Database\Schema\Blueprint;
use VeyroPHP\Database\Schema\Schema;

/**
 * Creates and drops the tables of the database queue driver and the failed-job
 * store. Call it from a migration:
 *
 *     public function up(Schema $schema): void { QueueSchema::up($schema); }
 *     public function down(Schema $schema): void { QueueSchema::down($schema); }
 *
 * Times are Unix timestamps (seconds) so comparisons do not depend on the
 * database server's time zone.
 */
final class QueueSchema
{
    public static function up(Schema $schema, string $jobsTable = 'jobs', string $failedTable = 'failed_jobs'): void
    {
        $schema->create($jobsTable, static function (Blueprint $t): void {
            $t->id();
            $t->string('queue', 100);
            $t->text('payload');
            $t->integer('attempts')->default(0);
            $t->string('reserved_by', 64)->nullable();
            $t->bigInteger('reserved_at')->nullable();
            $t->bigInteger('available_at');
            $t->bigInteger('created_at');
            $t->index(['queue', 'available_at']);
            $t->index(['queue', 'reserved_at']);
        });

        $schema->create($failedTable, static function (Blueprint $t): void {
            $t->id();
            $t->string('connection', 100);
            $t->string('queue', 100);
            $t->text('payload');
            $t->text('exception');
            $t->dateTime('failed_at');
        });
    }

    public static function down(Schema $schema, string $jobsTable = 'jobs', string $failedTable = 'failed_jobs'): void
    {
        $schema->dropIfExists($failedTable);
        $schema->dropIfExists($jobsTable);
    }
}
