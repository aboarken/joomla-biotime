<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads one employee's attendance from ZKBioTime's own PostgreSQL database (0.6.0), read only:
 *  - punches:  iclock_transaction;
 *  - each day: ZKBioTime's calculated attendance, att_payloadbase (scheduled and actual times,
 *              late, early leave, worked time) with the timetable's name (att_timeinterval) and
 *              overtime (att_payloadovertime);
 *  - holidays: att_holiday (for everyone, the employee's department or attendance group);
 *  - the employee: personnel_employee with department and position.
 *
 * Table and column names: from the server's Test Database report (ZKBioTime 9.5, PostgreSQL 15).
 * Times are stored with a time zone; they are returned in the ZKBioTime server's time zone, as
 * the devices and ZKBioTime show them. Every query filters by this one employee.
 *
 * Duration columns (late, early_leave, worked, overtime) are whole numbers whose unit ZKBioTime
 * does not document: "auto" compares each day's scheduled duration with its scheduled start and
 * end to tell seconds from minutes; the administrator can set the unit instead.
 */
final class AttendanceReader
{
    public const UNITS = ['auto', 'minutes', 'seconds'];

    /**
     * ZKBioTime's pay codes of a calculated day (att_payloadpaycode), by their symbol or, failing
     * that, their name (case-insensitive), as on ZKBioTime 9.5: Late In = L, Early Out = E,
     * Absence = A, HOLIDAY = H, WEEKEND = W, Regular = P; leave codes are named "… Leave".
     */
    private const PAY_CODES = [
        'late'    => ['L', 'late in'],
        'early'   => ['E', 'early out'],
        'absent'  => ['A', 'absence'],
        'holiday' => ['H', 'holiday'],
        'weekend' => ['W', 'weekend'],
        'regular' => ['P', 'regular'],
    ];

    /** The attendance code holding the worked time of a calculated day (att_payloadattcode). */
    private const WORKED_CODE = 'worked hours';

    /** ZKTeco punch states and verify types, as ZKBioTime shows them. */
    private const STATES  = ['0' => 'Check In', '1' => 'Check Out', '2' => 'Break Out', '3' => 'Break In', '4' => 'Overtime In', '5' => 'Overtime Out'];
    private const VERIFY  = ['0' => 'Password', '1' => 'Fingerprint', '4' => 'Card', '15' => 'Face'];

    /** @var array<string, ?array<string, mixed>> */
    private array $employees = [];

    private ?Query $query = null;

    /**
     * Rows read ahead for many employees at once (preload(), Manager Dashboard): kind => key
     * (employee id or code) => rows, for the dates in $bulkRange.
     *
     * @var array<string, array<string, list<array<string, mixed>>>>
     */
    private array $bulk = [];

    /** @var array{0: string, 1: string}|null */
    private ?array $bulkRange = null;

    /** @var array<string, list<array<string, mixed>>> results of optional() queries this request */
    private array $memo = [];

    /**
     * @param \Closure(): Query $connect opens the connection when first needed
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $timezone,
        private readonly string $unit = 'auto'
    ) {
        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            throw new \InvalidArgumentException('timezone');
        }
    }

    /**
     * The employee with this code, or null.
     *
     * @return array{id: int, department_id: ?int, group_id: ?int, department: ?string, position: ?string, hire_date: ?string}|null
     *
     * @throws DbException
     */
    public function employee(string $empCode): ?array
    {
        if (\array_key_exists($empCode, $this->employees)) {
            return $this->employees[$empCode];
        }

        $row = $this->q()->rows(
            "SELECT e.id, e.department_id, e.first_name, e.last_name, d.dept_name, p.position_name, to_char(e.hire_date, 'YYYY-MM-DD') AS hire_date
               FROM personnel_employee e
               LEFT JOIN personnel_department d ON d.id = e.department_id
               LEFT JOIN personnel_position p ON p.id = e.position_id
              WHERE e.emp_code = :code
              ORDER BY e.id
              LIMIT 2",
            [':code' => $empCode]
        );

        // Not exactly one employee with this code: nothing is shown (never another's data).
        if (\count($row) !== 1) {
            return $this->employees[$empCode] = null;
        }

        $group = null;

        try {
            $group = $this->q()->value('SELECT group_id FROM att_attemployee WHERE emp_id = :id ORDER BY id LIMIT 1', [':id' => (int) $row[0]['id']]);
        } catch (DbException) {
            // Attendance groups are optional (holidays per group only).
        }

        return $this->employees[$empCode] = [
            'id'            => (int) $row[0]['id'],
            'department_id' => $row[0]['department_id'] !== null ? (int) $row[0]['department_id'] : null,
            'group_id'      => $group !== null ? (int) $group : null,
            'department'    => self::text($row[0]['dept_name']),
            'position'      => self::text($row[0]['position_name']),
            'hire_date'     => self::text($row[0]['hire_date']),
            'name'          => self::text(trim(($row[0]['first_name'] ?? '') . ' ' . ($row[0]['last_name'] ?? ''))),
        ];
    }

    /**
     * Punches from $from 00:00 to $to 23:59:59 (server time), in the field names the portal has
     * always stored (those of ZKBioTime's transactions list), so cached punches keep their form.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function punches(string $empCode, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->fromBulk('punches', $empCode, $from, $to) ?? $this->q()->rows(
            self::sql('punches', '= :code'),
            [':code' => $empCode] + $this->punchRange($from, $to)
        );

        return array_map(static function (array $r): array {
            $state  = trim((string) $r['punch_state']);
            $verify = $r['verify_type'] !== null ? (string) $r['verify_type'] : null;

            return [
                'emp_code'            => (string) $r['emp_code'],
                'punch_time'          => (string) $r['punch_time'],
                'punch_state'         => $state,
                'punch_state_display' => self::STATES[$state] ?? null,
                'verify_type'         => $verify,
                'verify_type_display' => $verify !== null ? (self::VERIFY[$verify] ?? null) : null,
                'terminal_alias'      => $r['terminal_alias'],
                'area_alias'          => $r['area_alias'],
            ];
        }, $rows);
    }

    /**
     * Every device the employee uses: those they have punched on (iclock_transaction, any time) and
     * those they are registered on (iclock_terminalemployee with the device's name from
     * iclock_terminal). Names only, as the devices show them, sorted.
     *
     * @return list<string>
     *
     * @throws DbException
     */
    public function devices(string $empCode): array
    {
        $names = array_column($this->q()->rows(
            "SELECT DISTINCT terminal_alias AS name FROM iclock_transaction
              WHERE emp_code = :code AND COALESCE(terminal_alias, '') <> ''",
            [':code' => $empCode]
        ), 'name');

        foreach ($this->optional(
            "SELECT COALESCE(NULLIF(t.alias, ''), NULLIF(t.terminal_name, ''), te.terminal_sn) AS name
               FROM iclock_terminalemployee te
               LEFT JOIN iclock_terminal t ON t.sn = te.terminal_sn
              WHERE te.emp_code = :code",
            [':code' => $empCode]
        ) as $r) {
            $names[] = $r['name'];
        }

        $names = array_values(array_unique(array_filter(array_map(
            static fn (mixed $n): ?string => \BiotimePortal\Component\Biotime\Administrator\Attendance\Punch::deviceName(self::text($n)),
            $names
        ))));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * One row per date of the range that has something to say for this employee:
     *  - schedule: the timetable names, sched_start and sched_end ("HH:MM:SS") and cutoff, the
     *    time from which a punch is a check-out (the end of the check-in window). From
     *    ZKBioTime's calculation (att_payloadbase) when it has calculated the date, else from the
     *    schedules (see planned());
     *  - off: the employee has a schedule for the date but no timetable on it (a day off);
     *  - late, early, worked, overtime (minutes), absent, leave: ZKBioTime's calculation
     *    (att_payloadbase), only for dates it has calculated;
     *  - holiday: its name.
     * Check-in and check-out are told from the punches by AttendanceService (12:00 rule).
     *
     * @param array{id: int, department_id: ?int, group_id: ?int} $employee from employee()
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function days(array $employee, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->fromBulk('base', $employee['id'], $from, $to) ?? $this->q()->rows(
            self::sql('base', '= :emp'),
            [':emp' => $employee['id']] + $this->tzRange($from, $to)
        );

        $divisor = $this->divisor($rows);
        $minutes = static fn (mixed $v): ?int => $v === null ? null : max(0, (int) round(((int) $v) / $divisor));
        $days    = [];

        foreach ($rows as $r) {
            $date = (string) $r['att_date'];
            $d    = &$days[$date];
            $d  ??= ['date' => $date, 'entries' => [], 'late' => 0, 'early' => 0, 'worked' => 0, 'overtime' => 0, 'absent' => false, 'leave' => false, 'off' => true];

            if ($r['sched_in'] !== null || self::text($r['timetable']) !== null) {
                $d['entries'][] = self::entry($r['timetable'], $r['sched_in'], $r['sched_out'], $r['tt_duration'], $r['in_above_margin']);
            }

            $d['late']     += $minutes($r['late']) ?? 0;
            $d['early']    += $minutes($r['early_leave']) ?? 0;
            $d['worked']   += $minutes($r['total_worked']) ?? 0;
            $d['overtime'] += $minutes($r['total_ot']) ?? 0;
            $d['absent']    = $d['absent'] || (int) $r['absent'] > 0;
            $d['leave']     = $d['leave'] || (int) $r['leave'] > 0;
            // A day off only when every timetable row of the date is one.
            $d['off']       = $d['off'] && (int) $r['day_off'] > 0;
            unset($d);
        }

        // ZKBioTime 9.5 keeps its calculation in the time card and pay code tables instead of
        // att_payloadbase (empty there): timetable and scheduled times per day, and the minutes of
        // Late In, Early Out, Absence, leave, holiday and weekend codes.
        foreach ($this->calculated($employee, $from, $to) as $date => $c) {
            if (!isset($days[$date])) {
                $days[$date] = $c;
            }
        }

        // Dates ZKBioTime has not calculated (usually today and later): the schedules themselves.
        $planned = $this->planned($employee, $from, $to);

        foreach ($planned['days'] as $date => $entries) {
            if (!isset($days[$date])) {
                $days[$date] = ['date' => $date, 'entries' => $entries, 'off' => $entries === []];
            }
        }

        foreach ($this->holidays($employee, $from, $to) as $date => $name) {
            $days[$date] ??= ['date' => $date, 'entries' => [], 'off' => false];
            $days[$date]['holiday'] = $name;
        }

        ksort($days);

        return array_values(array_map(static function (array $d): array {
            $entries = array_values(array_filter($d['entries'], static fn (array $e): bool => $e['start'] !== null || $e['name'] !== null));
            $names   = array_values(array_unique(array_filter(array_column($entries, 'name'))));
            $starts  = array_filter($entries, static fn (array $e): bool => $e['start'] !== null);
            $ends    = array_filter($entries, static fn (array $e): bool => $e['end'] !== null);

            usort($starts, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            usort($ends, static fn (array $a, array $b): int => $b['end'] <=> $a['end']);

            $d['schedule']    = $names !== [] ? implode(' + ', $names) : null;
            $d['sched_start'] = $starts[0]['start'] ?? null;
            $d['sched_end']   = $ends[0]['end'] ?? null;
            // The check-in / check-out cut-off of the day's first timetable (see entry()).
            $d['cutoff']      = $starts[0]['cutoff'] ?? null;
            unset($d['entries']);

            return array_filter($d, static fn ($v) => $v !== null);
        }, $days));
    }

    /**
     * ZKBioTime's calculation from its time cards (att_payloadtimecard: timetable and scheduled
     * start and end), pay codes (att_payloadpaycode: minutes per code) and attendance codes
     * (att_payloadattcode: worked hours), per date. Missing or unreadable tables give nothing.
     *
     * @return array<string, array<string, mixed>> date => day as in days()
     */
    private function calculated(array $employee, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $range = [':emp' => $employee['id'], ':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')];
        $days  = [];
        $day   = static function (array &$days, string $date): void {
            $days[$date] ??= ['date' => $date, 'entries' => [], 'off' => false];
        };

        foreach ($this->fromBulk('timecard', $employee['id'], $from, $to) ?? $this->optional(self::sql('timecard', '= :emp'), $this->tzRange($from, $to) + [':emp' => $employee['id']]) as $r) {
            $date = (string) $r['d'];
            $day($days, $date);

            if ($r['s'] !== null || self::text($r['time_table_alias']) !== null) {
                $days[$date]['entries'][] = self::entry($r['time_table_alias'], $r['s'], $r['e'], $r['duration'], $r['in_above_margin']);
            }
        }

        $matches = static function (array $code, mixed $symbol, mixed $name): bool {
            return strcasecmp(trim((string) $symbol), $code[0]) === 0 || strcasecmp(trim((string) $name), $code[1]) === 0;
        };

        foreach ($this->fromBulk('paycode', $employee['id'], $from, $to) ?? $this->optional(self::sql('paycode', '= :emp'), $range) as $r) {
            $date    = (string) $r['d'];
            $minutes = max(0, (int) round((float) $r['minutes']));
            $day($days, $date);
            $d = &$days[$date];

            match (true) {
                $matches(self::PAY_CODES['late'], $r['symbol'], $r['name'])    => $d['late'] = ($d['late'] ?? 0) + $minutes,
                $matches(self::PAY_CODES['early'], $r['symbol'], $r['name'])   => $d['early'] = ($d['early'] ?? 0) + $minutes,
                $matches(self::PAY_CODES['absent'], $r['symbol'], $r['name'])  => $d['absent'] = true,
                $matches(self::PAY_CODES['holiday'], $r['symbol'], $r['name']) => $d['holiday'] ??= 'Holiday',
                $matches(self::PAY_CODES['weekend'], $r['symbol'], $r['name']) => $d['off'] = true,
                $matches(self::PAY_CODES['regular'], $r['symbol'], $r['name']) => null,
                // Any other pay code with time is a leave type defined in ZKBioTime, whatever its
                // name or language (1.3.7 audit: only names containing the English word "leave" were
                // recognised, so e.g. "إجازة سنوية" showed as Absent). Used only on days without punches.
                $minutes > 0 && self::text($r['name']) !== null                  => $d['leave_name'] ??= self::text($r['name']),
                default                                                        => null,
            };

            // Late-in and early-out exist (as 0) on every calculated working day.
            $d['late']  ??= 0;
            $d['early'] ??= 0;
            unset($d);
        }

        foreach ($this->fromBulk('attcode', $employee['id'], $from, $to) ?? $this->optional(self::sql('attcode', '= :emp'), $range) as $r) {
            $date = (string) $r['d'];
            $day($days, $date);
            $days[$date]['worked'] = max(0, (int) round((float) $r['minutes']));
        }

        return $days;
    }

    /**
     * The employee's planned timetables per date, from the schedules ZKBioTime keeps, in this order
     * (the first that has the date wins):
     *  1. a temporary schedule with its own times for the date (att_tempschedule);
     *  2. a temporary timetable for the date (att_temporaryschedule);
     *  3. the employee's shift (att_attschedule), else the attendance group's (att_groupschedule),
     *     else the department's (att_departmentschedule): the shift's timetable for the date's
     *     day in the shift cycle (att_attshift, att_shiftdetail).
     * A date covered by a shift without a timetable on it is a day off (an empty list).
     *
     * Shift cycles (UNVERIFIED, from ZKBioTime's shift settings: cycle = number × unit):
     * cycle_unit 0 = days (day_index counts from the schedule's start date), 1 = weeks (day_index
     * 0 = Sunday of the week the schedule starts in), 2 = months (day_index 0 = the 1st).
     *
     * @return array{days: array<string, list<array{name: ?string, start: ?string, end: ?string, cutoff: ?string}>>}
     */
    public function planned(array $employee, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $range = [':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')];
        $days  = [];

        // 1. Temporary schedules with their own start and end.
        foreach ($this->fromBulk('tempschedule', $employee['id'], $from, $to) ?? $this->optional(self::sql('tempschedule', '= :emp'), $this->tzRange($from, $to) + [':emp' => $employee['id']]) as $r) {
            $days[(string) $r['d']][] = self::entry($r['alias'], $r['s'], $r['e'], null, $r['in_above_margin']);
        }

        // 2. Temporary timetables.
        $temporary = [];

        foreach ($this->fromBulk('temporary', $employee['id'], $from, $to) ?? $this->optional(self::sql('temporary', '= :emp'), [':emp' => $employee['id']] + $range) as $r) {
            $temporary[(string) $r['d']][] = self::entry($r['alias'], $r['s'], null, $r['duration'], $r['in_above_margin']);
        }

        // 3. Shifts of the employee, the group and the department.
        $levels = [
            ['att_attschedule', 'employee_id', $employee['id']],
            ['att_groupschedule', 'group_id', $employee['group_id'] ?? null],
            ['att_departmentschedule', 'department_id', $employee['department_id'] ?? null],
        ];
        $assignments = [];
        $shiftIds    = [];

        foreach ($levels as $i => [$table, $column, $id]) {
            if ($id === null) {
                continue;
            }

            // Table and column names are constants above, never input. The employee's own shifts
            // come from preload() when read ahead; group and department shifts are shared, and
            // optional() reads each of them once per request.
            $own = $i === 0 ? $this->fromBulk('attschedule', (int) $id, $from, $to) : null;

            foreach ($own ?? $this->optional(
                "SELECT s.{$column} AS k, to_char(s.start_date, 'YYYY-MM-DD') AS sd, to_char(s.end_date, 'YYYY-MM-DD') AS ed, s.shift_id, sh.cycle_unit, sh.shift_cycle
                   FROM {$table} s
                   JOIN att_attshift sh ON sh.id = s.shift_id
                  WHERE s.{$column} = :id AND s.start_date <= CAST(:to AS date) AND s.end_date >= CAST(:from AS date)
                  ORDER BY s.start_date",
                [':id' => (int) $id] + $range
            ) as $r) {
                $assignments[$i][] = $r;
                $shiftIds[(int) $r['shift_id']] = true;
            }
        }

        $details = [];

        if ($shiftIds !== []) {
            $params = [];

            foreach (array_keys($shiftIds) as $n => $shiftId) {
                $params[':s' . $n] = $shiftId;
            }

            foreach ($this->optional(
                "SELECT d.shift_id, d.day_index, to_char(d.in_time, 'HH24:MI:SS') AS d_in, to_char(d.out_time, 'HH24:MI:SS') AS d_out,
                        t.alias, to_char(t.in_time, 'HH24:MI:SS') AS t_in, t.duration, t.in_above_margin
                   FROM att_shiftdetail d
                   JOIN att_timeinterval t ON t.id = d.time_interval_id
                  WHERE d.shift_id IN (" . implode(', ', array_keys($params)) . ')
                  ORDER BY d.shift_id, d.day_index, t.in_time',
                $params
            ) as $r) {
                $details[(int) $r['shift_id']][(int) $r['day_index']][] = self::entry(
                    $r['alias'],
                    // A shift day's own start, unless unset (null or 00:00): then the timetable's.
                    $r['d_in'] !== null && $r['d_in'] !== '00:00:00' ? $r['d_in'] : $r['t_in'],
                    $r['d_out'],
                    $r['duration'],
                    $r['in_above_margin']
                );
            }
        }

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');

            if (isset($days[$date])) {
                continue;
            }

            if (isset($temporary[$date])) {
                $days[$date] = $temporary[$date];

                continue;
            }

            foreach ([0, 1, 2] as $level) {
                $current = null;

                foreach ($assignments[$level] ?? [] as $a) {
                    if ($a['sd'] <= $date && $date <= $a['ed']) {
                        $current = $a;
                    }
                }

                if ($current === null) {
                    continue;
                }

                $index        = self::dayIndex((int) $current['cycle_unit'], (int) $current['shift_cycle'], new \DateTimeImmutable((string) $current['sd']), $day);
                $days[$date]  = $details[(int) $current['shift_id']][$index] ?? [];

                break;
            }
        }

        ksort($days);

        return ['days' => $days];
    }

    /**
     * The date's position in a shift cycle (see planned()).
     */
    public static function dayIndex(int $unit, int $cycle, \DateTimeImmutable $start, \DateTimeImmutable $date): int
    {
        $cycle = max(1, $cycle);
        $diff  = (int) $start->diff($date)->format('%r%a');

        return match ($unit) {
            1       => (($diff + (int) $start->format('w')) % (7 * $cycle) + 7 * $cycle) % (7 * $cycle),
            2       => ((((int) $date->format('Y') * 12 + (int) $date->format('n')) - ((int) $start->format('Y') * 12 + (int) $start->format('n'))) % $cycle) * 31 + (int) $date->format('j') - 1,
            default => (($diff % $cycle) + $cycle) % $cycle,
        };
    }

    /**
     * One timetable: start, end (the given end, else start + duration minutes) and the cut-off
     * between check-in and check-out: the end of the timetable's check-in window, i.e. the start
     * + in_above_margin minutes (ZKBioTime's "End Check-In"); null without a window.
     *
     * @return array{name: ?string, start: ?string, end: ?string, cutoff: ?string}
     */
    private static function entry(mixed $name, mixed $start, mixed $end, mixed $duration, mixed $inAboveMargin): array
    {
        $start = \is_string($start) && $start !== '' ? $start : null;
        $end   = \is_string($end) && $end !== '' ? $end : null;

        // The timetable's length in minutes (a value over a day is taken as seconds).
        $minutes = (int) $duration > 1440 ? intdiv((int) $duration, 60) : (int) $duration;

        // ZKBioTime stores 00:00 where a shift day's end is not set: an end that is not after the
        // start is not used. The end is then the start plus the timetable's length (which also
        // gives the right end of a shift that runs past midnight).
        if ($end !== null && $start !== null && $end <= $start) {
            $end = null;
        }

        if ($end === null && $start !== null && $minutes > 0) {
            $end = (new \DateTimeImmutable('2000-01-01 ' . $start))->modify('+' . $minutes . ' minutes')->format('H:i:s');
        }

        // A midnight start without an end or a length (ZKBioTime's time cards of weekends and days
        // off) is no schedule (1.0.4).
        if ($start === '00:00:00' && $end === null) {
            $start = null;
        }

        $cutoff = null;

        if ($start !== null && (int) $inAboveMargin > 0) {
            $at     = (new \DateTimeImmutable('2000-01-01 ' . $start))->modify('+' . (int) $inAboveMargin . ' minutes');
            // A window running past midnight has no cut-off on the same day.
            $cutoff = $at->format('Y-m-d') === '2000-01-01' ? $at->format('H:i:s') : null;
        }

        return [
            'name'   => self::text($name),
            'start'  => $start,
            'end'    => $end,
            'cutoff' => $cutoff,
        ];
    }

    /**
     * Rows of a query on a table that may be missing or not readable: none then.
     *
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function optional(string $sql, array $params): array
    {
        try {
            return $this->memoRows($sql, $params);
        } catch (DbException $e) {
            if ($e->getKind() !== DbException::QUERY) {
                throw $e;
            }

            return [];
        }
    }

    /**
     * Holidays of this employee in the range, date => name: those for everyone (no department
     * and no group), for the employee's department or for the employee's attendance group.
     *
     * @return array<string, string>
     */
    private function holidays(array $employee, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        try {
            $rows = $this->memoRows(
                "SELECT alias, to_char(start_date, 'YYYY-MM-DD') AS first_day,
                        to_char(COALESCE(end_date, start_date + (GREATEST(duration_day, 1) - 1)), 'YYYY-MM-DD') AS last_day,
                        department_id, att_group_id
                   FROM att_holiday
                  WHERE start_date <= CAST(:to AS date)
                    AND COALESCE(end_date, start_date + (GREATEST(duration_day, 1) - 1)) >= CAST(:from AS date)",
                [':to' => $to->format('Y-m-d'), ':from' => $from->format('Y-m-d')]
            );
        } catch (DbException) {
            return [];
        }

        $out = [];

        foreach ($rows as $r) {
            $dept  = $r['department_id'] !== null ? (int) $r['department_id'] : null;
            $group = $r['att_group_id'] !== null ? (int) $r['att_group_id'] : null;

            $applies = ($dept === null && $group === null)
                || ($dept !== null && $dept === $employee['department_id'])
                || ($group !== null && $group === $employee['group_id']);

            if (!$applies) {
                continue;
            }

            $day  = new \DateTimeImmutable(max((string) $r['first_day'], $from->format('Y-m-d')));
            $last = min((string) $r['last_day'], $to->format('Y-m-d'));

            for (; $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
                $out[$day->format('Y-m-d')] ??= self::text($r['alias']) ?? 'Holiday';
            }
        }

        return $out;
    }

    /**
     * 60 when duration columns are in seconds, 1 when in minutes. "auto": a day's scheduled
     * duration is close to its scheduled start-to-end span in seconds when the unit is seconds,
     * and about 60 times smaller when it is minutes; most days decide.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function divisor(array $rows): int
    {
        if ($this->unit !== 'auto') {
            return $this->unit === 'seconds' ? 60 : 1;
        }

        $votes = ['seconds' => 0, 'minutes' => 0];

        foreach ($rows as $r) {
            $span = (int) ($r['sched_seconds'] ?? 0);
            $dur  = (int) ($r['duration'] ?? 0);

            if ($span <= 0 || $dur <= 0) {
                continue;
            }

            $votes[$dur / $span > 0.2 ? 'seconds' : 'minutes']++;
        }

        // Without a scheduled day to compare: minutes, the unit ZKBioTime's timetables use.
        return $votes['seconds'] > $votes['minutes'] ? 60 : 1;
    }

    /**
     * The same query with the same values is read once per request (shared department and group
     * shifts, shift details and holidays when many employees are read).
     *
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    private function memoRows(string $sql, array $params): array
    {
        $key = $sql . "\0" . json_encode($params);

        return $this->memo[$key] ??= $this->q()->rows($sql, $params);
    }

    /**
     * Manager Dashboard: reads ahead, in a few queries, what days() and punches() need for every
     * one of these employees from the first day of $from's month to the last day of $to's month,
     * instead of about fifteen queries per employee and month. Later calls for these employees
     * and dates are answered from memory with exactly the rows the single queries would return.
     *
     * @param list<string> $codes employee codes
     *
     * @throws DbException
     */
    public function preload(array $codes, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static fn (string $c): bool => $c !== '')));
        $from  = $from->modify('first day of this month');
        $to    = $to->modify('last day of this month');

        if ($codes === []) {
            return;
        }

        // Already read ahead (e.g. the Manager Dashboard's whole team, then one of them again):
        // keep it. Reading one employee here would replace the team's rows (1.0.9).
        if ($this->bulkRange !== null && $from->format('Y-m-d') >= $this->bulkRange[0] && $to->format('Y-m-d') <= $this->bulkRange[1]
            && array_diff($codes, array_keys($this->bulk['punches'] ?? [])) === []) {
            return;
        }

        // Employees (as employee()): exactly one per code, else none.
        $bind = [];

        foreach ($codes as $n => $code) {
            $bind[':c' . $n] = $code;
        }

        $in   = implode(', ', array_keys($bind));
        $rows = $this->q()->rows(
            "SELECT e.id, e.emp_code, e.department_id, e.first_name, e.last_name, d.dept_name, p.position_name, to_char(e.hire_date, 'YYYY-MM-DD') AS hire_date
               FROM personnel_employee e
               LEFT JOIN personnel_department d ON d.id = e.department_id
               LEFT JOIN personnel_position p ON p.id = e.position_id
              WHERE e.emp_code IN ({$in})
              ORDER BY e.id",
            $bind
        );
        $byCode = [];

        foreach ($rows as $r) {
            $byCode[(string) $r['emp_code']][] = $r;
        }

        $ids    = [];
        $groups = [];

        foreach ($byCode as $list) {
            if (\count($list) === 1) {
                $ids[] = (int) $list[0]['id'];
            }
        }

        if ($ids !== []) {
            foreach ($this->optional('SELECT emp_id, group_id FROM att_attemployee WHERE emp_id IN (' . implode(',', $ids) . ') ORDER BY id', []) as $g) {
                $groups[(int) $g['emp_id']] ??= $g['group_id'] !== null ? (int) $g['group_id'] : null;
            }
        }

        foreach ($codes as $code) {
            $list = $byCode[$code] ?? [];
            $r    = $list[0] ?? null;

            $this->employees[$code] = \count($list) !== 1 ? null : [
                'id'            => (int) $r['id'],
                'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
                'group_id'      => $groups[(int) $r['id']] ?? null,
                'department'    => self::text($r['dept_name']),
                'position'      => self::text($r['position_name']),
                'hire_date'     => self::text($r['hire_date']),
                'name'          => self::text(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))),
            ];
        }

        // Per-employee tables, one query each (ids are integers, written into the statement).
        $this->bulk = ['punches' => array_fill_keys($codes, [])];

        foreach ($this->q()->rows(self::sql('punches', "IN ({$in})"), $bind + $this->punchRange($from, $to)) as $r) {
            $this->bulk['punches'][(string) $r['emp_code']][] = $r;
        }

        $who   = $ids === [] ? 'IN (NULL)' : 'IN (' . implode(',', $ids) . ')';
        $range = [':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')];
        $reads = [
            'base'         => [fn (): array => $this->q()->rows(self::sql('base', $who), $this->tzRange($from, $to))],
            'timecard'     => [fn (): array => $this->optional(self::sql('timecard', $who), $this->tzRange($from, $to))],
            'paycode'      => [fn (): array => $this->optional(self::sql('paycode', $who), $range)],
            'attcode'      => [fn (): array => $this->optional(self::sql('attcode', $who), $range)],
            'tempschedule' => [fn (): array => $this->optional(self::sql('tempschedule', $who), $this->tzRange($from, $to))],
            'temporary'    => [fn (): array => $this->optional(self::sql('temporary', $who), $range)],
            'attschedule'  => [fn (): array => $this->optional(
                "SELECT s.employee_id AS k, to_char(s.start_date, 'YYYY-MM-DD') AS sd, to_char(s.end_date, 'YYYY-MM-DD') AS ed, s.shift_id, sh.cycle_unit, sh.shift_cycle
                   FROM att_attschedule s
                   JOIN att_attshift sh ON sh.id = s.shift_id
                  WHERE s.employee_id {$who} AND s.start_date <= CAST(:to AS date) AND s.end_date >= CAST(:from AS date)
                  ORDER BY s.start_date",
                $range
            )],
        ];

        foreach ($reads as $kind => [$read]) {
            $this->bulk[$kind] = array_fill_keys(array_map('strval', $ids), []);

            foreach ($read() as $r) {
                $this->bulk[$kind][(string) $r['k']][] = $r;
            }
        }

        $this->bulkRange = [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * Rows read ahead by preload() for this employee and these dates; null when they were not
     * read ahead (then the single query runs).
     *
     * @return list<array<string, mixed>>|null
     */
    private function fromBulk(string $kind, int|string $key, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        $first = $from->format('Y-m-d');
        $last  = $to->format('Y-m-d');

        if ($this->bulkRange === null || $first < $this->bulkRange[0] || $last > $this->bulkRange[1] || !isset($this->bulk[$kind]) || !\array_key_exists((string) $key, $this->bulk[$kind])) {
            return null;
        }

        return array_values(array_filter($this->bulk[$kind][(string) $key], static function (array $r) use ($kind, $first, $last): bool {
            return match ($kind) {
                'punches'     => substr((string) $r['punch_time'], 0, 10) >= $first && substr((string) $r['punch_time'], 0, 10) <= $last,
                'base'        => $r['att_date'] >= $first && $r['att_date'] <= $last,
                'attschedule' => $r['sd'] <= $last && $r['ed'] >= $first,
                default       => $r['d'] >= $first && $r['d'] <= $last,
            };
        }));
    }

    /** :tz1, :tz2 and the date range. @return array<string, string> */
    private function tzRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [':tz1' => $this->timezone, ':tz2' => $this->timezone, ':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')];
    }

    /** The punch window, from $from 00:00 to the day after $to, in the server's time zone. @return array<string, string> */
    private function punchRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            ':tz1'  => $this->timezone,
            ':from' => $from->format('Y-m-d') . ' 00:00:00',
            ':tz2'  => $this->timezone,
            ':to'   => $to->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
            ':tz3'  => $this->timezone,
        ];
    }

    /**
     * The per-employee queries, for one employee ($who = "= :emp" or "= :code") or many
     * ($who = "IN (...)"); each also returns its employee as k (or emp_code for punches).
     */
    private static function sql(string $kind, string $who): string
    {
        return match ($kind) {
            'punches' => "SELECT to_char(t.punch_time AT TIME ZONE :tz1, 'YYYY-MM-DD HH24:MI:SS') AS punch_time,
                    t.emp_code, t.punch_state, t.verify_type, t.terminal_alias, t.area_alias
               FROM iclock_transaction t
              WHERE t.emp_code {$who}
                AND t.punch_time >= (CAST(:from AS timestamp) AT TIME ZONE :tz2)
                AND t.punch_time < (CAST(:to AS timestamp) AT TIME ZONE :tz3)
              ORDER BY t.punch_time",
            'base' => "SELECT b.emp_id AS k, to_char(b.att_date, 'YYYY-MM-DD') AS att_date,
                    to_char(b.check_in AT TIME ZONE :tz1, 'HH24:MI:SS') AS sched_in,
                    to_char(b.check_out AT TIME ZONE :tz2, 'HH24:MI:SS') AS sched_out,
                    CAST(EXTRACT(EPOCH FROM (b.check_out - b.check_in)) AS integer) AS sched_seconds,
                    b.duration, b.late, b.early_leave, b.total_worked, b.absent, b.leave, b.day_off,
                    t.alias AS timetable, t.in_above_margin, t.duration AS tt_duration, o.total_ot
               FROM att_payloadbase b
               LEFT JOIN att_timeinterval t ON t.id = b.timetable_id
               LEFT JOIN att_payloadovertime o ON o.uuid = b.overtime_id
              WHERE b.emp_id {$who}
                AND b.att_date >= CAST(:from AS date) AND b.att_date <= CAST(:to AS date)
              ORDER BY b.att_date, b.check_in",
            'timecard' => "SELECT c.emp_id AS k, to_char(c.att_date, 'YYYY-MM-DD') AS d, c.time_table_alias,
                    to_char(c.check_in AT TIME ZONE :tz1, 'HH24:MI:SS') AS s,
                    to_char(c.check_out AT TIME ZONE :tz2, 'HH24:MI:SS') AS e,
                    t.in_above_margin, t.duration
               FROM att_payloadtimecard c
               LEFT JOIN att_timeinterval t ON t.id = c.time_table_id
              WHERE c.emp_id {$who} AND c.att_date >= CAST(:from AS date) AND c.att_date <= CAST(:to AS date)
              ORDER BY c.att_date, c.check_in",
            'paycode' => "SELECT emp_id AS k, to_char(att_date, 'YYYY-MM-DD') AS d, pay_code_alias AS name, pay_code_symbol AS symbol, minutes
               FROM att_payloadpaycode
              WHERE emp_id {$who} AND att_date >= CAST(:from AS date) AND att_date <= CAST(:to AS date)",
            'attcode' => "SELECT emp_id AS k, to_char(att_date, 'YYYY-MM-DD') AS d, SUM(minutes) AS minutes
               FROM att_payloadattcode
              WHERE emp_id {$who} AND att_date >= CAST(:from AS date) AND att_date <= CAST(:to AS date)
                AND LOWER(att_code_alias) = '" . self::WORKED_CODE . "'
              GROUP BY emp_id, att_date",
            'tempschedule' => "SELECT x.employee_id AS k, to_char(x.att_date, 'YYYY-MM-DD') AS d,
                    to_char(x.start_time AT TIME ZONE :tz1, 'HH24:MI:SS') AS s,
                    to_char(x.end_time AT TIME ZONE :tz2, 'HH24:MI:SS') AS e,
                    t.alias, t.in_above_margin
               FROM att_tempschedule x
               LEFT JOIN att_timeinterval t ON t.id = x.time_interval_id
              WHERE x.employee_id {$who} AND x.att_date >= CAST(:from AS date) AND x.att_date <= CAST(:to AS date)
              ORDER BY x.att_date, x.start_time",
            'temporary' => "SELECT x.employee_id AS k, to_char(x.att_date, 'YYYY-MM-DD') AS d, t.alias, to_char(t.in_time, 'HH24:MI:SS') AS s, t.duration, t.in_above_margin
               FROM att_temporaryschedule x
               JOIN att_timeinterval t ON t.id = x.time_interval_id
              WHERE x.employee_id {$who} AND x.att_date >= CAST(:from AS date) AND x.att_date <= CAST(:to AS date)
              ORDER BY x.att_date, t.in_time",
        };
    }

    private function q(): Query
    {
        return $this->query ??= ($this->connect)();
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, 100);
    }
}
