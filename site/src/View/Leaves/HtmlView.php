<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Leaves;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;
use BiotimePortal\Component\Biotime\Administrator\Database\LeaveReader;
use BiotimePortal\Component\Biotime\Site\Model\LeavesModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Leaves (1.1.0): the employee's own leave requests from ZKBioTime, newest first, with the
 * status and every approval stage. Read only.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** Why nothing can be shown ('' when it can): 'notgranted' or a database error kind. */
    public string $problem = '';

    public function display($tpl = null): void
    {
        /** @var LeavesModel $model */
        $model = $this->getModel();

        try {
            $this->requests = $model->requests();
        } catch (DbException $e) {
            // A message the site has (1.3.7: the raw kind, e.g. "unreachable", had none).
            $this->problem = $e->getKind() === DbException::QUERY ? 'notgranted' : SourceException::kindOf($e);
            $model->logger()->warning('My Leaves: leave requests could not be read (' . $e->getKind() . ($e->getKind() === DbException::QUERY
                ? '; grant SELECT on att_leave, att_paycode, workflow_workflowinstance, workflow_nodeinstance to the portal\'s database user, see docs/DATABASE.md' : '') . ')');
        }

        $this->preparePage($model, 'leaves', 'COM_BIOTIME_LEAVES_TITLE');
        $model->logPage('leaves', 'requests=' . \count($this->requests));

        parent::display($tpl);
    }

    /** Requests with this overall status. */
    public function count(int $status): int
    {
        return \count(array_filter($this->requests, static fn (array $r): bool => $r['status'] === $status));
    }

    /** Days of the approved requests. */
    public function approvedDays(): float
    {
        return array_sum(array_map(static fn (array $r): float => $r['status'] === LeaveReader::APPROVED ? (float) ($r['days'] ?? 0) : 0.0, $this->requests));
    }
}
