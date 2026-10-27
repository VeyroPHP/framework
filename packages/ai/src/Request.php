<?php

declare(strict_types=1);

namespace VeyroPHP\Ai;

/**
 * What a provider is asked to do: the conversation, options and, for
 * structured output, a JSON schema the answer must follow.
 */
final class Request
{
    /**
     * @param non-empty-list<Message> $messages
     * @param array<string, mixed>|null $schema JSON schema of the expected answer
     */
    public function __construct(
        public readonly array $messages,
        public readonly Options $options = new Options(),
        public readonly ?array $schema = null,
    ) {}
}
