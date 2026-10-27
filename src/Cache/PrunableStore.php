<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

/**
 * A store whose expired entries stay until someone removes them.
 *
 * Expired entries are deleted when they are read, but entries that are never
 * read again (for example the session of a visitor who does not come back)
 * would stay forever. Run prune() regularly, e.g. "php veyro cache:prune"
 * from cron. Stores with native expiry, such as Redis, do not need this.
 */
interface PrunableStore extends Store
{
    /**
     * Removes expired and unreadable entries, and leftovers such as released
     * locks. Entries that have not expired are never removed.
     *
     * @return int number of removed entries
     */
    public function prune(): int;
}
