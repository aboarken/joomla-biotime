<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Cache\UserCacheInterface;
use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;
use Psr\Log\LoggerInterface;

/**
 * Reads one employee's attendance from ZKBioTime's database (Database\AttendanceReader): the
 * punches, and each day's schedule and ZKBioTime's own calculation (late-in, early-out, worked
 * time, overtime, holidays, leave, days off). Every method takes the resolved Identity: the
 * employee is never chosen by request input. Each data type is read once per month and cached per
 * user, so My Dashboard, My Attendance, the Attendance Calendar and day details share it.
 *
 * Ownership: punches whose employee code differs from the identity's (or is missing) are dropped
 * and a warning is logged, although every query already filters by the employee.
 */
final class AttendanceService
{
    /** Fields of a punch as AttendanceReader::punches() returns it (iclock_transaction). */
    public const TX_EMP_CODE = 'emp_code';
    public const TX_TIME     = 'punch_time';
    public const TX_STATE    = 'punch_state_display';
    public const TX_DEVICE   = 'terminal_alias';
    public const TX_AREA     = 'area_alias';
    public const TX_VERIFY   = 'verify_type_display';
    public const TX_VERIFY_CODE = 'verify_type';

    /** Punches from this time of day on are check-outs; earlier punches are check-ins. */
    public const CHECK_OUT_FROM = '12:00:00';

    /**
     * How long a past month is kept in the cache: its punches no longer change, so it does not need
     * the short cache time of the current month (Integration Settings). Only while the cache is on.
     */
    public const PAST_MONTH_TTL = 86400;

    /**
     * A month is kept that long only once it ended this many days ago (1.3.8): ZKBioTime calculates
     * the last days, devices upload late and leave is approved afterwards, so a month that has just
     * ended is still kept for the cache time only.
     */
    public const SETTLED_AFTER_DAYS = 7;

    /** Database errors after which another connection attempt in the same request is pointless. */
    private const DOWN = [DbException::NOT_CONFIGURED, DbException::DRIVER_MISSING, DbException::UNREACHABLE, DbException::AUTH, DbException::SSL, DbException::DATABASE];

    /**
     * Part of every database cache key. Raised whenever the rows read from the database change
     * shape (0.6.3: late-in and early-out from ZKBioTime 9.5's pay codes), so an update never shows
     * months cached by an older version (past months are kept a day). 5 (1.3.7): every leave pay
     * code is recognised, whatever its name.
     */
    public const CACHE_VERSION = 6;

    /** The first year the portal offers (year lists, month navigation). */
    public const FIRST_YEAR = 2025;

    /** ISO weekdays (1 = Monday … 7 = Sunday) that are weekend days by default: Friday and Saturday. */
    public const DEFAULT_WEEKEND = [5, 6];

    public const TYPE_PUNCHES = 'punches';
    public const TYPE_DAILY   = 'daily';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /** @var array<string, array{read: int, hidden: int, skipped: int, cached: int, error: ?string, state: string}> */
    private array $stats = [];

    /** @var array<string, ?array<string, mixed>> cache entries already looked up by range() */
    private array $peek = [];

    /** False while range() reads without the cache. */
    private bool $cacheOn = true;

    /**
     * The error kind of a connection that failed in this request (1.3.8). Without it every month
     * and type tried to connect again: a school year waited for the connect timeout about 25 times.
     */
    private ?string $down = null;

    /**
     * @param \Closure(): AttendanceReader           $database    ZKBioTime's database, opened when first needed
     * @param \DateTimeZone                         $serverZone  ZKBioTime server time zone; times are shown as recorded
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param list<int>                             $weekendDays ISO weekdays without work (default Friday, Saturday)
     */
    public function __construct(
        private readonly \Closure $database,
        private readonly UserCacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly \DateTimeZone $serverZone,
        ?\Closure $clock = null,
        private readonly array $weekendDays = self::DEFAULT_WEEKEND
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /** Today's date (Y-m-d) in the ZKBioTime server's time zone. */
    public function today(): string
    {
        return ($this->clock)()->setTimezone($this->serverZone)->format('Y-m-d');
    }

    public function serverZone(): \DateTimeZone
    {
        return $this->serverZone;
    }

    /**
     * The employee's department, position, hire date and name, from the database; null when it
     * cannot be read. Kept a day in the cache.
     *
     * @return array{department: ?string, position: ?string, hire_date: ?string, name: ?string}|null
     */
    public function profile(Identity $identity): ?array
    {
        if (!$identity->isMatched()) {
            return null;
        }

        $cached = $this->cache->get($identity, 'db:profile2');

        if ($cached !== null) {
            return $cached['p'] ?? null;
        }

        try {
            $emp = ($this->database)()->employee($identity->empCode());
        } catch (DbException $e) {
            $this->logger->warning('ZKBioTime database: the employee profile could not be read (' . $e->getKind() . ')');

            return null;
        }

        $profile = $emp === null ? null : ['department' => $emp['department'], 'position' => $emp['position'], 'hire_date' => $emp['hire_date'], 'name' => $emp['name'] ?? null];
        // A profile not found (e.g. a code duplicated in ZKBioTime) is kept only for the cache time,
        // so once corrected it shows within minutes, not a day (1.3.7).
        $this->cache->set($identity, 'db:profile2', ['p' => $profile], $profile === null ? null : self::PAST_MONTH_TTL);

        return $profile;
    }

    /**
     * Every device the employee uses (punched on at any time, or registered on), from the database;
     * [] when it cannot be read. Kept a day in the cache.
     *
     * @return list<string>
     */
    public function devices(Identity $identity): array
    {
        if (!$identity->isMatched()) {
            return [];
        }

        $key    = 'db' . self::CACHE_VERSION . ':devices';
        $cached = $this->cache->get($identity, $key);

        if ($cached !== null && isset($cached['d'])) {
            return $cached['d'];
        }

        try {
            $devices = ($this->database)()->devices($identity->empCode());
        } catch (DbException $e) {
            $this->logger->warning('ZKBioTime database: the employee\'s devices could not be read (' . $e->getKind() . ')');

            return [];
        }

        $this->cache->set($identity, $key, ['d' => $devices], $devices === [] ? null : self::PAST_MONTH_TTL);

        return $devices;
    }

    /** @return list<int> ISO weekdays that are weekend days */
    public function weekendDays(): array
    {
        return $this->weekendDays;
    }

    public function month(Identity $identity, int $year, int $month): Period
    {
        $first = self::firstOfMonth($year, $month);

        return $this->range($identity, $first, $first->modify('last day of this month'));
    }

    /**
     * Every day from $from to $to (inclusive); callers limit the length.
     */
    public function range(Identity $identity, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $cache = true): Period
    {
        // Without the cache (1.0.9, Manager Dashboard): the team is read ahead in a few queries, so
        // storing and reading every employee's months in Joomla's database would only cost time.
        $this->cacheOn = $cache;

        try {
            return $this->readRange($identity, $from, $to);
        } finally {
            $this->cacheOn = true;
        }
    }

    /**
     * The employees of $identities whose months are not all in the cache (1.3.7: looked up in one
     * query for the whole team, not one per employee, month and type). The entries found are kept
     * for range(), which does not look them up again.
     *
     * @param  list<Identity> $identities
     * @return list<int> the positions in $identities of the employees to read from ZKBioTime
     */
    public function uncached(array $identities, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $items = [];
        $owner = [];

        foreach ($identities as $n => $identity) {
            for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('first day of next month')) {
                foreach ([self::TYPE_PUNCHES, self::TYPE_DAILY] as $type) {
                    $items[] = [$identity, self::cacheKey($type, (int) $m->format('Y'), (int) $m->format('n'))];
                    $owner[] = $n;
                }
            }
        }

        $missing = [];

        foreach ($this->cache->getMany($items) as $i => $value) {
            [$identity, $key]                          = $items[$i];
            $this->peek[$this->peekKey($identity, $key)] = $value;

            if ($value === null) {
                $missing[$owner[$i]] = true;
            }
        }

        return array_keys($missing);
    }

    /** The look-ups kept between uncached() and range() belong to one user and employee. */
    private function peekKey(Identity $identity, string $key): string
    {
        return $identity->joomlaUserId . '|' . ($identity->isMatched() ? $identity->empCode() : '') . '|' . $key;
    }

    private function readRange(Identity $identity, \DateTimeImmutable $from, \DateTimeImmutable $to): Period
    {
        $from = new \DateTimeImmutable($from->format('Y-m-d'));
        $to   = new \DateTimeImmutable($to->format('Y-m-d'));

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $data = [self::TYPE_PUNCHES => [], self::TYPE_DAILY => []];
        $errors  = [];
        $fetched = null;

        // Months not in the cache are read ahead together (1.0.2): about ten queries for all of
        // them instead of about fifteen per month (My Dashboard reads up to a school year).
        $missing = [];

        for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('first day of next month')) {
            foreach ([self::TYPE_PUNCHES, self::TYPE_DAILY] as $type) {
                $key = $this->peekKey($identity, self::cacheKey($type, (int) $m->format('Y'), (int) $m->format('n')));

                // Already looked up by uncached() (Manager Dashboard, 1.2.8/1.3.7), else now.
                if (!\array_key_exists($key, $this->peek)) {
                    $this->peek[$key] = $this->cacheOn ? $this->cache->get($identity, self::cacheKey($type, (int) $m->format('Y'), (int) $m->format('n'))) : null;
                }

                if ($this->peek[$key] === null) {
                    $missing[$m->format('Y-m')] = $m;
                }
            }
        }

        if (\count($missing) >= 2 && $identity->isMatched() && $this->down === null) {
            try {
                ($this->database)()->preload([$identity->empCode()], reset($missing), end($missing)->modify('last day of this month'));
            } catch (DbException $e) {
                // Each month is then read on its own and its error shown as usual.
                $this->noteDown($e);
            }
        }

        // Months that could not be read completely, and the hire date (1.3.8): see build().
        $unread = [];
        $hired  = null;

        for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('first day of next month')) {
            foreach ([self::TYPE_PUNCHES, self::TYPE_DAILY] as $type) {
                $part = $this->monthRows($identity, $type, (int) $m->format('Y'), (int) $m->format('n'));

                if ($part['error'] !== null) {
                    $errors[$type]            = $part['error'];
                    $unread[$m->format('Y-m')] = true;

                    continue;
                }

                $data[$type] = array_merge($data[$type], $part['rows']);
                $hired     ??= $part['hired'] ?? null;

                if ($part['at'] !== null) {
                    $fetched = $fetched === null ? $part['at'] : min($fetched, $part['at']);
                }
            }
        }

        return $this->build($from, $to, $data, $errors, $fetched, $unread, $hired);
    }

    private function noteDown(DbException $e): void
    {
        if (\in_array($e->getKind(), self::DOWN, true)) {
            $this->down ??= SourceException::kindOf($e);
        }
    }

    /**
     * Counters for the one log line each page writes (no personal data).
     *
     * @return array<string, array{read: int, hidden: int, skipped: int, cached: int, error: ?string, state: string}>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public static function firstOfMonth(int $year, int $month): \DateTimeImmutable
    {
        $year  = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));

        return new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $data
     * @param array<string, ?string>                    $errors
     * @param array<string, true>                       $unread months (Y-m) whose punches or days could not be read
     * @param ?string                                   $hired  the employee's hire date (Y-m-d), when known
     */
    private function build(\DateTimeImmutable $from, \DateTimeImmutable $to, array $data, array $errors, ?int $fetched, array $unread = [], ?string $hired = null): Period
    {
        $punches = [];

        foreach ($data[self::TYPE_PUNCHES] as $row) {
            $punch = Punch::fromCache($row);
            $punches[$punch->date][] = $punch;
        }

        foreach ($punches as &$list) {
            usort($list, static fn (Punch $a, Punch $b): int => $a->time <=> $b->time);
        }
        unset($list);

        $rows = [];

        foreach ($data[self::TYPE_DAILY] as $row) {
            $rows[$row['date']] ??= $row;
        }

        $today = $this->today();
        $days  = [];

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $p    = $punches[$date] ?? [];
            $row  = $rows[$date] ?? null;

            // Check-in and check-out from the punches, by the time of day: the cut-off of the day's
            // timetable (the end of its check-in window), else 12:00.
            [$checkIn, $checkOut] = self::checkTimes($p, $row['cutoff'] ?? self::CHECK_OUT_FROM);

            $weekend = \in_array((int) $d->format('N'), $this->weekendDays, true);
            $status  = null;
            $derived = true;
            $punched = $p !== [] || $checkIn !== null || $checkOut !== null;
            $raw     = $row['holiday'] ?? null;

            if (!$punched && isset($row['holiday'])) {
                // A holiday of ZKBioTime (for everyone, the department or the group): not absent.
                [$status, $derived] = ['holiday', false];
            } elseif (!$punched && (isset($row['leave_name']) || !empty($row['leave']))) {
                // Leave as ZKBioTime calculated it: shown by its name, not absent. The att_payloadbase
                // build gives only a leave flag (1.3.7: it was read but not used).
                [$status, $derived, $raw] = ['other', false, (string) ($row['leave_name'] ?? 'Leave')];
            } elseif (!$punched && !$weekend && !empty($row['off'])) {
                // A day off in the employee's schedule.
                [$status, $derived] = ['rest', false];
            } else {
                $status = self::dayStatus($date, $today, $weekend, $checkIn, $checkOut, $p !== []);

                // "Absent" is only said of a day the portal could read, and of an employee who was
                // already employed (1.3.8). Before, a database error made every working day of the
                // month absent (0% attendance), and a new employee was absent on every day of the
                // school year before the hire date.
                if ($status === 'absent' && (isset($unread[substr($date, 0, 7)]) || ($hired !== null && $date < $hired))) {
                    $status = null;
                }
            }

            $day = new Day(
                $date,
                $p,
                $row !== null,
                $row['schedule'] ?? null,
                $row['sched_start'] ?? null,
                $row['sched_end'] ?? null,
                $row['break'] ?? null,
                $row['schedule_note'] ?? null,
                $checkIn,
                $checkOut,
                $p !== [],
                isset($row['late']) ? (int) $row['late'] : null,
                isset($row['early']) ? (int) $row['early'] : null,
                isset($row['worked']) ? (int) $row['worked'] : null,
                isset($row['overtime']) ? (int) $row['overtime'] : null,
                $status,
                $raw,
                $weekend,
                $derived
            );

            // "No schedule" only for a day ZKBioTime has a row for (and not a holiday).
            $day->detectExceptions($row !== null && !isset($row['holiday']), $date < $today);
            $days[] = $day;
        }

        return new Period($from->format('Y-m-d'), $to->format('Y-m-d'), $days, $errors, $fetched, $today);
    }

    /**
     * The status of a day that is not a holiday, leave or day off, told from the punches only:
     *  - present:    any punch was recorded: the employee was there. When the check-in or the
     *                check-out is missing the day also carries the "Missing Punch" tag
     *                (Day::missingPunch());
     *  - checked_in:  today, checked in and not checked out yet;
     *  - checked_out: today, checked out (the day's check-out is recorded);
     *  - weekend:    a weekend day without punches (a weekend day with punches is treated like any
     *                other day, so the weekend tag disappears);
     *  - absent:     a past working day without any punch;
     *  - null:       today or a future working day without punches (nothing to say yet).
     */
    public static function dayStatus(string $date, string $today, bool $weekend, ?string $checkIn, ?string $checkOut, bool $hasPunches): ?string
    {
        $punched = $hasPunches || $checkIn !== null || $checkOut !== null;

        if (!$punched) {
            return match (true) {
                $weekend       => Day::STATUS_WEEKEND,
                $date < $today => 'absent',
                default        => null,
            };
        }

        if ($date === $today) {
            if ($checkOut !== null) {
                return Day::STATUS_CHECKED_OUT;
            }

            if ($checkIn !== null) {
                return Day::STATUS_CHECKED_IN;
            }
        }

        return 'present';
    }

    /**
     * One month of one data type: from the cache, or read from the database (punches normalised and
     * ownership-checked).
     *
     * @return array{rows: list<array<string, mixed>>, at: ?int, error: ?string, hired?: ?string}
     */
    private static function cacheKey(string $type, int $year, int $month): string
    {
        return 'db' . self::CACHE_VERSION . ':' . $type . ':' . \sprintf('%04d-%02d', $year, $month);
    }

    private function monthRows(Identity $identity, string $type, int $year, int $month): array
    {
        $stat = &$this->stats[$type];
        $stat ??= ['read' => 0, 'hidden' => 0, 'skipped' => 0, 'cached' => 0, 'error' => null, 'state' => 'ok'];

        // Rows of older versions are never read from the cache (CACHE_VERSION).
        $key    = self::cacheKey($type, $year, $month);
        $peek   = $this->peekKey($identity, $key);
        $cached = \array_key_exists($peek, $this->peek) ? $this->peek[$peek] : ($this->cacheOn ? $this->cache->get($identity, $key) : null);
        unset($this->peek[$peek]);

        if ($cached !== null && isset($cached['rows'], $cached['at'])) {
            $stat['cached']++;
            $stat['read'] += \count($cached['rows']);

            return ['rows' => $cached['rows'], 'at' => (int) $cached['at'], 'error' => null, 'hired' => \is_string($cached['hired'] ?? null) ? $cached['hired'] : null];
        }

        $first = self::firstOfMonth($year, $month);
        $last  = $first->modify('last day of this month');
        $ttl   = $last->modify('+' . self::SETTLED_AFTER_DAYS . ' days')->format('Y-m-d') < $this->today() ? self::PAST_MONTH_TTL : null;

        if ($this->down !== null) {
            $stat['error'] = $this->down;

            return ['rows' => [], 'at' => null, 'error' => $this->down];
        }

        try {
            $reader   = ($this->database)();
            $employee = $reader->employee($identity->empCode());

            // The code must still belong to the employee the user was mapped to (1.3.8): a code
            // given to another person in ZKBioTime showed that person's attendance until the daily
            // re-check of the mapping.
            if ($employee !== null && (string) $employee['id'] !== $identity->employeeId()) {
                $this->logger->warning(\sprintf('ZKBioTime database: the mapped code of Joomla user %d now belongs to another employee; nothing is shown', $identity->joomlaUserId));
                $employee = null;
            }

            if ($employee === null) {
                // Not exactly one employee with this code in the database: nothing is shown.
                $this->logger->warning(\sprintf('ZKBioTime database: no single employee with the mapped code of Joomla user %d', $identity->joomlaUserId));
                $stat['error'] = SourceException::NOT_FOUND;

                return ['rows' => [], 'at' => null, 'error' => SourceException::NOT_FOUND];
            }

            if ($type === self::TYPE_DAILY) {
                // Already one employee's (filtered by its id) and in the portal's form.
                $rows = array_map(static fn (array $r): array => $r + ['db' => true], $reader->days($employee, $first, $last));
                $at   = ($this->clock)()->getTimestamp();
                $stat['read'] += \count($rows);

                $hired = $employee['hire_date'] ?? null;

                if ($this->cacheOn) {
                    $this->cache->set($identity, $key, ['rows' => $rows, 'at' => $at, 'hired' => $hired], $ttl);
                }

                return ['rows' => $rows, 'at' => $at, 'error' => null, 'hired' => $hired];
            }

            $items = $reader->punches($identity->empCode(), $first, $last);
        } catch (DbException $e) {
            $kind = SourceException::kindOf($e);
            $this->noteDown($e);
            // The kind and SQLSTATE only (1.0.10): the driver's text can name the database user,
            // the server or a value. Test Database shows the full text to the administrator.
            $this->logger->warning('ZKBioTime database: ' . $type . ' could not be read (' . $e->getKind() . ($e->sqlState() !== '' ? ', SQLSTATE ' . $e->sqlState() : '') . ')');
            $stat['error'] = $kind;

            return ['rows' => [], 'at' => null, 'error' => $kind];
        }

        $rows   = [];
        $hidden = 0;

        foreach ($items as $item) {
            $code = isset($item[self::TX_EMP_CODE]) && is_scalar($item[self::TX_EMP_CODE]) ? (string) $item[self::TX_EMP_CODE] : null;

            if ($code !== $identity->empCode()) {
                $hidden++;

                continue;
            }

            $row = self::normalisePunch($item);

            // A punch outside the month asked for would be counted twice when the next month is
            // read: it is skipped.
            if ($row === null || substr($row['t'], 0, 7) !== $first->format('Y-m')) {
                $stat['skipped']++;

                continue;
            }

            $rows[] = $row;
        }

        if ($hidden > 0) {
            $this->logger->warning(\sprintf('ZKBioTime returned %d %s rows of other employees (or without an employee code) for Joomla user %d; they were hidden', $hidden, $type, $identity->joomlaUserId));
        }

        $stat['read']   += \count($rows);
        $stat['hidden'] += $hidden;

        $at = ($this->clock)()->getTimestamp();

        if ($this->cacheOn) {
            $this->cache->set($identity, $key, ['rows' => $rows, 'at' => $at], $ttl);
        }

        return ['rows' => $rows, 'at' => $at, 'error' => null];
    }

    /**
     * @param array<mixed> $item
     *
     * @return array{t: string, s: ?string, c: ?string, d: ?string, a: ?string, v: ?string, n: ?string, vc: ?string}|null
     */
    private static function normalisePunch(array $item): ?array
    {
        $time = isset($item[self::TX_TIME]) && \is_string($item[self::TX_TIME]) ? self::dateTime($item[self::TX_TIME]) : null;

        if ($time === null) {
            return null;
        }

        $text = static fn (string $k): ?string => isset($item[$k]) && is_scalar($item[$k]) && trim((string) $item[$k]) !== ''
            ? mb_substr(trim(strip_tags((string) $item[$k])), 0, 100)
            : null;

        $verifyCode = isset($item[self::TX_VERIFY_CODE]) && is_scalar($item[self::TX_VERIFY_CODE]) && preg_match('/^\d{1,3}$/', (string) $item[self::TX_VERIFY_CODE]) ? (string) $item[self::TX_VERIFY_CODE] : null;

        return ['t' => $time, 's' => $text(self::TX_STATE), 'd' => $text(self::TX_DEVICE), 'a' => $text(self::TX_AREA), 'v' => $text(self::TX_VERIFY), 'vc' => $verifyCode];
    }

    /**
     * Check-in and check-out from one day's punches (in time order), exactly as recorded. The time
     * of day decides, whatever state the device recorded:
     *  - check-in:  the first punch before the cut-off;
     *  - check-out: the last punch at the cut-off or later;
     *  - a day with punches only before the cut-off has no check-out, a day with punches only from
     *    it has no check-in ("Missing Punch").
     * The cut-off is the end of the check-in window of the day's timetable, or 12:00
     * (CHECK_OUT_FROM) on a day without one.
     *
     * @param list<Punch> $punches
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function checkTimes(array $punches, string $cutoff = self::CHECK_OUT_FROM): array
    {
        $in = $out = null;

        foreach ($punches as $punch) {
            if ($punch->time < $cutoff) {
                $in ??= $punch->time;
            } else {
                $out = $punch->time;
            }
        }

        return [$in, $out];
    }

    /** "YYYY-MM-DD HH:MM[:SS]" (or with T), kept as the server recorded it: never shifted. */
    public static function dateTime(string $value): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', trim($value), $m) || !checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00');
    }
}
