<?php

declare(strict_types=1);

/**
 * A process for the file-lock concurrency test:
 *
 *     php lock-worker.php <cache directory> <go file> <milliseconds> acquire|prune
 *
 * "acquire" takes the lock "race" again and again. While holding it, it creates an
 * owner file with mode "x", which fails if another holder has one: that prints
 * VIOLATION. Every completed hold prints "held"; after ten holds it stops. "prune" runs
 * prune() as fast as it can and prints how many files it removed. (clear() removes lock
 * files the same way, through removeFreeLockFile(); FileLockRaceTest covers it.)
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use VeyroPHP\Cache\FileStore;

[$directory, $go, $milliseconds, $mode] = [$argv[1] ?? '', $argv[2] ?? '', (int) ($argv[3] ?? 0), $argv[4] ?? ''];
$store = new FileStore($directory);
$owner = $directory . DIRECTORY_SEPARATOR . 'owner';

// Start together: wait for the go file the test creates once every process is up.
while (!is_file($go)) {
    usleep(2_000);
}

$deadline = microtime(true) + $milliseconds / 1000;

if ($mode === 'prune') {
    $removed = 0;

    while (microtime(true) < $deadline) {
        // prune() also removes the empty lock directory, so acquirers must recreate it
        // (this found the stale stat cache fixed in FileStore::ensureDirectory()).
        $removed += $store->prune();
    }

    echo 'removed ', $removed, "\n";

    exit(0);
}

// A process stops after MAX_HOLDS, so under heavy load (all databases running) one fast
// process cannot keep the lock to itself: the others are then sure to get turns.
const MAX_HOLDS = 10;
$holds = 0;

while (microtime(true) < $deadline && $holds < MAX_HOLDS) {
    $token = $store->acquireLock('race', 5);

    if ($token === null) {
        usleep(random_int(0, 500));

        continue;
    }

    $marker = @fopen($owner, 'x');

    if ($marker === false) {
        echo "VIOLATION\n";
        $store->releaseLock('race', $token);

        continue;
    }

    fclose($marker);
    usleep(1_000);
    unlink($owner);
    $store->releaseLock('race', $token);
    echo "held\n";
    $holds++;
    // Give the other processes a turn: longer than their retry wait, so a coarse system
    // timer (Windows) cannot keep handing the lock back to the same process.
    usleep(random_int(5_000, 10_000));
}
