<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Find Tables (1.3.0): the ZKBioTime 9.5 tables the portal knows, what they hold and which portal
 * page reads them. Tables without a page are the ones a future feature (Leave Request) will need.
 * Find Tables lists every other table the database user can see as well.
 */
final class TableCatalog
{
    /** name => [section, what it holds, portal pages that read it] */
    private const TABLES = [
        'iclock_transaction'             => ['Punches', 'Every punch (clock-in and clock-out) from the devices', 'My Punches, My Attendance, Calendar, My Dashboard, Manager Dashboard'],
        'iclock_terminal'                => ['Punches', 'Devices (terminals): name, area, address', 'My Punches, Punches per Device'],
        'iclock_terminalemployee'        => ['Punches', 'Employees registered on each device', 'Punches per Device'],
        'personnel_employee'             => ['People', 'Employees: code, name, department, position', 'Every page (employee matching)'],
        'personnel_department'           => ['People', 'Departments and their parents', 'Manager Dashboard, profile'],
        'personnel_position'             => ['People', 'Positions', 'Profile'],
        'personnel_area'                 => ['People', 'Areas of the devices', ''],
        'personnel_resign'               => ['People', 'Resignations (employees who left)', 'Employee matching'],
        'auth_user'                      => ['People', 'ZKBioTime users (administrators, managers)', 'Manager Dashboard'],
        'auth_user_auth_dept'            => ['People', 'Departments each ZKBioTime user may manage', 'Manager Dashboard'],
        'att_attemployee'                => ['Schedules', 'Attendance settings and group of each employee', 'Schedules'],
        'att_attschedule'                => ['Schedules', 'Shifts assigned to employees (from – to)', 'Schedules'],
        'att_tempschedule'               => ['Schedules', 'Temporary schedules', 'Schedules'],
        'att_temporaryschedule'          => ['Schedules', 'Temporary schedules (other builds)', 'Schedules'],
        'att_attshift'                   => ['Schedules', 'Shift definitions: name, cycle', 'Schedules'],
        'att_shiftdetail'                => ['Schedules', 'Timetable on each day of a shift', 'Schedules'],
        'att_timeinterval'               => ['Schedules', 'Timetables: working hours, check-in and check-out windows', 'Schedules'],
        'att_holiday'                    => ['Schedules', 'Holidays', 'Calendar, My Attendance'],
        'att_payloadtimecard'            => ['Calculation', 'Time cards: ZKBioTime\'s daily calculation (late in, early out, worked)', 'My Attendance, My Dashboard, Manager Dashboard'],
        'att_payloadbase'                => ['Calculation', 'Daily calculation (other builds)', 'My Attendance'],
        'att_payloadpaycode'             => ['Calculation', 'Pay codes of each calculated day (absent, leave)', 'My Attendance'],
        'att_payloadovertime'            => ['Calculation', 'Overtime of each calculated day', 'My Attendance'],
        'att_payloadattcode'             => ['Calculation', 'Attendance codes of each calculated day', 'My Attendance'],
        'att_paycode'                    => ['Leave', 'Pay codes: leave types, absence, weekend', 'My Leaves, My Attendance'],
        'att_leave'                      => ['Leave', 'Leave requests: dates, days, reason, attachment', 'My Leaves, My Approvals'],
        'workflow_workflowinstance'      => ['Leave', 'Requests in an approval workflow: employee, status', 'My Leaves, My Approvals'],
        'workflow_nodeinstance'          => ['Leave', 'Approval stages of each request: who, when, status', 'My Leaves, My Approvals'],
        'workflow_workflownode_approver' => ['Leave', 'Approver roles of each workflow stage', 'My Approvals'],
        'personnel_employee_flow_role'   => ['Leave', 'Approver roles held by employees', 'My Approvals'],
        'workflow_workflowengine'        => ['Leave', 'Approval workflows (which requests use which stages)', ''],
        'workflow_workflownode'          => ['Leave', 'Stages of each approval workflow', ''],
        'workflow_workflowrole'          => ['Leave', 'Approver roles', ''],
    ];

    /** Tables no page reads yet, which the planned Leave Request (writing to ZKBioTime) will need. */
    private const PLANNED = ['workflow_workflowengine', 'workflow_workflownode', 'workflow_workflowrole'];

    /**
     * @return list<array{name: string, section: string, summary: string, portal: string, planned: bool}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (array_keys(self::TABLES) as $name) {
            $out[] = self::find($name);
        }

        return $out;
    }

    /** @return array{name: string, section: string, summary: string, portal: string, planned: bool}|null */
    public static function find(string $name): ?array
    {
        $entry = self::TABLES[$name] ?? null;

        return $entry === null ? null : ['name' => $name, 'section' => $entry[0], 'summary' => $entry[1], 'portal' => $entry[2], 'planned' => \in_array($name, self::PLANNED, true)];
    }

    /** A table name as typed: lower case letters, digits and underscores, or null. */
    public static function normalize(string $typed): ?string
    {
        $name = strtolower(trim($typed));

        return preg_match('/^[a-z_][a-z0-9_]{0,127}$/', $name) ? $name : null;
    }
}
