<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Support;

use BiotimePortal\Component\Biotime\Administrator\Cache\UserCacheInterface;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;

/**
 * Per-user cache over a plain array, for the tests (the component itself uses DbUserCache).
 * Keys contain the Joomla user id and the employee code, like DbUserCache.
 */
final class StoredUserCache implements UserCacheInterface
{
    private const MAX_ENTRIES = 40;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param \Closure(): array<string, mixed>    $load
     * @param \Closure(array<string, mixed>): void $save
     * @param int                                  $ttl  seconds; 0 disables the cache
     */
    public function __construct(
        private readonly \Closure $load,
        private readonly \Closure $save,
        private readonly int $ttl = 300,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allowStale(bool $allow): void
    {
    }

    public function staleSince(): ?int
    {
        return null;
    }

    public function getMany(array $items, bool $stale = true): array
    {
        return array_map(fn (array $item): ?array => $this->get($item[0], $item[1], $stale), $items);
    }

    public function get(Identity $identity, string $key, bool $stale = true): ?array
    {
        if ($this->ttl <= 0 || !$identity->isMatched()) {
            return null;
        }

        $entry = ($this->load)()[$this->key($identity, $key)] ?? null;

        if (!\is_array($entry) || ($entry['e'] ?? 0) <= ($this->clock)() || !\is_array($entry['v'] ?? null)) {
            return null;
        }

        return $entry['v'];
    }

    public function set(Identity $identity, string $key, array $value, ?int $ttl = null): void
    {
        if ($this->ttl <= 0 || !$identity->isMatched()) {
            return;
        }

        $now   = ($this->clock)();
        $store = array_filter(($this->load)(), static fn ($e) => \is_array($e) && ($e['e'] ?? 0) > $now);

        $store[$this->key($identity, $key)] = ['e' => $now + max(1, $ttl ?? $this->ttl), 'v' => $value];

        if (\count($store) > self::MAX_ENTRIES) {
            uasort($store, static fn (array $a, array $b): int => $a['e'] <=> $b['e']);
            $store = \array_slice($store, -self::MAX_ENTRIES, null, true);
        }

        ($this->save)($store);
    }

    private function key(Identity $identity, string $key): string
    {
        return $identity->joomlaUserId . '|' . $identity->empCode() . '|' . $key;
    }
}
