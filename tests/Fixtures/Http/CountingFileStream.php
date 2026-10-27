<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Fixtures\Http;

/**
 * An in-memory stream wrapper that counts how often each file is opened, to test that
 * a file is read only as often as intended. Register with
 * stream_wrapper_register('counting', CountingFileStream::class).
 */
final class CountingFileStream
{
    /** @var array<string, string> path => contents */
    public static array $files = [];

    /** @var array<string, int> path => times opened */
    public static array $opens = [];

    /** @var resource|null set by PHP */
    public $context;

    private string $data = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!isset(self::$files[$path])) {
            return false;
        }

        self::$opens[$path] = (self::$opens[$path] ?? 0) + 1;
        $this->data = self::$files[$path];
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->data), 'mode' => 0o100644];
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return isset(self::$files[$path]) ? ['size' => strlen(self::$files[$path]), 'mode' => 0o100644] : false;
    }
}
