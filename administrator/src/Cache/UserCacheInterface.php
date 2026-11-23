<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;

/**
 * Short-lived cache whose keys always include the Joomla user id and the employee code.
 * Only data already checked for ownership may be stored.
 */
interface UserCacheInterface
{
    /**
     * @param bool $stale false: never an entry past its time, even while a page updates (1.2.8;
     *                    e.g. which employees a manager may see)
     *
     * @return array<mixed>|null
     */
    public function get(Identity $identity, string $key, bool $stale = true): ?array;

    /**
     * Several entries at once (1.3.7), as get() would return each one.
     *
     * @param  list<array{0: Identity, 1: string}> $items
     * @return list<array<mixed>|null> in the order of $items
     */
    public function getMany(array $items, bool $stale = true): array;

    /**
     * @param array<mixed> $value
     * @param int|null     $ttl   seconds to keep it instead of the cache time (e.g. a day for past
     *                            months, which no longer change); ignored when the cache is off
     */
    public function set(Identity $identity, string $key, array $value, ?int $ttl = null): void;

    /**
     * Whether get() may also return an entry past its time, up to a few days old (1.2.6): a page
     * is then shown at once with it and updated in the background (DisplayController, refresh.js).
     */
    public function allowStale(bool $allow): void;

    /** When the oldest entry past its time that get() returned was stored (Unix time); null if none. */
    public function staleSince(): ?int;
}
