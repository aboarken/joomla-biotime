<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\DbException;

/**
 * My Approvals (1.1.0): the leave requests waiting at a stage the logged-in employee approves in
 * ZKBioTime (by their ZKBioTime approver role, or sent to them), and the stages they decided.
 * Read only: requests are approved in ZKBioTime.
 */
final class ApprovalsModel extends PortalModel
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function waiting(): array
    {
        // Other employees' requests, by the approver's current ZKBioTime role: never an older copy (1.3.7).
        return $this->cachedList('leaves:waiting', fn (): array => $this->biotime()->getLeaveReader()->waitingFor((int) $this->identity()->employeeId()), false);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function decided(): array
    {
        return $this->cachedList('leaves:decided', fn (): array => $this->biotime()->getLeaveReader()->decidedBy((int) $this->identity()->employeeId()));
    }
}
