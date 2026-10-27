<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * @internal source patterns that the analyzers and converters must read the same way (CORE-8).
 */
final class Patterns
{
    /** A class that extends an Eloquent model, user or pivot. */
    public const ELOQUENT_MODEL = '/\bextends\s+(\\\\?Illuminate\\\\Database\\\\Eloquent\\\\)?(Model|Authenticatable|Pivot)\b/';

    /**
     * Table names of "->create('table')" calls, in order. $receiver limits them to calls
     * on one variable, such as '$schema' in converted migrations.
     *
     * @return list<string>
     */
    public static function createdTables(string $code, string $receiver = ''): array
    {
        preg_match_all('/' . preg_quote($receiver, '/') . '->create\(\s*[\'"](\w+)[\'"]/', $code, $matches);

        return $matches[1];
    }
}
