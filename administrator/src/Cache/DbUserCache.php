<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Per-user cache in the component's own table #__biotime_cache, so it survives logging out and is
 * not loaded with the Joomla session on every page of the site. Keys always contain the Joomla
 * user id and the employee code, so a user never reads another user's entry and a changed mapping
 * never shows the previous employee's data. Every entry has an expiry time; expired entries are
 * deleted now and then, so the table only holds what was read recently.
 *
 * A database error never breaks a page: the entry is simply read from ZKBioTime again.
 *
 * 1.2.6: an entry past its time is kept a few more days (STALE_SECONDS). On a normal page load
 * (allowStale()) it is still returned, so the page opens at once with the data it was last shown
 * with; staleSince() then tells from when, and the page updates itself in the background. Each
 * row stores when it was written ({"c": time, "v": value}).
 */
final class DbUserCache implements UserCacheInterface
{
    private const TABLE = '#__biotime_cache';

    /** About one write in this many also deletes expired entries. */
    private const PURGE_EVERY = 20;

    /** Larger entries are not cached (a month of punches is a few KB). */
    private const MAX_BYTES = 2_000_000;

    /** How long past its time an entry may still be shown while the page updates (1.2.6). */
    public const STALE_SECONDS = 7 * 86400;

    private bool $allowStale = false;

    private ?int $staleSince = null;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param int $ttl seconds; 0 disables the cache
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 300,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allowStale(bool $allow): void
    {
        $this->allowStale = $allow;
    }

    public function staleSince(): ?int
    {
        return $this->staleSince;
    }

    public function get(Identity $identity, string $key, bool $stale = true): ?array
    {
        return $this->getMany([[$identity, $key]], $stale)[0];
    }

    /**
     * Several entries in one query (1.3.7: the Manager Dashboard looked up every employee, month and
     * type one by one, hundreds of queries per page).
     *
     * @param  list<array{0: Identity, 1: string}> $items
     * @return list<array<mixed>|null> in the order of $items
     */
    public function getMany(array $items, bool $stale = true): array
    {
        $out = array_fill(0, \count($items), null);
        $ids = [];

        foreach ($items as $i => [$identity, $key]) {
            if ($this->ttl > 0 && $identity->isMatched()) {
                $ids[$this->key($identity, $key)][] = $i;
            }
        }

        if ($ids === []) {
            return $out;
        }

        $now    = ($this->clock)();
        $oldest = $this->allowStale && $stale ? $now - self::STALE_SECONDS : $now;

        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            try {
                $query = $this->db->createQuery()
                    ->select($this->db->quoteName(['cache_key', 'value', 'expires']))
                    ->from($this->db->quoteName(self::TABLE))
                    ->whereIn($this->db->quoteName('cache_key'), $chunk, ParameterType::STRING)
                    ->where($this->db->quoteName('expires') . ' > :oldest')
                    ->bind(':oldest', $oldest, ParameterType::INTEGER);

                $rows = $this->db->setQuery($query)->loadAssocList();
            } catch (\Throwable $e) {
                $this->logger->warning('ZKBioTime cache could not be read: ' . $e::class);

                continue;
            }

            foreach ($rows as $row) {
                $value = $this->value($row, $now, $stale);

                foreach ($ids[(string) ($row['cache_key'] ?? '')] ?? [] as $i) {
                    $out[$i] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * The value of a stored row, or null when it may not be returned.
     *
     * @param array<string, mixed> $row cache_key, value, expires
     * @return array<mixed>|null
     */
    private function value(array $row, int $now, bool $stale): ?array
    {
        if (!\is_string($row['value'] ?? null) || $row['value'] === '') {
            return null;
        }

        $stored = json_decode($row['value'], true);

        if (!\is_array($stored)) {
            return null;
        }

        // Rows written before 1.2.6 hold the value itself, without the time it was written.
        $written = isset($stored['c'], $stored['v']) && \is_int($stored['c']) && \is_array($stored['v']) && \count($stored) === 2;
        $value   = $written ? $stored['v'] : $stored;

        if ((int) $row['expires'] > $now) {
            return $value;
        }

        // Past its time: only on a page that updates itself, and only when we know its age.
        if (!$this->allowStale || !$stale || !$written) {
            return null;
        }

        $this->staleSince = min($this->staleSince ?? $stored['c'], $stored['c']);

        return $value;
    }

    public function set(Identity $identity, string $key, array $value, ?int $ttl = null): void
    {
        if ($this->ttl <= 0 || !$identity->isMatched()) {
            return;
        }

        $id   = $this->key($identity, $key);
        $now  = ($this->clock)();
        $json = json_encode(['c' => $now, 'v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || \strlen($json) > self::MAX_BYTES) {
            return;
        }

        try {
            $this->delete($this->db->quoteName('cache_key') . ' = :key', [':key' => [$id, ParameterType::STRING]]);
            // insertObject() takes the row by reference: it must be a variable.
            $row = (object) [
                'cache_key' => $id,
                'user_id'   => $identity->joomlaUserId,
                'expires'   => $now + max(1, $ttl ?? $this->ttl),
                'value'     => $json,
            ];
            $this->db->insertObject(self::TABLE, $row);

            if (random_int(1, self::PURGE_EVERY) === 1) {
                $this->purge($now);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ZKBioTime cache could not be written: ' . $e::class);
        }
    }

    /** Delete every entry too old to be shown even while a page updates (STALE_SECONDS past its time). */
    public function purge(?int $now = null): void
    {
        $this->delete($this->db->quoteName('expires') . ' <= :oldest', [':oldest' => [($now ?? ($this->clock)()) - self::STALE_SECONDS, ParameterType::INTEGER]]);
    }

    /**
     * @param array<string, array{0: int|string, 1: string}> $binds
     */
    private function delete(string $where, array $binds): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($where);

        foreach ($binds as $name => [$value, $type]) {
            $query->bind($name, $value, $type);
        }

        $this->db->setQuery($query)->execute();
    }

    private function key(Identity $identity, string $key): string
    {
        // At most 191 characters (index length); the parts are short and checked elsewhere.
        return substr($identity->joomlaUserId . '|' . $identity->empCode() . '|' . $key, 0, 191);
    }
}
