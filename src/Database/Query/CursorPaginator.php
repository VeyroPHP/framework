<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Query;

use JsonSerializable;
use VeyroPHP\Database\Exception\InvalidCursorException;
use VeyroPHP\Support\Base64Url;

/**
 * One page from cursorPaginate(): the items and the cursor of the next page
 * (null on the last page). There is no total, which is what keeps it fast.
 *
 * The cursor is the last item's key, base64url-encoded JSON. It is not secret
 * (it holds a key the client may already see) and not signed: a client can
 * change it, but only to start reading at another position of the same query.
 *
 * @template T
 */
final class CursorPaginator implements JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $perPage,
        public readonly ?string $nextCursor,
    ) {}

    public function hasMorePages(): bool
    {
        return $this->nextCursor !== null;
    }

    /**
     * @return array{data: list<T>, per_page: int, next_cursor: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['data' => $this->items, 'per_page' => $this->perPage, 'next_cursor' => $this->nextCursor];
    }

    /**
     * @internal
     */
    public static function encode(int|string $key): string
    {
        return Base64Url::encode((string) json_encode(['k' => $key]));
    }

    /**
     * @internal
     *
     * @throws InvalidCursorException
     */
    public static function decode(string $cursor): int|string
    {
        if ($cursor === '' || strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            throw new InvalidCursorException();
        }

        $json = Base64Url::decode($cursor);
        $data = is_string($json) ? json_decode($json, true) : null;
        $key = is_array($data) ? ($data['k'] ?? null) : null;

        return is_int($key) || is_string($key) ? $key : throw new InvalidCursorException();
    }
}
