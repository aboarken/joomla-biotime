<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Paper of the Manager Dashboard report (Export PDF, 1.0.11), always landscape (1.0.14).
 *  - A3 (1.0.16): up to 31 days in one table, so every employee is one row; with many days the
 *    table is drawn smaller (bt-report--dense). A longer period (up to a year since 1.3.6) is cut
 *    into balanced tables of at most 31 dates.
 *  - A4: up to A4 dates side by side next to the employee column and the totals; a longer period
 *    is cut into balanced tables of at most A4 dates (31 days: 11, 11 and 9).
 */
final class ReportColumns
{
    public const A4 = 12;

    public const A3 = 31;

    /** The papers, as ?paper= takes them. */
    public const PAPERS = ['a4', 'a3'];

    /** The paper asked for (?paper=), A4 when it is anything else. */
    public static function paper(string $value): string
    {
        return \in_array($value, self::PAPERS, true) ? $value : 'a4';
    }

    /**
     * @param  list<string> $dates
     * @return list<list<string>> the dates of each table
     */
    public static function of(array $dates, string $paper = 'a4'): array
    {
        $max = self::paper($paper) === 'a3' ? self::A3 : self::A4;

        if (\count($dates) <= $max) {
            return [$dates];
        }

        $tables = (int) ceil(\count($dates) / $max);

        return array_chunk($dates, (int) ceil(\count($dates) / $tables));
    }
}
