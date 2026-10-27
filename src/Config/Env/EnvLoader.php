<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Env;

use VeyroPHP\Config\Exception\EnvException;

/**
 * Builds an Env from an optional .env file and the process environment.
 *
 * Variables set in the real environment win over the file, so a server or
 * container can override any value without editing .env. The loader never
 * writes to putenv(), $_ENV or $_SERVER.
 */
final class EnvLoader
{
    /** @var array<string, string> */
    private readonly array $system;

    /**
     * @param array<string, string>|null $system process environment; defaults to getenv()
     * @param bool|null $caseInsensitiveNames whether the OS treats variable names case-insensitively
     *                                        (Windows); defaults to the current OS
     */
    public function __construct(?array $system = null, ?bool $caseInsensitiveNames = null)
    {
        $system ??= getenv();

        // Windows names are case-insensitive ("Path" and "PATH" are one variable), but getenv()
        // keeps the original spelling. Add the upper-case spelling so config can read PATH, APP_ENV, ...
        if ($caseInsensitiveNames ?? PHP_OS_FAMILY === 'Windows') {
            foreach ($system as $name => $value) {
                $system[strtoupper($name)] ??= $value;
            }
        }

        $this->system = $system;
    }

    /**
     * @param string $file path to a .env file; a missing file is allowed
     *
     * @throws EnvException when the file exists but cannot be read or parsed
     */
    public function load(string $file): Env
    {
        $fromFile = [];

        if (is_file($file)) {
            $contents = is_readable($file) ? file_get_contents($file) : false;

            if ($contents === false) {
                throw EnvException::fileNotReadable($file);
            }

            $fromFile = (new DotenvParser())->parse($contents);
        }

        return new Env($this->system + $fromFile);
    }
}
