<?php

declare(strict_types=1);

/**
 * Run by MissingExtensionTest with "php -n" (no php.ini, so no fileinfo): prints the
 * exception each fileinfo user throws, one per line.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use VeyroPHP\Filesystem\LocalDisk;
use VeyroPHP\Security\MimeType;

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-fileinfo-' . bin2hex(random_bytes(4));
mkdir($directory);
file_put_contents($directory . '/note.txt', 'hello');

foreach ([
    static fn() => (new LocalDisk($directory))->mimeType('note.txt'),
    static fn() => MimeType::detect(new Nyholm\Psr7\UploadedFile(Nyholm\Psr7\Stream::create('hello'), 5, UPLOAD_ERR_OK, 'note.txt')),
] as $call) {
    try {
        $call();
        echo "no exception\n";
    } catch (Throwable $e) {
        echo $e::class, ': ', $e->getMessage(), "\n";
    }
}

unlink($directory . '/note.txt');
rmdir($directory);
