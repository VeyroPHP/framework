<?php

declare(strict_types=1);

// Large-table checks: how the query builder behaves with millions of rows.
//
//   php bench/scale.php --rows=1000000 --db=/path/to/scale.sqlite
//
// SQLite only (a file, never an existing database: the file must not exist or
// must have been created by this script). Prints time and peak memory per case.

require dirname(__DIR__) . '/vendor/autoload.php';

use VeyroPHP\Database\Connection\Connection;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Support\Ulid;

$options = getopt('', ['rows:', 'db:', 'keep']);
$options = is_array($options) ? $options : [];
$rows = is_string($options['rows'] ?? null) && ctype_digit($options['rows']) ? (int) $options['rows'] : 1_000_000;
$file = is_string($options['db'] ?? null) ? $options['db'] : sys_get_temp_dir() . '/veyrophp-scale.sqlite';
$marker = $file . '.veyrophp-scale';

if (is_file($file) && !is_file($marker)) {
    fwrite(STDERR, "Refusing to use $file: it was not created by this script.\n");
    exit(1);
}

$db = openDatabase($file);

function openDatabase(string $file): Connection
{
    return DatabaseManager::fromConfig(['default' => 'scale', 'connections' => ['scale' => ['driver' => 'sqlite', 'database' => $file]]])->connection();
}

/**
 * @param Closure(): mixed $work
 */
function measure(string $label, Closure $work): mixed
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $start = hrtime(true);

    try {
        $result = $work();
        $error = null;
    } catch (Throwable $e) {
        $result = null;
        $error = $e::class . ': ' . substr($e->getMessage(), 0, 120);
    }

    printf(
        "%-58s %10.1f ms  peak +%7.1f MB%s\n",
        $label,
        (hrtime(true) - $start) / 1e6,
        (memory_get_peak_usage() - $before) / 1048576,
        $error === null ? '' : "  FAILED: $error",
    );

    return $result;
}

$existing = is_file($marker) ? (int) file_get_contents($marker) : 0;

if ($existing !== $rows) {
    @unlink($file);
    $db = openDatabase($file);
    $db->statement('PRAGMA journal_mode = WAL');
    $db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE, email VARCHAR(255) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL)');

    measure(sprintf('seed %s rows (builder insert, 500 per statement)', number_format($rows)), static function () use ($db, $rows): void {
        $batch = [];
        $db->transaction(static function () use ($db, $rows, &$batch): void {
            for ($i = 1; $i <= $rows; $i++) {
                $batch[] = ['public_id' => Ulid::generate(), 'email' => "user$i@example.test", 'name' => "User $i", 'status' => $i % 10 === 0 ? 'blocked' : 'active', 'created_at' => '2026-01-01 00:00:00'];
                if (count($batch) === 500) {
                    $db->table('users')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $db->table('users')->insert($batch);
            }
        });
    });
    file_put_contents($marker, (string) $rows);
}

$middle = intdiv($rows, 2);
$publicId = $db->table('users')->where('id', $middle)->value('public_id');

echo "\n";
measure('find by primary key', static fn() => $db->table('users')->where('id', $middle)->first());
measure('find by public_id (unique index)', static fn() => $db->table('users')->where('public_id', $publicId)->first());
measure('find by email (unique index)', static fn() => $db->table('users')->where('email', "user$middle@example.test")->first());
measure('find by name (no index: full scan)', static fn() => $db->table('users')->where('name', "User $middle")->first());
measure('count(*) all rows', static fn() => $db->table('users')->count());
measure('count(*) where status (no index)', static fn() => $db->table('users')->where('status', 'blocked')->count());
measure('paginate page 1 (count + offset 0)', static fn() => $db->table('users')->orderBy('id')->paginate(20, 1));
measure('paginate middle page (count + large offset)', static fn() => $db->table('users')->orderBy('id')->paginate(20, intdiv($rows, 40)));
measure('paginate last page', static fn() => $db->table('users')->orderBy('id')->paginate(20, intdiv($rows, 20)));
measure('keyset page after the middle id (where id > ? limit 20)', static fn() => $db->table('users')->where('id', '>', $middle)->orderBy('id')->limit(20)->get());
measure('cursorPaginate first page', static fn() => $db->table('users')->cursorPaginate(20));
$middleCursor = VeyroPHP\Database\Query\CursorPaginator::encode($middle);
$lastCursor = VeyroPHP\Database\Query\CursorPaginator::encode($rows - 20);
measure('cursorPaginate page in the middle', static fn() => $db->table('users')->cursorPaginate(20, $middleCursor));
measure('cursorPaginate last page', static fn() => $db->table('users')->cursorPaginate(20, $lastCursor));
measure('get() 100,000 rows into memory', static fn() => count($db->table('users')->limit(100_000)->get()));
measure(sprintf('chunkById(1000) over all %s rows', number_format($rows)), static function () use ($db): int {
    $seen = 0;
    $db->table('users')->chunkById(1000, static function (array $chunk) use (&$seen): void {
        $seen += count($chunk);
    });

    return $seen;
});
measure(sprintf('lazyById(1000) over all %s rows', number_format($rows)), static function () use ($db): int {
    $seen = 0;
    foreach ($db->table('users')->lazyById(1000) as $row) {
        $seen++;
    }

    return $seen;
});
measure('insert 20,000 rows in ONE statement', static function () use ($db): int {
    $batch = [];
    for ($i = 0; $i < 20_000; $i++) {
        $batch[] = ['public_id' => Ulid::generate(), 'email' => 'bulk' . bin2hex(random_bytes(8)) . '@example.test', 'name' => 'Bulk', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00'];
    }

    return $db->table('users')->insert($batch);
});

$db->disconnect();
if (!isset($options['keep'])) {
    @unlink($file);
    @unlink($file . '-wal');
    @unlink($file . '-shm');
    @unlink($marker);
}
