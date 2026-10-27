<?php

declare(strict_types=1);

namespace VeyroPHP\Port\Convert;

/**
 * One file produced by a converter, not yet written.
 */
final class ConvertedFile
{
    /** Marks files written by the converter; only such files may be replaced with --force. */
    public const MARKER = 'Ported by "php veyro port:convert"';

    /**
     * @param list<string> $todos what a developer must still do (also written as TODO(port) comments)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $source,
        public readonly string $target,
        public readonly string $content,
        public readonly array $todos,
    ) {}

    /**
     * Whether the generated code is valid PHP syntax. This is only a syntax
     * check (nothing runs); it does not mean the code works.
     */
    public function parses(): bool
    {
        if (!str_ends_with($this->target, '.php')) {
            return true; // not PHP: nothing to check
        }

        try {
            return token_get_all($this->content, TOKEN_PARSE) !== [];
        } catch (\ParseError) {
            return false;
        }
    }
}
