<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Approvals;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;
use BiotimePortal\Component\Biotime\Site\Model\ApprovalsModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Approvals (1.1.0): the leave requests waiting at the employee's stage in ZKBioTime (the
 * longest waiting first), then the stages they approved or rejected. Read only.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var list<array<string, mixed>> */
    public array $waiting = [];

    /** @var list<array<string, mixed>> each with 'decision' */
    public array $decided = [];

    /** Why nothing can be shown ('' when it can): 'notgranted' or a database error kind. */
    public string $problem = '';

    public function display($tpl = null): void
    {
        /** @var ApprovalsModel $model */
        $model = $this->getModel();

        try {
            $this->waiting = $model->waiting();
            $this->decided = $model->decided();
        } catch (DbException $e) {
            // A message the site has (1.3.7: the raw kind, e.g. "unreachable", had none).
            $this->problem = $e->getKind() === DbException::QUERY ? 'notgranted' : SourceException::kindOf($e);
            $model->logger()->warning('My Approvals: leave requests could not be read (' . $e->getKind() . ($e->getKind() === DbException::QUERY
                ? '; grant SELECT on att_leave, att_paycode, workflow_workflowinstance, workflow_nodeinstance, workflow_workflownode_approver, personnel_employee_flow_role to the portal\'s database user, see docs/DATABASE.md' : '') . ')');
        }

        $this->preparePage($model, 'approvals', 'COM_BIOTIME_APPROVALS_TITLE');
        $model->logPage('approvals', 'waiting=' . \count($this->waiting) . ' decided=' . \count($this->decided));

        parent::display($tpl);
    }

    /** Stages this employee decided with this status. */
    public function decidedCount(int $status): int
    {
        return \count(array_filter($this->decided, static fn (array $r): bool => $r['decision']['status'] === $status));
    }
}
