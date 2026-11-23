<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Punch;
use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Administrator\Support\Formatter;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use PHPUnit\Framework\TestCase;

/**
 * Month data read from ZKBioTime's database (AttendanceReader over CatalogueQuery): ownership
 * checks (punches of other employees are dropped), per-day schedules and ZKBioTime's calculation,
 * day statuses from punches (present with or without a missing punch, absent, weekend), holidays,
 * days off, summaries, punch counts by verification, exceptions, cache and independent failures.
 */
final class AttendanceServiceTest extends TestCase
{
    private ArrayLogger $log;

    /** @var array<string, mixed> */
    private array $session = [];

    private Identity $alice;

    /** iclock_transaction rows (any employee, any month). */
    private array $punches = [];

    /** att_payloadbase rows of Alice (employee id 1). */
    private array $base = [];

    /** att_holiday rows. */
    private array $holidays = [];

    /** @var array<string, \Closure(): never> fragment => failure, for tables that fail */
    private array $failing = [];

    /** @var array<string, list<array<string, mixed>>> fragment => parameters of each query sent */
    private array $params = [];

    protected function setUp(): void
    {
        $this->log   = new ArrayLogger();
        $this->alice = Identity::matched(5, '11', 'E1');

        // Punches as ZKBioTime keeps them (punch_state 0 = Check In, 1 = Check Out, 255 = unknown;
        // verify_type 1 = Fingerprint, 4 = Card, 15 = Face). Bob's punch and a row without a code
        // are returned too, as if the employee filter were ignored: they must be hidden.
        $this->punches = [
            $this->punch('2026-11-02 07:58:10', '0', 1, 'Gate A', 'HQ'),
            $this->punch('2026-11-02 17:01:00', '1', 1, 'Gate A', 'HQ'),
            $this->punch('2026-11-03 09:15:00', '0', 15, 'Gate B'),
            $this->punch('2026-11-03 09:15:30', '0', 15, 'Gate B'),
            $this->punch('2026-11-03 15:00:00', '1', 15),
            $this->punch('2026-11-04 08:00:00', '0', 1),
            $this->punch('2026-11-05 03:33:33', '0', 1, 'BOB-GATE', null, 'E2'),
            $this->punch('2026-11-06 03:33:33', '0', 1, null, null, ''),
        ];

        // ZKBioTime's calculation: a different timetable on different days (stored in minutes).
        $this->base = [
            $this->day('2026-11-02', 'Morning', '08:00:00', '17:00:00', 0, 0, 540, ['total_ot' => 1]),
            $this->day('2026-11-03', 'Late Shift', '09:00:00', '18:00:00', 15, 180, 345),
            $this->day('2026-11-05', 'Morning', '08:00:00', '17:00:00', 0, 0, 0, ['absent' => 480]),
            $this->day('2026-11-09', null, null, null, 0, 0, 0, ['day_off' => 1]),
        ];

        $this->holidays = [
            ['alias' => 'National Day', 'first_day' => '2026-11-11', 'last_day' => '2026-11-11', 'department_id' => null, 'att_group_id' => null],
        ];
    }

    private function punch(string $time, string $state, ?int $verify, ?string $device = null, ?string $area = null, string $code = 'E1'): array
    {
        return ['punch_time' => $time, 'emp_code' => $code, 'punch_state' => $state, 'verify_type' => $verify, 'terminal_alias' => $device, 'area_alias' => $area];
    }

    private function day(string $date, ?string $timetable, ?string $in, ?string $out, int $late, int $early, int $worked, array $more = []): array
    {
        return $more + [
            'att_date' => $date, 'sched_in' => $in, 'sched_out' => $out, 'sched_seconds' => $in !== null ? 32400 : 0, 'duration' => $in !== null ? 540 : 0,
            'late' => $late, 'early_leave' => $early, 'total_worked' => $worked, 'absent' => 0, 'leave' => 0, 'day_off' => 0,
            'timetable' => $timetable, 'in_above_margin' => $in !== null ? 240 : null, 'tt_duration' => $in !== null ? 540 : null, 'total_ot' => null,
        ];
    }

    /** The database: answers from the fixture rows, recording each query's parameters. */
    private function query(): CatalogueQuery
    {
        $answer = function (string $fragment, \Closure $rows): \Closure {
            return function (array $p) use ($fragment, $rows): array {
                $this->params[$fragment][] = $p;

                if (isset($this->failing[$fragment])) {
                    ($this->failing[$fragment])();
                }

                return $rows($p);
            };
        };

        return new CatalogueQuery('pgsql', [
            // One code (:code) or several read ahead together (:c0, :c1, ...; 1.0.2).
            'FROM personnel_employee' => $answer('FROM personnel_employee', static fn (array $p): array => array_merge([], ...array_map(static fn (string $code): array => match ($code) {
                'E1'    => [['id' => 1, 'emp_code' => 'E1', 'department_id' => 10, 'first_name' => 'Alice', 'last_name' => 'A', 'dept_name' => 'IT', 'position_name' => 'Developer', 'hire_date' => '2024-09-01']],
                'E2'    => [['id' => 2, 'emp_code' => 'E2', 'department_id' => 10, 'first_name' => 'Bob', 'last_name' => 'B', 'dept_name' => 'IT', 'position_name' => 'Tester', 'hire_date' => null]],
                default => [],
            }, isset($p[':code']) ? [$p[':code']] : array_values(array_filter($p, static fn (string $k): bool => preg_match('/^:c\d+$/', $k) === 1, ARRAY_FILTER_USE_KEY))))),
            'FROM att_attemployee'    => [],
            // Only the month asked for, but every employee (the service checks the code itself).
            'FROM iclock_transaction' => $answer('FROM iclock_transaction', fn (array $p): array => array_values(array_filter(
                $this->punches,
                static fn (array $r): bool => $r['punch_time'] >= $p[':from'] && $r['punch_time'] < $p[':to']
            ))),
            // Alice's rows (emp_id 1), asked for alone (:emp) or read ahead with others (k).
            'FROM att_payloadbase'    => $answer('FROM att_payloadbase', fn (array $p): array => ($p[':emp'] ?? 1) !== 1 ? [] : array_values(array_map(
                static fn (array $r): array => $r + ['k' => 1],
                array_filter($this->base, static fn (array $r): bool => $r['att_date'] >= $p[':from'] && $r['att_date'] <= $p[':to'])
            ))),
            'FROM att_holiday'        => $answer('FROM att_holiday', fn (): array => $this->holidays),
            // Time cards, pay codes and schedules: not on this server (optional tables).
            'FROM att_'               => static fn (): array => throw new DbException(DbException::QUERY, 'relation does not exist'),
        ]);
    }

    private function service(int $ttl = 300, string $now = '2026-11-20 12:00:00', array $weekend = [5, 6], ?\Closure $cacheClock = null): AttendanceService
    {
        $reader = new AttendanceReader(fn () => $this->query(), 'Asia/Riyadh', 'minutes');

        return new AttendanceService(
            static fn () => $reader,
            Fakes::cache($this->session, $ttl, $cacheClock),
            $this->log,
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable($now, new \DateTimeZone('UTC')),
            $weekend
        );
    }

    private function reads(string $fragment = 'FROM iclock_transaction'): int
    {
        return \count($this->params[$fragment] ?? []);
    }

    public function testOnlyTheIdentitysEmployeeAndMonthAreRequested(): void
    {
        $this->service()->month($this->alice, 2026, 11);

        $tx = $this->params['FROM iclock_transaction'][0];
        self::assertSame(['E1', '2026-11-01 00:00:00', '2026-12-01 00:00:00', 'Asia/Riyadh'], [$tx[':code'], $tx[':from'], $tx[':to'], $tx[':tz1']]);

        $daily = $this->params['FROM att_payloadbase'][0];
        self::assertSame([1, '2026-11-01', '2026-11-30'], [$daily[':emp'], $daily[':from'], $daily[':to']]);
        self::assertSame('E1', $this->params['FROM personnel_employee'][0][':code']);
    }

    public function testPunchesOfOtherEmployeesAreHiddenAndLogged(): void
    {
        $service = $this->service();
        $period  = $service->month($this->alice, 2026, 11);

        self::assertStringNotContainsString('BOB-GATE', serialize($period));
        self::assertSame([], $period->day('2026-11-05')->punches);
        self::assertSame([], $period->day('2026-11-06')->punches, 'a punch without an employee code');
        self::assertTrue($this->log->has('warning', 'rows of other employees'));
        self::assertStringNotContainsString('E2', $this->log->text(), 'no employee codes in the log');
        self::assertSame(2, $service->stats()['punches']['hidden']);
    }

    public function testEachDateKeepsItsOwnScheduleAndZkbioTimesCalculation(): void
    {
        $period = $this->service()->month($this->alice, 2026, 11);

        $d2 = $period->day('2026-11-02');
        $d3 = $period->day('2026-11-03');
        self::assertSame(['Morning', '08:00:00', '17:00:00'], [$d2->schedule, $d2->schedStart, $d2->schedEnd]);
        self::assertSame(['Late Shift', '09:00:00', '18:00:00'], [$d3->schedule, $d3->schedStart, $d3->schedEnd]);
        self::assertSame([15, 180, 345], [$d3->late, $d3->early, $d3->worked], 'as ZKBioTime calculated them, nothing recalculated');
        self::assertSame([0, 0, 540, 1], [$d2->late, $d2->early, $d2->worked, $d2->overtime]);
        self::assertSame(['09:15:00', '15:00:00'], [$d3->checkIn, $d3->checkOut], 'check-in and check-out from the punches');
        self::assertTrue($d3->checkFromPunches);
        self::assertSame(['present', 'present', 'absent'], [$d2->status, $d3->status, $period->day('2026-11-05')->status]);
        self::assertSame('rest', $period->day('2026-11-09')->status, 'a day off in the schedule is not absent');
        self::assertSame(['holiday', 'National Day'], [$period->day('2026-11-11')->status, $period->day('2026-11-11')->statusRaw]);
        self::assertSame('absent', $period->day('2026-11-10')->status, 'a past working day without any punch');
        self::assertCount(30, $period->days);
        self::assertSame([], $period->errors);
    }

    public function testWithoutZkbioTimesCalculationStatusesComeFromThePunches(): void
    {
        $this->base = $this->holidays = [];

        // Today is Friday 2026-11-20 (Asia/Riyadh); Friday and Saturday are weekend days.
        $period = $this->service()->month($this->alice, 2026, 11);

        $d2 = $period->day('2026-11-02');
        self::assertSame(['07:58:10', '17:01:00'], [$d2->checkIn, $d2->checkOut], 'first check-in and last check-out as recorded');
        self::assertTrue($d2->checkFromPunches);
        self::assertTrue($d2->statusFromPunches);
        self::assertSame('present', $d2->status);
        self::assertSame(542, $d2->span());
        self::assertNull($d2->schedule);
        self::assertNull($d2->late, 'late-in only from ZKBioTime\'s calculation');

        $d4 = $period->day('2026-11-04');
        self::assertSame('present', $d4->status, 'check-in only: present ...');
        self::assertTrue($d4->missingPunch(), '... with the Missing Punch tag');
        self::assertFalse($d2->missingPunch());
        self::assertSame('absent', $period->day('2026-11-05')->status, 'past working day without punches');
        self::assertSame('absent', $period->day('2026-11-01')->status, 'Sunday is a working day');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-06')->status, 'Friday');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-07')->status, 'Saturday');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-20')->status, 'today, a Friday');
        self::assertNull($period->day('2026-11-23')->status, 'future working day: nothing to say yet');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-27')->status, 'future weekend');
    }

    public function testAPunchOnAWeekendDayRemovesTheWeekendTag(): void
    {
        $this->punches = [
            $this->punch('2026-11-07 08:00:00', '0', 1),
            $this->punch('2026-11-07 12:30:00', '255', 1),
            $this->punch('2026-11-14 09:00:00', '0', 1),
        ];

        $period = $this->service()->month($this->alice, 2026, 11);
        $sat    = $period->day('2026-11-07');

        self::assertTrue($sat->weekend);
        self::assertTrue($sat->workedOnWeekend());
        self::assertSame('present', $sat->status);
        self::assertSame('present', $period->day('2026-11-14')->status, 'a weekend day with only a check-in');
        self::assertTrue($period->day('2026-11-14')->missingPunch());
        self::assertSame(2, $period->summary()->weekendWorked);
    }

    public function testTodayWithACheckInIsCheckedInAndWithACheckOutCheckedOut(): void
    {
        $this->punches = [$this->punch('2026-11-19 07:40:00', '0', 1)];
        $day           = $this->service(300, '2026-11-19 06:00:00')->month($this->alice, 2026, 11)->day('2026-11-19');

        self::assertSame(Day::STATUS_CHECKED_IN, $day->status);
        self::assertNotContains(Day::EX_MISSING_OUT, $day->exceptions, 'the day is not over');
        self::assertFalse($day->missingPunch(), 'the check-out may still come');

        $this->session = [];
        $this->punches = [$this->punch('2026-11-19 07:40:00', '0', 1), $this->punch('2026-11-19 14:05:00', '0', 1)];
        $day           = $this->service(300, '2026-11-19 12:00:00')->month($this->alice, 2026, 11)->day('2026-11-19');

        self::assertSame(['07:40:00', '14:05:00'], [$day->checkIn, $day->checkOut], 'the 14:05 punch is the check-out');
        self::assertSame(Day::STATUS_CHECKED_OUT, $day->status);
        self::assertFalse($day->missingPunch());
        self::assertContains(Day::STATUS_CHECKED_OUT, Day::ATTENDED);
    }

    public function testWeekendDaysAreConfigurable(): void
    {
        $period = $this->service(300, '2026-11-20 12:00:00', [6, 7])->month($this->alice, 2026, 11);

        self::assertSame('absent', $period->day('2026-11-06')->status, 'Friday is a working day here');
        self::assertSame(Day::STATUS_WEEKEND, $period->day('2026-11-08')->status, 'Sunday');
    }

    public function testSummaryIsCountedFromThePunchesAndZkbioTimesCalculation(): void
    {
        $s = $this->service()->month($this->alice, 2026, 11)->summary();

        self::assertSame(3, $s->presentDays, 'the missing-punch day counts as present ...');
        self::assertSame(1, $s->missingPunchDays, '... and as a missing punch');
        self::assertSame([0, 1], [$s->missingMorningDays, $s->missingAfternoonDays], 'the 4th has a check-in only: the afternoon punch is missing');
        self::assertSame(1, $s->holidayDays);
        self::assertSame(5, $s->weekendDays);
        self::assertSame(0, $s->weekendWorked);
        self::assertSame(6, $s->punchCount);
        self::assertSame(3, $s->punchDays);
        self::assertSame([15, 180, 1, 1], [$s->lateMinutes, $s->earlyMinutes, $s->lateDays, $s->earlyDays]);
        self::assertSame(540 + 345, $s->workedMinutes);
        self::assertSame('07:58:10', $s->firstCheckIn);
        self::assertSame('17:01:00', $s->lastCheckOut);
    }

    public function testMyPunchesCountsByVerificationMonthAndDevice(): void
    {
        $this->punches = [
            $this->punch('2026-10-05 07:50:00', '0', 15, 'Gate A', 'HQ'),
            $this->punch('2026-10-05 15:10:00', '255', 1, 'Gate B'),
            $this->punch('2026-11-02 07:58:00', '0', 1, 'Gate A'),
            $this->punch('2026-11-02 16:00:00', '1', 15, 'Gate A'),
            $this->punch('2026-11-03 08:00:00', '0', 4),
        ];

        $period = $this->service()->range($this->alice, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-20'));
        $stats  = \BiotimePortal\Component\Biotime\Administrator\Attendance\PunchStats::of($period->days);

        self::assertSame(['fingerprint' => 2, 'face' => 2, 'other' => 1, 'total' => 5, 'days' => 3], $stats->total);
        self::assertSame(['fingerprint' => 1, 'face' => 1, 'other' => 0, 'total' => 2, 'days' => 1], $stats->months[10]);
        self::assertSame(3, $stats->months[11]['total']);
        self::assertSame(['Gate A', 'Gate B', '—'], array_column($stats->devices, 'device'), 'most used device first');
        self::assertSame(['fingerprint' => 1, 'face' => 2, 'total' => 3, 'last' => '2026-11-02 16:00:00'], array_intersect_key($stats->devices[0], ['fingerprint' => 0, 'face' => 0, 'total' => 0, 'last' => 0]));
    }

    public function testPunchDetailsIncludeHowAndWhereButNotTheWordUnknown(): void
    {
        $this->punches = [
            $this->punch('2026-11-12 07:45:00', '0', 15, 'Main Gate', 'HQ'),
            $this->punch('2026-11-12 15:20:00', '255', 99, 'Main Gate', ' Unknown '),
        ];

        $day = $this->service()->month($this->alice, 2026, 11)->day('2026-11-12');

        self::assertSame(['Check In', 'Face', 'Main Gate', 'HQ'], [$day->punches[0]->state, $day->punches[0]->verify, $day->punches[0]->device, $day->punches[0]->area]);
        self::assertSame([null, null, null, 'Main Gate'], [$day->punches[1]->state, $day->punches[1]->verify, $day->punches[1]->area, $day->punches[1]->device]);
        self::assertSame(['07:45:00', '15:20:00'], [$day->checkIn, $day->checkOut], 'the punch still counts');
    }

    public function testTheBiometricPrefixIsRemovedFromDeviceNames(): void
    {
        self::assertSame('Main Gate', Punch::deviceName('Biometric - Main Gate'));
        self::assertSame('Gym', Punch::deviceName('biometric-Gym'));
        self::assertSame('Biometric', Punch::deviceName('Biometric'), 'a name that is only the word stays');
        self::assertSame('Side Door', Punch::fromCache(['t' => '2026-11-02 07:00:00', 'd' => 'Biometric – Side Door'])->device);
    }

    public function testExceptionsAreDetectedButNothingIsCorrected(): void
    {
        $period = $this->service()->month($this->alice, 2026, 11);

        self::assertContains(Day::EX_DUPLICATE, $period->day('2026-11-03')->exceptions);
        self::assertContains(Day::EX_LATE, $period->day('2026-11-03')->exceptions);
        self::assertContains(Day::EX_EARLY, $period->day('2026-11-03')->exceptions);
        self::assertContains(Day::EX_MISSING_OUT, $period->day('2026-11-04')->exceptions, 'one check-in punch only');
        self::assertSame('08:00:00', $period->day('2026-11-04')->checkIn);
        self::assertNull($period->day('2026-11-04')->checkOut);
        self::assertNotContains(Day::EX_NO_SCHEDULE, $period->day('2026-11-04')->exceptions, 'no row from ZKBioTime for the date: nothing to say');

        // A row from ZKBioTime without a timetable, but punches: worth noting.
        $this->session = [];
        $this->base[]  = $this->day('2026-11-04', null, null, null, 0, 0, 0);
        self::assertContains(Day::EX_NO_SCHEDULE, $this->service()->month($this->alice, 2026, 11)->day('2026-11-04')->exceptions);
    }

    public function testMonthIsCachedPerUserAndEmployee(): void
    {
        $this->service()->month($this->alice, 2026, 11);
        $reads = $this->reads();

        $again = $this->service()->month($this->alice, 2026, 11);
        self::assertSame($reads, $this->reads(), 'second read comes from the cache');
        self::assertSame($reads, $this->reads('FROM att_payloadbase'));
        self::assertNotNull($again->fetchedAt);

        // Another employee (or a re-mapped user) never sees the cached rows.
        $bob = $this->service()->month(Identity::matched(5, '13', 'E2'), 2026, 11);
        self::assertGreaterThan($reads, $this->reads());
        self::assertSame(['03:33:33'], array_map(static fn (Punch $p): string => $p->time, $bob->day('2026-11-05')->punches), 'Bob sees only his own punch');

        $before = $this->reads();
        $this->service(0)->month($this->alice, 2026, 11);
        self::assertGreaterThan($before, $this->reads(), 'cache time 0 reads again');
    }

    public function testPastMonthsAreKeptForADayTheCurrentMonthForTheCacheTime(): void
    {
        $time  = strtotime('2026-11-20 12:00:00 UTC');
        // Each call is a new page load: a new service and reader, the same cache.
        $range = function () use (&$time): void {
            $this->service(300, '2026-11-20 12:00:00', [5, 6], static function () use (&$time): int {
                return $time;
            })->range($this->alice, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-20'));
        };

        $range();
        self::assertSame(1, $this->reads(), 'October and November read together (1.0.2)');

        $time += 600; // 10 minutes later: past the 5-minute cache time
        $range();
        self::assertSame(2, $this->reads(), 'only November (the current month) is read again');
        self::assertSame('2026-11-01 00:00:00', $this->params['FROM iclock_transaction'][1][':from']);

        $time += 86400; // a day later: October is read again too, together with November
        $range();
        self::assertSame(3, $this->reads());
    }

    public function testOneFailingPartDoesNotHideTheOthers(): void
    {
        $this->failing['FROM att_payloadbase'] = static fn () => throw new DbException(DbException::QUERY, 'permission denied for table att_payloadbase');

        $period = $this->service()->month($this->alice, 2026, 11);

        self::assertSame('server', $period->error('daily'));
        self::assertNull($period->error('punches'));
        self::assertCount(2, $period->day('2026-11-02')->punches);
        self::assertTrue($this->log->has('warning', 'daily could not be read'));
    }

    public function testDatabaseErrorsBecomePortalMessages(): void
    {
        foreach ([DbException::UNREACHABLE => 'network', DbException::AUTH => 'auth', DbException::NOT_CONFIGURED => 'not_configured', DbException::DATABASE => 'server'] as $db => $kind) {
            $this->session = [];
            $this->failing['FROM personnel_employee'] = static fn () => throw new DbException($db, 'x');

            $period = $this->service()->month($this->alice, 2026, 11);
            self::assertSame(['punches' => $kind, 'daily' => $kind], $period->errors, $db);
            self::assertSame([], $period->day('2026-11-02')->punches);
        }

        // No (or more than one) employee with the mapped code: nothing is shown.
        $this->failing = [];
        $period = $this->service()->month(Identity::matched(5, '99', 'E99'), 2026, 11);
        self::assertSame(['punches' => 'not_found', 'daily' => 'not_found'], $period->errors);
    }

    public function testRangeOverTwoMonthsReadsBoth(): void
    {
        $period = $this->service()->range($this->alice, new \DateTimeImmutable('2026-10-25'), new \DateTimeImmutable('2026-11-03'));

        self::assertCount(10, $period->days);
        self::assertSame(1, $this->reads(), 'both months in one read (1.0.2)');
        self::assertSame(['2026-10-01 00:00:00', '2026-12-01 00:00:00'], [$this->params['FROM iclock_transaction'][0][':from'], $this->params['FROM iclock_transaction'][0][':to']]);
        self::assertSame('2026-10-25', $period->from);
        self::assertSame('Late Shift', $period->day('2026-11-03')->schedule);
    }

    /** The time of day decides: before the cut-off check-in, from it check-out (whatever state the device recorded). */
    public function testCheckInBeforeTheCutOffCheckOutFromIt(): void
    {
        $p = static fn (string $t, ?string $state, ?string $code = null): Punch => new Punch('2026-11-02', $t, $state, null, null, $code);

        self::assertSame([null, '17:00:00'], AttendanceService::checkTimes([$p('17:00:00', 'Check Out')]));
        self::assertSame([null, '15:30:00'], AttendanceService::checkTimes([$p('15:30:00', 'Check In', '0')]), 'an afternoon "Check In" punch is the check-out');
        self::assertSame(['08:00:00', null], AttendanceService::checkTimes([$p('08:00:00', 'Check Out', '1')]), 'a morning "Check Out" punch is the check-in');
        self::assertSame(['11:59:59', '12:00:00'], AttendanceService::checkTimes([$p('11:59:59', null), $p('12:00:00', null)]), 'without a timetable: 12:00');
        self::assertSame(['07:50:00', '17:00:00'], AttendanceService::checkTimes([
            $p('07:50:00', 'Check In'), $p('07:51:00', 'Check In'), $p('12:30:00', 'Break Out'), $p('13:00:00', 'Break In'), $p('17:00:00', 'Check Out'),
        ]), 'first morning punch and last afternoon punch');
        self::assertSame([null, '11:40:00'], AttendanceService::checkTimes([$p('11:40:00', null)], '11:30:00'), 'the timetable\'s cut-off');
        self::assertSame([null, null], AttendanceService::checkTimes([]));

        // Through the whole service: the 3rd's timetable starts at 09:00 with a 4-hour check-in
        // window, so the cut-off is 13:00 and a 12:30 punch is still a check-in.
        $this->punches = [$this->punch('2026-11-03 12:30:00', '1', 1), $this->punch('2026-11-09 17:05:00', '1', 1)];
        $period        = $this->service()->month($this->alice, 2026, 11);

        self::assertSame(['12:30:00', null], [$period->day('2026-11-03')->checkIn, $period->day('2026-11-03')->checkOut]);
        $day = $period->day('2026-11-09');
        self::assertNull($day->checkIn);
        self::assertSame('17:05:00', $day->checkOut);
        self::assertContains(Day::EX_MISSING_IN, $day->exceptions);
        self::assertNotContains(Day::EX_MISSING_OUT, $day->exceptions);
    }

    public function testValueParsingHelpers(): void
    {
        self::assertSame('2026-11-02 08:05:00', AttendanceService::dateTime('2026-11-02T08:05'));
        self::assertNull(AttendanceService::dateTime('2026-02-30 08:00:00'));

        $f = new Formatter('d M Y', 'h:i A', static fn (string $k): string => $k === 'NOVEMBER_SHORT' ? 'Nov' : $k);
        self::assertSame('05 Nov 2026', $f->date('2026-11-05'));
        self::assertSame('02:05 COM_BIOTIME_PM', $f->time('14:05:00'));
        self::assertSame('8:05', $f->duration(485));
        self::assertSame('—', $f->duration(null));
    }

    /** 1.0.9: the Manager Dashboard reads without Joomla's cache (neither stored nor read). */
    public function testARangeWithoutTheCacheNeitherStoresNorReadsIt(): void
    {
        $this->service()->range($this->alice, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-20'), false);
        self::assertSame([], $this->session, 'nothing stored');
        self::assertSame(1, $this->reads());

        $this->service()->range($this->alice, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-20'));
        $this->service()->range($this->alice, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-20'), false);
        self::assertSame(3, $this->reads(), 'the cached copy is not used either');
    }

    public function testUncachedLooksUpTheTeamOnceAndKeepsEachEmployeesEntriesApart(): void
    {
        $service = $this->service();
        $from    = new \DateTimeImmutable('2026-11-01');
        $to      = new \DateTimeImmutable('2026-11-20');
        $bob     = Identity::matched(5, '13', 'E2');

        self::assertSame([0], $service->uncached([$this->alice], $from, $to));
        $service->range($this->alice, $from, $to);
        self::assertSame(1, $this->reads());

        // Alice is now cached, Bob (same Joomla user: a manager reading both) is not.
        self::assertSame([1], $service->uncached([$this->alice, $bob], $from, $to), 'only Bob (position 1)');

        $alice = $service->range($this->alice, $from, $to);
        self::assertSame(1, $this->reads(), 'Alice from the cache');
        $b = $service->range($bob, $from, $to);
        self::assertSame(2, $this->reads(), 'Bob read from the database, never with Alice\'s rows');
        self::assertSame(['03:33:33'], array_map(static fn (Punch $p): string => $p->time, $b->day('2026-11-05')->punches));
        self::assertNotSame($alice->day('2026-11-05')->punches, $b->day('2026-11-05')->punches);
    }

    /** 1.3.7 (audit): the att_payloadbase build marks leave with a flag only; such a day is leave, not absent. */
    public function testALeaveFlagWithoutAPayCodeNameIsLeaveNotAbsent(): void
    {
        $this->base[] = $this->day('2026-11-10', 'Morning', '08:00:00', '17:00:00', 0, 0, 0, ['leave' => 480]);

        $day = $this->service()->month($this->alice, 2026, 11)->day('2026-11-10');

        self::assertSame(['other', 'Leave'], [$day->status, $day->statusRaw]);
    }
}
