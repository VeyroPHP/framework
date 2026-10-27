<?php

declare(strict_types=1);

/**
 * A worker process for the database-queue concurrency test:
 *
 *     php claim-worker.php '<connection config as JSON>' <table> <queue>
 *
 * Takes jobs until the queue is empty and prints the payload of each job it
 * got, one per line. Several of these run at the same time; the test checks
 * that no job was taken twice.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Queue\Driver\DatabaseDriver;

$settings = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
\assert(is_array($settings));
$table = $argv[2] ?? 'jobs';
$queue = $argv[3] ?? 'default';

/** @var array<string, mixed> $settings */
$connection = (new DatabaseManager(['test' => ConnectionConfig::fromArray('test', $settings)], 'test'))->connection();
$driver = new DatabaseDriver($connection, $table);

// Start together: wait for the go file the test creates once every worker is up.
$go = $argv[4] ?? '';
while ($go !== '' && !is_file($go)) {
    usleep(5_000);
}

$empty = 0;

while ($empty < 3) {
    $job = $driver->pop($queue);

    if ($job === null) {
        $empty++;
        usleep(20_000);

        continue;
    }

    $empty = 0;
    echo $job->payload, "\n";
    $driver->delete($job);
}
