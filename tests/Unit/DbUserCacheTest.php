<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Cache\DbUserCache;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseFactory;
use PHPUnit\Framework\TestCase;

/**
 * The cache table #__biotime_cache, against a real database (SQLite in memory, with the same
 * Joomla database driver API the site uses).
 */
final class DbUserCacheTest extends TestCase
{
    private DatabaseDriver $db;

    private int $time = 1_000_000;

    private ArrayLogger $log;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available');
        }

        $this->db  = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'j_']);
        $this->log = new ArrayLogger();
        $this->db->setQuery('CREATE TABLE "j_biotime_cache" ("cache_key" varchar(191) NOT NULL PRIMARY KEY, "user_id" bigint NOT NULL DEFAULT 0, "expires" bigint NOT NULL, "value" text NOT NULL)')->execute();
    }

    private function cache(int $ttl = 300): DbUserCache
    {
        return new DbUserCache($this->db, $this->log, $ttl, fn (): int => $this->time);
    }

    private function rows(): int
    {
        return (int) $this->db->setQuery('SELECT COUNT(*) FROM "j_biotime_cache"')->loadResult();
    }

    public function testEntriesArePerUserAndEmployeeAndCanBeOverwritten(): void
    {
        $alice = Identity::matched(5, '11', 'E1');
        $cache = $this->cache();

        $cache->set($alice, 'punches:2026-11', ['rows' => [['t' => '2026-11-02 07:58:00']], 'at' => 1]);
        $cache->set($alice, 'punches:2026-11', ['rows' => [['t' => '2026-11-03 08:00:00']], 'at' => 2]);

        self::assertSame(['rows' => [['t' => '2026-11-03 08:00:00']], 'at' => 2], $cache->get($alice, 'punches:2026-11'));
        self::assertSame(1, $this->rows(), 'overwritten, not duplicated');
        self::assertNull($cache->get(Identity::matched(6, '12', 'E2'), 'punches:2026-11'), 'another user');
        self::assertNull($cache->get(Identity::matched(5, '13', 'E3'), 'punches:2026-11'), 'same user, mapped to another employee');
        self::assertSame([], $this->log->records);
    }

    public function testEntriesExpireAndArePurged(): void
    {
        $alice = Identity::matched(5, '11', 'E1');
        $cache = $this->cache();

        $cache->set($alice, 'punches:2026-11', ['at' => 1]);
        $cache->set($alice, 'punches:2026-10', ['at' => 1], 86400);

        $this->time += 600;
        self::assertNull($cache->get($alice, 'punches:2026-11'), 'current month: cache time (5 minutes)');
        self::assertSame(['at' => 1], $cache->get($alice, 'punches:2026-10'), 'past month: a day');

        // Kept a few more days past their time (1.2.6: shown while a page updates), then purged.
        $cache->purge();
        self::assertSame(2, $this->rows());
        $this->time += 86400 + DbUserCache::STALE_SECONDS;
        $cache->purge();
        self::assertSame(0, $this->rows());
    }

    public function testEntriesPastTheirTimeAreShownOnlyWhileAPageUpdates(): void
    {
        $alice = Identity::matched(5, '11', 'E1');
        $cache = $this->cache();
        $at    = $this->time;

        $cache->set($alice, 'punches:2026-11', ['rows' => [], 'at' => 1]);
        $this->time += 600;

        self::assertNull($cache->get($alice, 'punches:2026-11'), 'a normal read never returns it');
        self::assertNull($cache->staleSince());

        $cache->allowStale(true);
        self::assertSame(['rows' => [], 'at' => 1], $cache->get($alice, 'punches:2026-11'));
        self::assertSame($at, $cache->staleSince(), 'from when');
        self::assertNull($cache->get($alice, 'punches:2026-11', false), 'never for a look-up that must be current (a manager\'s team)');
        self::assertNull($cache->get(Identity::matched(6, '12', 'E2'), 'punches:2026-11'), 'never another user\'s');

        // A fresh entry does not change staleSince(); the oldest older one counts.
        $cache->set($alice, 'punches:2026-10', ['at' => 2]);
        self::assertSame(['at' => 2], $cache->get($alice, 'punches:2026-10'));
        self::assertSame($at, $cache->staleSince());

        // Too old to be shown at all.
        $this->time += DbUserCache::STALE_SECONDS;
        self::assertNull($cache->get($alice, 'punches:2026-11'));
    }

    public function testRowsOfOlderVersionsAreReadButNeverShownPastTheirTime(): void
    {
        $alice = Identity::matched(5, '11', 'E1');
        $this->db->setQuery("INSERT INTO \"j_biotime_cache\" VALUES ('5|E1|k', 5, " . ($this->time + 300) . ", '{\"rows\":[],\"at\":3}')")->execute();
        $cache = $this->cache();
        $cache->allowStale(true);

        self::assertSame(['rows' => [], 'at' => 3], $cache->get($alice, 'k'));
        $this->time += 600;
        self::assertNull($cache->get($alice, 'k'), 'its age is unknown');
        self::assertNull($cache->staleSince());
    }

    public function testCacheTimeZeroWritesNothingAndErrorsNeverBreakAPage(): void
    {
        $alice = Identity::matched(5, '11', 'E1');

        $this->cache(0)->set($alice, 'k', ['a' => 1]);
        self::assertSame(0, $this->rows());
        self::assertNull($this->cache(0)->get($alice, 'k'));
        self::assertNull($this->cache()->get(Identity::notMatched(5, 'not_found'), 'k'));

        $this->db->setQuery('DROP TABLE "j_biotime_cache"')->execute();
        $this->cache()->set($alice, 'k', ['a' => 1]);
        self::assertNull($this->cache()->get($alice, 'k'));
        self::assertTrue($this->log->has('warning', 'could not be written'));
        self::assertTrue($this->log->has('warning', 'could not be read'));
    }

    /** 1.3.7: several entries in one query, in order, each user's own; past their time only while a page updates. */
    public function testManyEntriesAreReadAtOnce(): void
    {
        $alice = Identity::matched(5, '11', 'E1');
        $bob   = Identity::matched(5, '12', 'E2');
        $cache = $this->cache();

        $cache->set($alice, 'k1', ['a' => 1]);
        $cache->set($bob, 'k1', ['b' => 1], 60);
        $this->time += 120;

        $items = [[$alice, 'k1'], [$bob, 'k1'], [$alice, 'missing'], [Identity::notMatched(7, 'not_found'), 'k1']];
        self::assertSame([['a' => 1], null, null, null], $cache->getMany($items), 'Bob\'s entry is past its time');

        $cache->allowStale(true);
        self::assertSame([['a' => 1], ['b' => 1], null, null], $cache->getMany($items));
        self::assertSame([['a' => 1], null, null, null], $cache->getMany($items, false));
        self::assertSame([], $cache->getMany([]));
    }
}
