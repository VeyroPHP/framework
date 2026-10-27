<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

/**
 * One turn of a conversation.
 */
final class Message
{
    /**
     * @param 'user'|'assistant' $role
     */
    private function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {}

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }
}
