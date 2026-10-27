<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

interface Analyzer
{
    /** Short name used by --from, e.g. "laravel". */
    public function name(): string;

    /** Whether the project looks like this framework (from composer files and folders only). */
    public function detects(SourceTree $tree): bool;

    public function analyze(SourceTree $tree): Report;
}
