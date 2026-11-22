<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \BiotimePortal\Component\Biotime\Administrator\View\Tables\HtmlView $this */

$action = Route::_('index.php?option=com_biotime');
$token  = HTMLHelper::_('form.token');
$e      = fn (string $t): string => $this->escape($t);
$diag   = $this->diagnostics;
$check  = $this->check;
$result = $check['result'] ?? null;
$rows   = $this->rows();
$at     = static fn (int $time): string => HTMLHelper::_('date', $time, Text::_('DATE_FORMAT_LC2'));
?>
<div class="biotime-tables">
    <p><?php echo Text::_('COM_BIOTIME_TABLES_INTRO'); ?></p>

    <section class="card mb-4" id="bt-diag">
        <div class="card-body">
            <h2 class="h4 card-title"><?php echo Text::_('COM_BIOTIME_DIAG_TITLE'); ?></h2>
            <p><?php echo Text::_('COM_BIOTIME_DIAG_INTRO'); ?></p>
            <form method="post" action="<?php echo $action; ?>">
                <input type="hidden" name="task" value="tables.diagnose">
                <?php echo $token; ?>
                <button class="btn btn-primary" type="submit"><span class="icon-wrench" aria-hidden="true"></span> <?php echo Text::_('COM_BIOTIME_DIAG_RUN'); ?></button>
                <?php if ($diag !== null) : ?>
                    <span class="small text-muted ms-2"><?php echo Text::sprintf('COM_BIOTIME_TABLES_AT', $at((int) $diag['at'])); ?></span>
                <?php endif; ?>
            </form>
            <?php if ($diag !== null) : ?>
                <label class="form-label mt-3" for="bt-diag-report"><?php echo Text::_('COM_BIOTIME_DIAG_REPORT'); ?></label>
                <textarea class="form-control font-monospace small" id="bt-diag-report" rows="18" readonly><?php echo $e($diag['report']); ?></textarea>
                <button class="btn btn-secondary mt-2" type="button" id="bt-diag-copy" data-done="<?php echo $e(Text::_('COM_BIOTIME_DIAG_COPIED')); ?>"><span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_BIOTIME_DIAG_COPY'); ?></button>
                <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_BIOTIME_DIAG_PRIVACY'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <h2 class="h4"><?php echo Text::_('COM_BIOTIME_TABLES_STEP1'); ?></h2>
    <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_TABLES_STEP1_HELP'); ?></p>
    <form method="post" action="<?php echo $action; ?>" class="mb-3">
        <input type="hidden" name="task" value="tables.find">
        <?php echo $token; ?>
        <button class="btn btn-primary" type="submit"><span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_BIOTIME_TABLES_FIND'); ?></button>
        <?php if ($this->list !== null) : ?>
            <span class="small text-muted ms-2"><?php echo Text::sprintf('COM_BIOTIME_TABLES_AT', $at((int) $this->list['at'])); ?></span>
        <?php endif; ?>
    </form>

    <form method="get" action="<?php echo Route::_('index.php'); ?>" class="row g-2 align-items-end mb-2">
        <input type="hidden" name="option" value="com_biotime">
        <input type="hidden" name="view" value="tables">
        <div class="col-auto">
            <label class="form-label" for="bt-filter"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
            <input class="form-control" id="bt-filter" name="filter" value="<?php echo $e($this->filter); ?>" placeholder="leave, workflow">
        </div>
        <div class="col-auto"><button class="btn btn-secondary" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button></div>
    </form>

    <div class="table-responsive mb-4">
        <table class="table table-striped table-sm align-middle">
            <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_TABLES_STEP1'); ?></caption>
            <thead><tr>
                <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_TABLE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_HOLDS'); ?></th>
                <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_USED_BY'); ?></th>
                <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_STATE'); ?></th>
                <th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_BIOTIME_TABLES_CHECK'); ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <th scope="row"><code><?php echo $e($r['name']); ?></code><?php if ($r['portal'] !== '') : ?> <span class="badge bg-info text-dark"><?php echo Text::_('COM_BIOTIME_TABLES_PORTAL'); ?></span><?php endif; ?></th>
                    <td class="small"><?php if ($r['section'] !== '') : ?><span class="text-muted"><?php echo $e($r['section']); ?>:</span> <?php endif; ?><?php echo $e($r['summary'] ?: '—'); ?></td>
                    <td class="small"><?php echo $e($r['portal'] ?: ($r['planned'] ? Text::_('COM_BIOTIME_TABLES_PLANNED') : '')); ?></td>
                    <td class="small">
                        <?php if ($r['found'] === null) : ?>
                            <span class="text-muted">—</span>
                        <?php elseif (!$r['found']) : ?>
                            <span class="badge bg-secondary"><?php echo Text::_('COM_BIOTIME_TABLES_MISSING'); ?></span>
                        <?php elseif ($r['readable']) : ?>
                            <span class="badge bg-success"><?php echo Text::_('COM_BIOTIME_TABLES_READABLE'); ?></span>
                            <?php echo $r['estimate'] !== null ? $e(Text::sprintf('COM_BIOTIME_TABLES_ABOUT_ROWS', number_format((int) $r['estimate']))) : ''; ?>
                        <?php else : ?>
                            <span class="badge bg-warning text-dark"><?php echo Text::_('COM_BIOTIME_TABLES_NOT_READABLE'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($r['found'] !== false) : ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo Route::_('index.php?option=com_biotime&view=tables&' . http_build_query(['use' => $r['name']])); ?>#bt-check"><?php echo Text::_('COM_BIOTIME_TABLES_SELECT'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($rows === []) : ?><p class="text-muted"><?php echo Text::_('COM_BIOTIME_TABLES_NONE_SHOWN'); ?></p><?php endif; ?>
    </div>

    <h2 class="h4" id="bt-check"><?php echo Text::_('COM_BIOTIME_TABLES_STEP2'); ?></h2>
    <form method="post" action="<?php echo $action; ?>" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="task" value="tables.check">
        <?php echo $token; ?>
        <div class="col-12 col-md-6 col-lg-4">
            <label class="form-label" for="bt-table"><?php echo Text::_('COM_BIOTIME_TABLES_TABLE'); ?></label>
            <input class="form-control" id="bt-table" name="table" required maxlength="128" autocomplete="off" list="bt-tables"
                   value="<?php echo $e($this->selected ?: (string) ($check['table'] ?? 'att_leave')); ?>" placeholder="att_leave">
            <datalist id="bt-tables"><?php foreach ($this->rows() as $r) : ?><option value="<?php echo $e($r['name']); ?>"></option><?php endforeach; ?></datalist>
        </div>
        <div class="col-auto"><button class="btn btn-primary" type="submit"><?php echo Text::_('COM_BIOTIME_TABLES_CHECK'); ?></button></div>
        <p class="col-12 small text-muted mb-0"><?php echo Text::_('COM_BIOTIME_TABLES_CHECK_HELP'); ?></p>
    </form>

    <?php if (is_array($result)) : ?>
        <h3 class="h5"><code><?php echo $e($result['name']); ?></code>
            <?php if ($result['rows'] !== null) : ?>
                · <?php echo $e(Text::sprintf('COM_BIOTIME_TABLES_ROWS', number_format((int) $result['rows']) . ($result['capped'] ? '+' : ''))); ?>
            <?php endif; ?>
            · <?php echo $e(Text::plural('COM_BIOTIME_TABLES_N_COLUMNS', count($result['columns']))); ?>
        </h3>

        <?php if (!$result['readable']) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_BIOTIME_TABLES_NOT_READABLE_MSG'); ?></div>
        <?php elseif ($result['error'] !== null) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_BIOTIME_TABLES_ROWS_FAILED'); ?> (<?php echo $e((string) $result['error']); ?>)</div>
        <?php endif; ?>

        <div class="table-responsive mb-3">
            <table class="table table-striped table-sm align-middle">
                <caption class="visually-hidden"><?php echo Text::_('COM_BIOTIME_TABLES_COLUMNS'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_COLUMN'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_TYPE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_KEY'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_POINTS_TO'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_FILLED'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_BIOTIME_TABLES_VALUES'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['columns'] as $c) : ?>
                    <tr>
                        <th scope="row"><code><?php echo $e($c['name']); ?></code></th>
                        <td class="small"><?php echo $e($c['type']); ?><?php echo $c['nullable'] ? '' : ' <span class="text-muted">' . Text::_('COM_BIOTIME_TABLES_REQUIRED') . '</span>'; ?></td>
                        <td class="small"><?php echo $c['key'] !== '' ? '<span class="badge bg-primary">' . $e($c['key']) . '</span>' : ''; ?></td>
                        <td class="small"><?php if ($c['ref'] !== '') : ?><a href="<?php echo Route::_('index.php?option=com_biotime&view=tables&' . http_build_query(['use' => strtok($c['ref'], '.')])); ?>#bt-check"><code><?php echo $e($c['ref']); ?></code></a><?php endif; ?></td>
                        <td class="small text-nowrap"><?php echo $c['filled'] === null ? '—' : $e($result['sample'] > 0 ? Text::sprintf('COM_BIOTIME_TABLES_FILLED_OF', number_format((int) $c['filled']), number_format((int) $result['sample']), (int) round(100 * $c['filled'] / $result['sample'])) : '0'); ?></td>
                        <td class="small"><?php echo $c['values'] === null ? '' : $e(implode(' · ', array_map(static fn (array $v): string => $v[0] . ' ×' . $v[1], $c['values']))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h4 class="h6"><?php echo Text::_('COM_BIOTIME_TABLES_REFERENCED_BY'); ?></h4>
        <?php if ($result['referenced_by'] === []) : ?>
            <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_TABLES_REFERENCED_NONE'); ?></p>
        <?php else : ?>
            <ul class="small">
                <?php foreach ($result['referenced_by'] as $ref) : ?>
                    <li><a href="<?php echo Route::_('index.php?option=com_biotime&view=tables&' . http_build_query(['use' => $ref['table']])); ?>#bt-check"><code><?php echo $e($ref['table'] . '.' . $ref['column']); ?></code></a> → <code><?php echo $e($result['name'] . '.' . $ref['to']); ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="small text-muted"><?php echo Text::_('COM_BIOTIME_TABLES_PRIVACY'); ?></p>
    <?php endif; ?>
</div>
