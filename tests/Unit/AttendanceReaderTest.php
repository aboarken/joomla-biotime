<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use PHPUnit\Framework\TestCase;

/**
 * Attendance read from ZKBioTime's database (0.6.0): the reader's queries and their results, and
 * the month pages built from them (schedule, late-in, early-out, worked time, holidays, totals).
 */
final class AttendanceReaderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /** @return array<string, list<array<string, mixed>>|\Closure> */
    private function answers(int $durationFactor = 60): array
    {
        $base = static fn (string $date, ?string $in, ?string $out, ?string $cin, ?string $cout, int $late, int $early, int $worked, array $more = []): array => $more + [
            'att_date' => $date, 'sched_in' => $in, 'sched_out' => $out, 'sched_seconds' => $in !== null ? 28800 : 0,
            'clock_in' => $cin, 'clock_out' => $cout, 'duration' => $in !== null ? 480 * $durationFactor : 0,
            'late' => $late * $durationFactor, 'early_leave' => $early * $durationFactor, 'total_worked' => $worked * $durationFactor,
            'absent' => 0, 'leave' => 0, 'day_off' => 0, 'timetable' => $in !== null ? 'Day 07:30-15:30' : null, 'total_ot' => null,
            'in_above_margin' => $in !== null ? 240 : null, 'tt_duration' => $in !== null ? 480 : null,
        ];
        // As on the real server: the shift day's own end is 00:00 (not set); the timetable gives 15:30.
        $interval = ['alias' => 'Day 07:30-15:30', 't_in' => '07:30:00', 'duration' => 480, 'in_above_margin' => 240, 'd_in' => '07:30:00', 'd_out' => '00:00:00'];

        return [
            'FROM personnel_employee' => [['id' => 1, 'department_id' => 10, 'first_name' => 'Alice', 'last_name' => 'A', 'dept_name' => 'Information Technology', 'position_name' => 'Developer', 'hire_date' => '2024-09-01']],
            'FROM att_attemployee'    => [['group_id' => 5]],
            'FROM iclock_transaction' => [
                ['punch_time' => '2026-11-02 07:42:10', 'emp_code' => 'E1', 'punch_state' => '0', 'verify_type' => 15, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-02 15:05:00', 'emp_code' => 'E1', 'punch_state' => '1', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-09 08:00:00', 'emp_code' => 'E1', 'punch_state' => '0', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-09 16:00:00', 'emp_code' => 'E1', 'punch_state' => '1', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                // Thursday 19th, not calculated: the timetable's cut-off is 11:30, so 11:40 is not a check-in.
                ['punch_time' => '2026-11-19 11:40:00', 'emp_code' => 'E1', 'punch_state' => '0', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-19 15:35:00', 'emp_code' => 'E1', 'punch_state' => '1', 'verify_type' => 1, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-20 13:10:00', 'emp_code' => 'E1', 'punch_state' => '1', 'verify_type' => 15, 'terminal_alias' => 'Main Gate', 'area_alias' => 'HQ'],
                ['punch_time' => '2026-11-03 07:00:00', 'emp_code' => 'E2', 'punch_state' => '0', 'verify_type' => 1, 'terminal_alias' => 'X', 'area_alias' => 'X'],
            ],
            'FROM att_payloadbase'    => [
                $base('2026-11-02', '07:30:00', '15:30:00', '07:42:10', '15:05:00', 12, 25, 443, ['total_ot' => 30 * $durationFactor]),
                $base('2026-11-04', '07:30:00', '15:30:00', null, null, 0, 0, 0, ['absent' => 480]),
                $base('2026-11-10', null, null, null, null, 0, 0, 0, ['day_off' => 1]),
                // A split shift: two timetables on one date add up.
                $base('2026-11-11', '07:30:00', '11:30:00', '07:35:00', '11:30:00', 5, 0, 235, ['timetable' => 'Morning']),
                $base('2026-11-11', '13:00:00', '17:00:00', '13:10:00', '16:50:00', 10, 10, 220, ['timetable' => 'Afternoon']),
            ],
            // ZKBioTime 9.5's own tables (empty here: this server version keeps att_payloadbase).
            'FROM att_payloadtimecard' => [],
            'FROM att_payloadpaycode'  => [],
            'FROM att_payloadattcode'  => [],
            // Schedules for the dates ZKBioTime has not calculated.
            'FROM att_tempschedule'   => [['d' => '2026-11-23', 's' => '09:00:00', 'e' => '13:00:00', 'alias' => 'Exam duty', 'in_above_margin' => 60]],
            'FROM att_temporaryschedule' => [],
            // A weekly shift, Sunday to Thursday (day_index 0–4), from Sunday 1 November.
            'FROM att_attschedule'    => [['sd' => '2026-11-01', 'ed' => '2026-12-31', 'shift_id' => 7, 'cycle_unit' => 1, 'shift_cycle' => 1]],
            'FROM att_groupschedule'  => [],
            'FROM att_departmentschedule' => [],
            'FROM att_shiftdetail'    => array_map(static fn (int $i): array => ['shift_id' => 7, 'day_index' => $i] + $interval, [0, 1, 2, 3, 4]),
            'FROM att_holiday'        => [
                ['alias' => 'National Day', 'first_day' => '2026-11-15', 'last_day' => '2026-11-16', 'department_id' => null, 'att_group_id' => null],
                ['alias' => 'HR Day', 'first_day' => '2026-11-17', 'last_day' => '2026-11-17', 'department_id' => 11, 'att_group_id' => null],
                ['alias' => 'Group Day', 'first_day' => '2026-11-18', 'last_day' => '2026-11-18', 'department_id' => null, 'att_group_id' => 5],
            ],
        ];
    }

    private function reader(CatalogueQuery $q, string $unit = 'auto'): AttendanceReader
    {
        return new AttendanceReader(static fn () => $q, 'Asia/Riyadh', $unit);
    }

    public function testDaysAreZkbioTimesCalculationInMinutesWhateverTheStoredUnit(): void
    {
        foreach ([60 => 'seconds', 1 => 'minutes'] as $factor => $unit) {
            $r    = $this->reader(new CatalogueQuery('pgsql', $this->answers($factor)));
            $emp  = $r->employee('E1');
            $days = array_column($r->days($emp, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')), null, 'date');

            self::assertSame([12, 25, 443, 30], [$days['2026-11-02']['late'], $days['2026-11-02']['early'], $days['2026-11-02']['worked'], $days['2026-11-02']['overtime']], 'stored in ' . $unit);
            self::assertSame(['Day 07:30-15:30', '07:30:00', '15:30:00', '11:30:00'], [$days['2026-11-02']['schedule'], $days['2026-11-02']['sched_start'], $days['2026-11-02']['sched_end'], $days['2026-11-02']['cutoff']]);
            self::assertArrayNotHasKey('check_in', $days['2026-11-02'], 'check-in is told from the punches, not taken from ZKBioTime');
            self::assertTrue($days['2026-11-04']['absent']);
            self::assertTrue($days['2026-11-10']['off']);
            self::assertSame(['Morning + Afternoon', '07:30:00', '17:00:00', 15, 10], [$days['2026-11-11']['schedule'], $days['2026-11-11']['sched_start'], $days['2026-11-11']['sched_end'], $days['2026-11-11']['late'], $days['2026-11-11']['early']], 'split shift');

            // Not calculated by ZKBioTime: the schedule from the shift and temporary schedules.
            self::assertSame(['Day 07:30-15:30', '07:30:00', '15:30:00', '11:30:00'], [$days['2026-11-19']['schedule'], $days['2026-11-19']['sched_start'], $days['2026-11-19']['sched_end'], $days['2026-11-19']['cutoff']], 'Thursday');
            self::assertArrayNotHasKey('late', $days['2026-11-19'], 'late-in only from ZKBioTime\'s calculation');
            self::assertTrue($days['2026-11-20']['off'], 'Friday: no timetable in the shift');
            self::assertSame(['Exam duty', '09:00:00', '13:00:00', '10:00:00'], [$days['2026-11-23']['schedule'], $days['2026-11-23']['sched_start'], $days['2026-11-23']['sched_end'], $days['2026-11-23']['cutoff']], 'temporary schedule wins');
            self::assertSame(['National Day', 'National Day', 'Group Day'], [$days['2026-11-15']['holiday'], $days['2026-11-16']['holiday'], $days['2026-11-18']['holiday']]);
            self::assertArrayNotHasKey('holiday', $days['2026-11-17'], 'another department\'s holiday');
        }

        // A fixed unit overrides the check.
        $r    = $this->reader(new CatalogueQuery('pgsql', $this->answers(60)), 'minutes');
        $days = array_column($r->days($r->employee('E1'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')), null, 'date');
        self::assertSame(720, $days['2026-11-02']['late']);
    }

    public function testEveryQueryIsForTheOneEmployeeAndOnlyReads(): void
    {
        $q = new CatalogueQuery('pgsql', $this->answers());
        $r = $this->reader($q);
        $r->days($r->employee('E1'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        $r->punches('E1', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));

        foreach ($q->sent as $sql) {
            self::assertMatchesRegularExpression('/^\s*SELECT\b/', $sql);

            if (str_contains($sql, 'FROM iclock_transaction')) {
                self::assertStringContainsString('t.emp_code = :code', $sql);
                self::assertStringContainsString('AT TIME ZONE', $sql, 'times in the server\'s zone');
            }

            if (str_contains($sql, 'FROM att_payloadbase')) {
                self::assertStringContainsString('b.emp_id = :emp', $sql);
            }
        }

        // Punches in iclock_transaction's field names, with the state and verification as ZKBioTime shows them.
        $p = $this->reader(new CatalogueQuery('pgsql', $this->answers()))->punches('E1', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'))[0];
        self::assertSame(['2026-11-02 07:42:10', 'Check In', 'Face', '15'], [$p['punch_time'], $p['punch_state_display'], $p['verify_type_display'], $p['verify_type']]);
    }

    /**
     * ZKBioTime 9.5 as on the real server: att_payloadbase empty; the calculation in the time cards
     * (timetable, scheduled times), pay codes (Late In = L, Early Out = E, Absence, HOLIDAY, WEEKEND,
     * leave, minutes) and attendance codes (Worked Hours).
     */
    public function testZkbioTime95KeepsItsCalculationInTimeCardsAndPayCodes(): void
    {
        $answers = $this->answers();
        $answers['FROM att_payloadbase'] = [];
        $answers['FROM att_payloadtimecard'] = [
            ['d' => '2026-11-02', 'time_table_alias' => 'IN07:30-OUT15:30', 's' => '07:30:00', 'e' => '15:30:00', 'in_above_margin' => 240, 'duration' => 480],
            // A time card whose end is midnight: the timetable's length gives the end.
            ['d' => '2026-11-04', 'time_table_alias' => 'IN07:30-OUT15:30', 's' => '07:30:00', 'e' => '00:00:00', 'in_above_margin' => 240, 'duration' => 480],
            ['d' => '2026-11-05', 'time_table_alias' => 'IN07:30-OUT15:30', 's' => '07:30:00', 'e' => '15:30:00', 'in_above_margin' => 240, 'duration' => 480],
            ['d' => '2026-11-06', 'time_table_alias' => null, 's' => null, 'e' => null, 'in_above_margin' => null, 'duration' => null],
        ];
        $answers['FROM att_payloadpaycode'] = [
            ['d' => '2026-11-02', 'name' => 'Late In', 'symbol' => 'L', 'minutes' => '12.0'],
            ['d' => '2026-11-02', 'name' => 'Early Out', 'symbol' => 'E', 'minutes' => '25.0'],
            ['d' => '2026-11-02', 'name' => 'Regular', 'symbol' => 'P', 'minutes' => '443.0'],
            ['d' => '2026-11-04', 'name' => 'Absence', 'symbol' => 'A', 'minutes' => '480.0'],
            ['d' => '2026-11-05', 'name' => 'Annual Leave', 'symbol' => null, 'minutes' => '480.0'],
            ['d' => '2026-11-06', 'name' => 'WEEKEND', 'symbol' => 'W', 'minutes' => '1440.0'],
            ['d' => '2026-11-08', 'name' => 'HOLIDAY', 'symbol' => 'H', 'minutes' => '0.0'],
            // 1.3.7: a leave type named in another language is leave, not absent.
            ['d' => '2026-11-11', 'name' => 'إجازة سنوية', 'symbol' => 'AL', 'minutes' => '480.0'],
        ];
        $answers['FROM att_payloadattcode'] = [['d' => '2026-11-02', 'minutes' => '443.0']];

        $r    = $this->reader(new CatalogueQuery('pgsql', $answers));
        $days = array_column($r->days($r->employee('E1'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')), null, 'date');

        self::assertSame(['IN07:30-OUT15:30', '07:30:00', '15:30:00', '11:30:00', 12, 25, 443], [
            $days['2026-11-02']['schedule'], $days['2026-11-02']['sched_start'], $days['2026-11-02']['sched_end'], $days['2026-11-02']['cutoff'],
            $days['2026-11-02']['late'], $days['2026-11-02']['early'], $days['2026-11-02']['worked'],
        ]);
        self::assertTrue($days['2026-11-04']['absent']);
        self::assertSame('15:30:00', $days['2026-11-04']['sched_end'], 'not 00:00');
        self::assertSame('Annual Leave', $days['2026-11-05']['leave_name']);
        self::assertTrue($days['2026-11-06']['off']);
        self::assertSame('Holiday', $days['2026-11-08']['holiday'], 'a HOLIDAY pay code');
        self::assertSame('National Day', $days['2026-11-15']['holiday'], 'att_holiday gives the name');

        $period = (new AttendanceService(
            fn () => $this->reader(new CatalogueQuery('pgsql', $answers)),
            Fakes::cache($this->cache),
            new ArrayLogger(),
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC')),
            [5, 6]
        ))->month(Identity::matched(5, '11', 'E1'), 2026, 11);

        self::assertSame([12, 25, '07:42:10', 'IN07:30-OUT15:30'], [$period->day('2026-11-02')->late, $period->day('2026-11-02')->early, $period->day('2026-11-02')->checkIn, $period->day('2026-11-02')->schedule]);
        self::assertSame(['other', 'Annual Leave'], [$period->day('2026-11-05')->status, $period->day('2026-11-05')->statusRaw], 'leave, not absent');
        self::assertSame('absent', $period->day('2026-11-04')->status);
        self::assertSame(['other', 'إجازة سنوية'], [$period->day('2026-11-11')->status, $period->day('2026-11-11')->statusRaw], 'leave in any language');
        self::assertSame('regular', $days['2026-11-02']['leave_name'] ?? 'regular', 'Regular is not leave');
        self::assertSame([12, 25], [$period->summary()->lateMinutes, $period->summary()->earlyMinutes]);
    }

    public function testEveryDeviceTheEmployeeUsesIsListed(): void
    {
        $q = new CatalogueQuery('pgsql', [
            'SELECT DISTINCT terminal_alias'  => [['name' => 'Biometric - Main Gate'], ['name' => 'Side Door']],
            'FROM iclock_terminalemployee'    => [['name' => 'Gym'], ['name' => 'Biometric - Main Gate']],
        ]);

        self::assertSame(['Gym', 'Main Gate', 'Side Door'], $this->reader($q)->devices('E1'), 'punched on or registered on, without "Biometric - ", once each');

        foreach ($q->sent as $sql) {
            self::assertStringContainsString('emp_code = :code', $sql);
        }

        // My Dashboard: devices not used in the month are listed after the used ones, with 0.
        $period = $this->service(new CatalogueQuery('pgsql', $this->answers()))->month(Identity::matched(5, '11', 'E1'), 2026, 11);
        $stats  = \BiotimePortal\Component\Biotime\Administrator\Attendance\PunchStats::withDevices($period->days, ['Gym', 'Main Gate']);
        self::assertSame(['Main Gate', 'Gym'], array_column($stats->devices, 'device'));
        self::assertSame(0, $stats->devices[1]['total']);
    }

    public function testNoOrTwoEmployeesWithTheCodeShowNothing(): void
    {
        self::assertNull($this->reader(new CatalogueQuery('pgsql', ['FROM personnel_employee' => []]))->employee('E1'));
        self::assertNull($this->reader(new CatalogueQuery('pgsql', ['FROM personnel_employee' => [['id' => 1, 'department_id' => null, 'dept_name' => null, 'position_name' => null, 'hire_date' => null], ['id' => 2, 'department_id' => null, 'dept_name' => null, 'position_name' => null, 'hire_date' => null]]]))->employee('E1'));
    }

    private function service(CatalogueQuery $q): AttendanceService
    {
        $reader = $this->reader($q);

        return new AttendanceService(
            static fn () => $reader,
            Fakes::cache($this->cache),
            new ArrayLogger(),
            new \DateTimeZone('Asia/Riyadh'),
            static fn () => new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC')),
            [5, 6]
        );
    }

    public function testMonthPagesUseZkbioTimesCalculation(): void
    {
        $alice  = Identity::matched(5, '11', 'E1');
        $period = $this->service(new CatalogueQuery('pgsql', $this->answers()))->month($alice, 2026, 11);

        $d = $period->day('2026-11-02');
        self::assertSame(['present', 'Day 07:30-15:30', '07:30:00', '07:42:10', '15:05:00', 12, 25], [$d->status, $d->schedule, $d->schedStart, $d->checkIn, $d->checkOut, $d->late, $d->early]);
        self::assertCount(2, $d->punches);
        self::assertSame('absent', $period->day('2026-11-04')->status);
        self::assertSame('rest', $period->day('2026-11-10')->status, 'a day off in the schedule is not absent');
        self::assertSame(['holiday', 'National Day'], [$period->day('2026-11-15')->status, $period->day('2026-11-15')->statusRaw]);
        self::assertSame('absent', $period->day('2026-11-17')->status, 'another department\'s holiday');
        self::assertSame('present', $period->day('2026-11-09')->status, 'not calculated yet: from the punches');
        self::assertNull($period->day('2026-11-09')->late);

        // The cut-off comes from the timetable: 11:30 on the 19th, so 11:40 is not the check-in.
        $d19 = $period->day('2026-11-19');
        self::assertSame([null, '15:35:00', 'Day 07:30-15:30'], [$d19->checkIn, $d19->checkOut, $d19->schedule]);
        self::assertTrue($d19->missingMorning());
        self::assertNull($d19->late, 'not calculated by ZKBioTime yet: no late-in');

        // A day without a timetable: 12:00. Today's 13:10 punch is the check-out.
        $today = $period->day('2026-11-20');
        self::assertSame([null, '13:10:00', 'checked_out'], [$today->checkIn, $today->checkOut, $today->status]);
        self::assertSame([], $period->day('2026-11-03')->punches, 'Bob\'s punch is never shown');

        $s = $period->summary();
        self::assertSame([27, 35, 62, 2, 2, 3], [$s->lateMinutes, $s->earlyMinutes, $s->lateEarlyMinutes(), $s->lateDays, $s->earlyDays, $s->holidayDays]);
        self::assertSame(443 + 235 + 220, $s->workedMinutes);
        self::assertSame(30, $s->overtimeMinutes);
        self::assertSame(['department' => 'Information Technology', 'position' => 'Developer', 'hire_date' => '2024-09-01', 'name' => 'Alice A'], $this->service(new CatalogueQuery('pgsql', $this->answers()))->profile($alice));
    }

    public function testMonthsCachedByAnOlderVersionAreReadAgain(): void
    {
        // A day as 0.6.2 cached it under the old key: no late-in (kept a day for past months).
        $stale = ['rows' => [['date' => '2026-11-02', 'schedule' => 'Old', 'db' => true]], 'truncated' => false, 'at' => 1];
        $this->cache['5|E1|db:daily:2026-11'] = ['e' => PHP_INT_MAX, 'v' => $stale];

        $day = $this->service(new CatalogueQuery('pgsql', $this->answers()))->month(Identity::matched(5, '11', 'E1'), 2026, 11)->day('2026-11-02');

        self::assertSame(['Day 07:30-15:30', 12], [$day->schedule, $day->late], 'read again, not the old cached copy');
    }

    public function testADatabaseErrorShowsAMessageAndNoData(): void
    {
        $q      = new CatalogueQuery('pgsql', ['FROM personnel_employee' => static fn (): array => throw new DbException(DbException::UNREACHABLE, 'down')]);
        $period = $this->service($q)->month(Identity::matched(5, '11', 'E1'), 2026, 11);

        self::assertSame(['punches' => 'network', 'daily' => 'network'], $period->errors);
        self::assertSame([], $period->day('2026-11-02')->punches);
    }

    /**
     * Against a real PostgreSQL loaded with tests/Support/sql/zkbiotime-pgsql.sql, when
     * BIOTIME_TEST_PG = "host:port:database:user:password" (a read-only user). Skipped otherwise.
     */
    public function testRealPostgres(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $r      = new AttendanceReader(static fn () => (new DbConnector())->connect($config), 'Asia/Riyadh');
        $emp    = $r->employee('E1');
        $from   = new \DateTimeImmutable('2026-11-01');
        $to     = new \DateTimeImmutable('2026-11-30');

        self::assertSame(['Information Technology', 'Developer', 5, 'Alice A'], [$emp['department'], $emp['position'], $emp['group_id'], $emp['name']]);
        self::assertSame(['2026-11-02 07:42:10', '2026-11-02 15:05:00', '2026-11-03 07:31:00'], array_column($r->punches('E1', $from, $to), 'punch_time'), 'local time, this month, Alice only');

        $days = array_column($r->days($emp, $from, $to), null, 'date');
        self::assertSame([12, 25, 443, 30, '11:30:00'], [$days['2026-11-02']['late'], $days['2026-11-02']['early'], $days['2026-11-02']['worked'], $days['2026-11-02']['overtime'], $days['2026-11-02']['cutoff']]);
        self::assertSame(['Day 07:30-15:30', '07:30:00', '15:30:00', '11:30:00'], [$days['2026-11-19']['schedule'], $days['2026-11-19']['sched_start'], $days['2026-11-19']['sched_end'], $days['2026-11-19']['cutoff']], 'from the weekly shift');
        self::assertTrue($days['2026-11-20']['off'], 'Friday: no timetable');
        self::assertSame(['07:30:00', '15:30:00'], [$days['2026-11-22']['sched_start'], $days['2026-11-22']['sched_end']], 'Sunday: the shift day\'s end is stored as 00:00');
        self::assertSame(['Exam duty', '09:00:00', '13:00:00', '10:00:00'], [$days['2026-11-23']['schedule'], $days['2026-11-23']['sched_start'], $days['2026-11-23']['sched_end'], $days['2026-11-23']['cutoff']], 'temporary schedule, in local time');
        self::assertSame(['National Day', 'National Day', 'Group Day'], [$days['2026-11-08']['holiday'], $days['2026-11-09']['holiday'], $days['2026-11-15']['holiday']]);
        self::assertArrayNotHasKey('holiday', $days['2026-11-12'], 'HR Day is for another department');
        self::assertSame(['Gym', 'Main Gate'], $r->devices('E1'), 'punched on, or registered on');
        self::assertSame([7, 0, 'IN07:30-OUT15:30', 'Annual Leave'], [$days['2026-11-03']['late'], $days['2026-11-03']['early'], $days['2026-11-03']['schedule'], $days['2026-11-24']['leave_name']], 'ZKBioTime 9.5 time card and pay codes');
    }

    /**
     * Manager Dashboard (1.0.0): preload() reads many employees in a few queries; what days(),
     * punches() and employee() then return is exactly what they return one employee at a time.
     */
    public function testPreloadGivesTheSameAsOneEmployeeAtATime(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config  = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $queries = 0;
        $connect = static function () use ($config, &$queries) {
            $inner = (new DbConnector())->connect($config);

            return new class ($inner, $queries) extends \BiotimePortal\Component\Biotime\Administrator\Database\Query {
                public function __construct(private readonly \BiotimePortal\Component\Biotime\Administrator\Database\Query $inner, private int &$counter)
                {
                    parent::__construct(null, 'pgsql');
                }

                public function rows(string $sql, array $params = []): array
                {
                    $this->counter++;

                    return $this->inner->rows($sql, $params);
                }
            };
        };

        $single = new AttendanceReader($connect, 'Asia/Riyadh');
        $bulk   = new AttendanceReader($connect, 'Asia/Riyadh');
        $from   = new \DateTimeImmutable('2026-11-01');
        $to     = new \DateTimeImmutable('2026-11-30');

        $bulk->preload(['E1', 'E2', 'NOBODY'], new \DateTimeImmutable('2026-11-03'), new \DateTimeImmutable('2026-11-20'));
        $before = $queries;

        foreach (['E1', 'E2'] as $code) {
            self::assertSame($single->employee($code), $bulk->employee($code), $code . ': employee');
            self::assertSame($single->days($single->employee($code), $from, $to), $bulk->days($bulk->employee($code), $from, $to), $code . ': days');
            self::assertSame($single->punches($code, $from, $to), $bulk->punches($code, $from, $to), $code . ': punches');
        }

        self::assertNull($bulk->employee('NOBODY'));

        // The single reader ran its own queries meanwhile; the bulk reader only shared ones
        // (holidays, group/department shifts and shift details), each once.
        $q = $queries;
        $bulk->days($bulk->employee('E1'), $from, $to);
        $bulk->punches('E1', $from, $to);
        self::assertSame($q, $queries, 'read ahead: no query for an employee read again');
        self::assertGreaterThan(0, $before);
    }

    /** 1.0.4: a weekend's time card with only a midnight start is no schedule ("—", not "00:00–—"). */
    public function testAMidnightStartWithoutAnEndIsNoSchedule(): void
    {
        $entry = new \ReflectionMethod(AttendanceReader::class, 'entry');

        self::assertSame(['name' => null, 'start' => null, 'end' => null, 'cutoff' => null], $entry->invoke(null, null, '00:00:00', null, 0, 0));
        self::assertSame('08:00:00', $entry->invoke(null, 'Night', '00:00:00', null, 480, 0)['end'], 'a real night shift from midnight keeps its times');
        self::assertSame('00:00:00', $entry->invoke(null, 'Night', '00:00:00', null, 480, 0)['start']);
    }
}
