<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Manager\HtmlView $this */

/*
 * Manager Dashboard (1.0.0): filter bar (department, employee, status / From, To), cards
 * (workforce and the figures of My Attendance for everyone shown; a card with a filter shows only
 * the matching days and jumps to them), then one row per employee per day.
 */

$f     = $this->f;
$e     = fn (string $text): string => $this->escape($text);
$min   = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);

// Department list: the manager's department, then those below it (indented).
$deptSelect = '<select class="bt-input" id="bt-dept" name="dept"><option value="">' . $e(Text::_('COM_BIOTIME_MANAGER_ALL_DEPARTMENTS')) . '</option>';

foreach ($this->departments as $d) {
    $deptSelect .= '<option value="' . $d['id'] . '"' . ($d['id'] === $this->dept ? ' selected' : '') . '>' . str_repeat('&nbsp;&nbsp;', $d['depth']) . $e($d['name']) . '</option>';
}

$deptSelect .= '</select>';

// Employee list: those of the chosen department (or all the manager's).
$empSelect = '<select class="bt-input" id="bt-emp" name="emp"><option value="">' . $e(Text::_('COM_BIOTIME_MANAGER_ALL_EMPLOYEES')) . '</option>';

foreach ($this->dept > 0 ? $this->employees : $this->team as $emp) {
    $empSelect .= '<option value="' . $e($emp['emp_code']) . '"' . ($emp['emp_code'] === $this->emp ? ' selected' : '') . '>' . $e($emp['name'] . ' (' . $emp['emp_code'] . ')') . '</option>';
}

$empSelect .= '</select>';

$filters = $this->statusLabels();
$statusSelect = '<select class="bt-input" id="bt-status" name="status">';

foreach ($filters as $value => $label) {
    $statusSelect .= '<option value="' . $value . '"' . ($value === $this->status ? ' selected' : '') . '>' . $e($label) . '</option>';
}

$statusSelect .= '</select>';

$dateInput = fn (string $id, string $name, string $value): string => '<input class="bt-input" type="text" id="' . $id . '" name="' . $name . '" autocomplete="off" maxlength="10" data-bt-datepicker'
    . ' pattern="\d{1,2}/\d{1,2}/\d{4}" placeholder="' . $e(Text::_('COM_BIOTIME_DATE_HINT')) . '"'
    . ' data-months="' . $e(implode(',', array_map([$f, 'monthName'], range(1, 12)))) . '"'
    . ' data-days="' . $e(implode(',', array_map(static fn (int $d): string => $f->weekday($d, true), range(1, 7)))) . '"'
    . ' data-month="' . sprintf('%02d/%04d', $this->month, $this->year) . '"'
    . ' data-min="01/01/' . AttendanceService::FIRST_YEAR . '"'
    . ' data-labels="' . $e((string) json_encode([
        'choose' => Text::_('COM_BIOTIME_DATE_CHOOSE'),
        'prev'   => Text::_('COM_BIOTIME_PREV_MONTH'),
        'next'   => Text::_('COM_BIOTIME_NEXT_MONTH'),
        'today'  => Text::_('COM_BIOTIME_DATE_TODAY'),
        'clear'  => Text::_('COM_BIOTIME_DATE_CLEAR'),
    ])) . '"'
    . ' value="' . $e((new DateTimeImmutable($value))->format('d/m/Y')) . '">';

// Cards: [label, value html, tone, filter]; '*' = every day (clears the status filter).
$cards = $this->cards();
$range = $this->from === $this->to ? $f->date($this->from) : $f->date($this->from) . ' – ' . $f->date($this->to);
?>
<div class="com-biotime biotime-manager" data-bt-root="manager">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
    </div>

    <?php if ($this->problem !== '') : ?>
        <div class="bt-alert bt-tone-<?php echo $this->problem === 'nodepartment' ? 'amber' : 'red'; ?>" role="alert">
            <?php echo $e(Text::_($this->problem === 'nodepartment' ? 'COM_BIOTIME_MANAGER_NO_DEPARTMENT' : 'COM_BIOTIME_ERROR_' . strtoupper($this->problem))); ?>
        </div>
    <?php else : ?>
        <?php echo LayoutHelper::render('biotime.toolbar', [
            'view'   => 'manager',
            'f'      => $f,
            'fields' => [
                ['id' => 'bt-dept', 'label' => Text::_('COM_BIOTIME_MANAGER_DEPARTMENT'), 'html' => $deptSelect],
                ['id' => 'bt-emp', 'label' => Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'), 'html' => $empSelect],
                ['id' => 'bt-status', 'label' => Text::_('COM_BIOTIME_COL_STATUS'), 'html' => $statusSelect],
                ['break' => true],
                // Employee ID search (1.0.4): Enter applies it.
                ['id' => 'bt-code', 'label' => Text::_('COM_BIOTIME_MANAGER_SEARCH_ID'), 'html' => '<input class="bt-input" type="search" id="bt-code" name="code" maxlength="20" autocomplete="off" placeholder="' . $e(Text::_('COM_BIOTIME_MANAGER_SEARCH_ID_HINT')) . '" value="' . $e($this->code) . '">'],
                ['id' => 'bt-from', 'label' => Text::_('COM_BIOTIME_DATE_FROM'), 'html' => $dateInput('bt-from', 'from', $this->from)],
                ['id' => 'bt-to', 'label' => Text::_('COM_BIOTIME_DATE_TO'), 'html' => $dateInput('bt-to', 'to', $this->to)],
            ],
        ]); ?>

        <?php foreach ($this->errors as $type => $kind) : ?>
            <div class="bt-alert bt-tone-red" role="alert">
                <strong><?php echo $e(Text::_('COM_BIOTIME_PART_' . strtoupper($type))); ?>:</strong>
                <?php echo $e(Text::_('COM_BIOTIME_ERROR_' . strtoupper($kind))); ?>
            </div>
        <?php endforeach; ?>

        <?php if ($this->tooMany > 0) : ?>
            <div class="bt-alert bt-tone-amber" role="status"><?php echo $e(Text::sprintf('COM_BIOTIME_MANAGER_TOO_MANY', $this->tooMany, $this->maxEmployees)); ?></div>
        <?php else : ?>
        <div class="bt-stats bt-stats--5">
            <?php foreach ($cards as [$label, $value, $tone, $filter]) :
                $inner = '<span class="bt-stat-label">' . $e($label) . '</span><span class="bt-stat-value">' . $value . '</span>';
                ?>
                <?php if ($filter !== '') :
                    $value  = $filter === '*' ? '' : $filter;
                    $active = $value === $this->status;
                    ?>
                    <a class="bt-stat bt-tone-<?php echo $tone; ?><?php echo $active ? ' is-active' : ''; ?>" href="<?php echo $this->filterUrl(['status' => $value]); ?>#bt-records"<?php echo $active ? ' aria-current="true"' : ''; ?>><?php echo $inner; ?></a>
                <?php else : ?>
                    <div class="bt-stat bt-tone-<?php echo $tone; ?>"><?php echo $inner; ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <section class="bt-panel" id="bt-records">
            <div class="bt-panel-head">
                <h2 class="bt-panel-title" id="bt-mgr-range"><?php echo $e($range); ?></h2>
                <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_MANAGER_N_EMPLOYEES', $this->rowCount)); ?> · <?php echo $e(Text::plural('COM_BIOTIME_N_DAYS', \count($this->dates))); ?><?php echo $this->status !== '' ? ' · ' . $e($filters[$this->status] ?? '') : ''; ?></span>
            </div>
            <?php if ($this->rows === [] || $this->dates === []) : ?>
                <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></p>
            <?php else : ?>
                <?php // Its own scroll area: the sideways scrollbar stays in view, the header row and the employee column stay put (1.0.2). ?>
                <div class="bt-table-wrap bt-table-wrap--fixed" tabindex="0" role="region" aria-labelledby="bt-mgr-range">
                    <table class="bt-table bt-manager-table">
                        <caption class="visually-hidden"><?php echo $e(Text::_('COM_BIOTIME_MANAGER_TITLE') . ': ' . $range); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col" class="bt-mgr-name"><?php echo Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'); ?></th>
                                <?php foreach ($this->dates as $date) :
                                    $dt = new DateTimeImmutable($date);
                                    ?>
                                    <th scope="col" class="bt-mgr-date"><?php echo $e($f->date($date)); ?><span class="bt-sub"><?php echo $e($f->weekday((int) $dt->format('N'))); ?></span></th>
                                <?php endforeach; ?>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></th>
                                <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_MANAGER_TOTAL'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->rows as $row) :
                            $emp = $row['employee'];
                            ?>
                            <tr>
                                <th scope="row" class="bt-mgr-name">
                                    <?php echo $e($emp['name']); ?>
                                    <span class="bt-sub"><?php echo $e(implode(' · ', array_filter([$emp['emp_code'], $emp['department']]))); ?></span>
                                </th>
                                <?php foreach ($this->dates as $date) : ?>
                                    <?php echo LayoutHelper::render('biotime.managercell', ['day' => $row['days'][$date] ?? null, 'f' => $f]); ?>
                                <?php endforeach; ?>
                                <td class="bt-num"><?php echo $min($row['late']); ?></td>
                                <td class="bt-num"><?php echo $min($row['early']); ?></td>
                                <td class="bt-num"><?php echo $row['late'] + $row['early'] > 0 ? '<strong class="bt-late">' . $e($f->duration($row['late'] + $row['early'])) . '</strong>' : '<span class="bt-check-none">—</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($this->pages > 1) : ?>
                <nav class="bt-pager" aria-label="<?php echo $e(Text::_('COM_BIOTIME_MANAGER_PAGES')); ?>">
                    <?php if ($this->page > 1) : ?><a class="bt-btn bt-btn--ghost" href="<?php echo $this->filterUrl(['page' => (string) ($this->page - 1)]); ?>#bt-records">&larr; <?php echo Text::_('COM_BIOTIME_MANAGER_PREV'); ?></a><?php endif; ?>
                    <span><?php echo $e(Text::sprintf('COM_BIOTIME_MANAGER_PAGE_OF', $this->page, $this->pages)); ?></span>
                    <?php if ($this->page < $this->pages) : ?><a class="bt-btn bt-btn--ghost" href="<?php echo $this->filterUrl(['page' => (string) ($this->page + 1)]); ?>#bt-records"><?php echo Text::_('COM_BIOTIME_MANAGER_NEXT'); ?> &rarr;</a><?php endif; ?>
                </nav>
            <?php endif; ?>
            <?php if ($this->rows !== [] && $this->dates !== []) : ?>
                <?php // Export PDF (1.0.11): every employee of every page on letterhead paper, in a new tab with the print dialog. ?>
                <?php // A4 (blue) then A3 (green), 1.0.17. A3: the whole period in one table, every employee one row; A4: up to 12 days per table. ?>
                <div class="bt-export">
                    <?php foreach (['a4' => 'COM_BIOTIME_MANAGER_EXPORT_PDF_A4', 'a3' => 'COM_BIOTIME_MANAGER_EXPORT_PDF_A3'] as $paper => $key) : ?>
                        <a class="bt-btn bt-btn--<?php echo $paper; ?>" href="<?php echo $this->filterUrl(['layout' => 'pdf', 'format' => 'raw', 'paper' => $paper]); ?>" rel="nofollow">
                            <svg class="bt-btn-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M9.5 1.75H4.25a1.5 1.5 0 0 0-1.5 1.5v9.5a1.5 1.5 0 0 0 1.5 1.5h7.5a1.5 1.5 0 0 0 1.5-1.5V5.5zM9.5 1.75V5.5h3.75M8 7.5v4.5M6 10l2 2 2-2"/></svg>
                            <?php echo Text::_($key); ?>
                        </a>
                    <?php endforeach; ?>
                    <span class="bt-export-hint"><?php echo $e(Text::plural('COM_BIOTIME_MANAGER_EXPORT_HINT', $this->rowCount)); ?></span>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
