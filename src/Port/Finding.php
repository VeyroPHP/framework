<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

final class Finding
{
    /**
     * @param list<string> $files up to 10 example files (relative paths)
     */
    public function __construct(
        public readonly string $area,
        public readonly string $item,
        public readonly Status $status,
        public readonly int $count,
        public readonly string $note,
        public readonly array $files = [],
        public readonly ?string $doc = null,
    ) {}

    /**
     * @return array{area: string, item: string, status: string, count: int, note: string, files: list<string>, doc: ?string}
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area,
            'item' => $this->item,
            'status' => $this->status->value,
            'count' => $this->count,
            'note' => $this->note,
            'files' => $this->files,
            'doc' => $this->doc,
        ];
    }
}
