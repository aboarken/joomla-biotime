<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use Joomla\CMS\Factory;

/**
 * One day of the logged-in employee. The date only selects the day; a date with no data, or an
 * invalid date, gives the same "not found" answer.
 */
final class DayModel extends PortalModel
{
    public function date(): ?string
    {
        $date = Factory::getApplication()->getInput()->getString('date', '');

        if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        // 1.3.7 (audit): only dates the portal shows (from its first year to today), so a typed URL
        // never reads or caches months that cannot have data.
        return $date >= AttendanceService::FIRST_YEAR . '-01-01' && $date <= $this->attendance()->today() ? $date : null;
    }

    public function selectedMonth(): array
    {
        $date = $this->date();

        return $date !== null ? [(int) substr($date, 0, 4), (int) substr($date, 5, 2)] : parent::selectedMonth();
    }

    public function day(): ?Day
    {
        $date = $this->date();

        return $date === null ? null : $this->period()->day($date);
    }
}
