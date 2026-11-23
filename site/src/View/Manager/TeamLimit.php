<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * How many employees the Manager Dashboard reads at once for a period (1.3.6). The period may be
 * up to a year (ManagerModel::MAX_DAYS), and every employee in the list is read for every day:
 * employees × days stays within CELLS, the largest load measured (300 employees × 31 days: the
 * page, and a PDF in about 10 seconds and 160 MB). Up to 31 days that is 300 employees, as
 * before; a whole year, 25. With more, the manager chooses a department or an employee first.
 */
final class TeamLimit
{
    public const EMPLOYEES = 300;

    public const CELLS = self::EMPLOYEES * 31;

    /** Employees read at once from $from to $to (YYYY-MM-DD, both included). */
    public static function employees(string $from, string $to): int
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable(max($from, $to)))->days + 1;

        return max(1, min(self::EMPLOYEES, intdiv(self::CELLS, $days)));
    }
}
