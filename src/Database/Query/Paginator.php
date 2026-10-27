<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Query;

use JsonSerializable;

/**
 * One page of results plus the total count.
 */
final class Paginator implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->items,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
        ];
    }
}
