<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Attendance\HtmlView $this */

$f       = $this->f;
$s       = $this->summary;
$e       = fn (string $text): string => $this->escape($text);
$check   = fn ($day, string $type): string => LayoutHelper::render('biotime.checktime', ['day' => $day, 'f' => $f, 'type' => $type, 'device' => true]);
// Each day carries ZKBioTime's schedule, late-in, early-out and worked time (0.6.0).
$min     = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);
$filters = [
    ''              => Text::_('COM_BIOTIME_FILTER_ALL'),
    'attended'      => Text::_('COM_BIOTIME_FILTER_ATTENDED'),
    'present'       => Text::_('COM_BIOTIME_STATUS_PRESENT'),
    'missingpunch'  => Text::_('COM_BIOTIME_MISSING_PUNCH'),
    'missingmorning'   => Text::_('COM_BIOTIME_CARD_MISSING_MORNING'),
    'missingafternoon' => Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'),
    'absent'        => Text::_('COM_BIOTIME_STATUS_ABSENT'),
    'leave'         => Text::_('COM_BIOTIME_CARD_LEAVES'),
    'weekend'       => Text::_('COM_BIOTIME_STATUS_WEEKEND'),
    'weekendworked' => Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'),
    'late'          => Text::_('COM_BIOTIME_FILTER_LATE'),
    'early'         => Text::_('COM_BIOTIME_FILTER_EARLY'),
    'holiday'       => Text::_('COM_BIOTIME_STATUS_HOLIDAY'),
    'exceptions'    => Text::_('COM_BIOTIME_FILTER_EXCEPTIONS'),
    'punches'       => Text::_('COM_BIOTIME_FILTER_PUNCHES'),
    'nopunches'     => Text::_('COM_BIOTIME_FILTER_NO_PUNCHES'),
];

$statusSelect = '<select class="bt-input" id="bt-status" name="status">';

foreach ($filters as $value => $label) {
    $statusSelect .= '<option value="' . $value . '"' . ($value === $this->status ? ' selected' : '') . '>' . $e($label) . '</option>';
}

$statusSelect .= '</select>';
// From date – to date, below the month (0.9.0). Date pickers in DD/MM/YYYY
// (media/com_biotime/js/datepicker.js); a browser date field would show the browser's own order,
// e.g. MM/DD/YYYY. Month and weekday names are passed in translated. Choosing a month or year
// clears them (filters.js: data-bt-range).
$dateInput = fn (string $id, string $name, string $value): string => '<input class="bt-input" type="text" id="' . $id . '" name="' . $name . '" autocomplete="off" maxlength="10" data-bt-datepicker data-bt-range'
    . ' pattern="\d{1,2}/\d{1,2}/\d{4}" placeholder="' . $e(Text::_('COM_BIOTIME_DATE_HINT')) . '"'
    . ' data-months="' . $e(implode(',', array_map([$f, 'monthName'], range(1, 12)))) . '"'
    . ' data-days="' . $e(implode(',', array_map(static fn (int $d): string => $f->weekday($d, true), range(1, 7)))) . '"'
    . ' data-month="' . sprintf('%02d/%04d', $this->month, $this->year) . '"'
    . ' data-min="01/01/' . \BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService::FIRST_YEAR . '"'
    . ' data-labels="' . $e((string) json_encode([
        'choose' => Text::_('COM_BIOTIME_DATE_CHOOSE'),
        'prev'   => Text::_('COM_BIOTIME_PREV_MONTH'),
        'next'   => Text::_('COM_BIOTIME_NEXT_MONTH'),
        'today'  => Text::_('COM_BIOTIME_DATE_TODAY'),
        'clear'  => Text::_('COM_BIOTIME_DATE_CLEAR'),
    ])) . '"'
    . ' value="' . $e($value !== '' ? (new DateTimeImmutable($value))->format('d/m/Y') : '') . '">';

// Summary cards: [label, value html, tone, filter]. Missing punches as two cards: morning (no
// check-in) and afternoon (no check-out). 3 rows of 5.
// The first card, All Days, clears the status filter ('*'); a card with a filter shows only the
// matching days and jumps down to them (#bt-records, 1.0.0).
// All Days: present + absent + leaves + holidays + weekend days so far (1.0.3).
$allDays = [Text::_('COM_BIOTIME_CARD_ALL_DAYS'), (string) $s->allDays, Ui::cardTone('COM_BIOTIME_CARD_ALL_DAYS'), '*'];
$cards = [
    $allDays,
    [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', Ui::cardTone('COM_BIOTIME_CARD_PRESENT_DAYS'), 'attended'],
    [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::cardTone('COM_BIOTIME_CARD_ABSENT_DAYS'), 'absent'],
    [Text::_('COM_BIOTIME_CARD_LEAVES'), (string) $s->leaveDays, Ui::cardTone('COM_BIOTIME_CARD_LEAVES'), 'leave'],
    [Text::_('COM_BIOTIME_CARD_HOLIDAYS'), (string) $s->holidayDays, Ui::cardTone('COM_BIOTIME_CARD_HOLIDAYS'), 'holiday'],
    [Text::_('COM_BIOTIME_CARD_WEEKENDS'), (string) $s->offDays, Ui::cardTone('COM_BIOTIME_CARD_WEEKENDS'), 'weekend'],
    [Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::cardTone('COM_BIOTIME_CARD_WEEKEND_WORKED'), 'weekendworked'],
    [Text::_('COM_BIOTIME_CARD_MISSING_MORNING'), (string) $s->missingMorningDays, Ui::cardTone('COM_BIOTIME_CARD_MISSING_MORNING'), 'missingmorning'],
    [Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'), (string) $s->missingAfternoonDays, Ui::cardTone('COM_BIOTIME_CARD_MISSING_AFTERNOON'), 'missingafternoon'],
    [Text::_('COM_BIOTIME_CARD_PUNCHES'), (string) $s->punchCount, Ui::cardTone('COM_BIOTIME_CARD_PUNCHES'), 'punches'],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $e($f->time($s->avgCheckIn)), Ui::cardTone('COM_BIOTIME_CARD_AVG_CHECK_IN'), ''],
    [Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $e($f->time($s->avgCheckOut)), Ui::cardTone('COM_BIOTIME_CARD_AVG_CHECK_OUT'), ''],
    [Text::_('COM_BIOTIME_CARD_LATE_TOTAL'), $e($f->duration($s->lateMinutes)), Ui::cardTone('COM_BIOTIME_CARD_LATE_TOTAL'), 'late'],
    [Text::_('COM_BIOTIME_CARD_EARLY_TOTAL'), $e($f->duration($s->earlyMinutes)), Ui::cardTone('COM_BIOTIME_CARD_EARLY_TOTAL'), 'early'],
    [Text::_('COM_BIOTIME_CARD_LATE_EARLY'), $e($f->duration($s->lateEarlyMinutes())), Ui::cardTone('COM_BIOTIME_CARD_LATE_EARLY'), ''],
    [Text::_('COM_BIOTIME_CARD_WORKED'), $e($f->duration($s->workedMinutes)), Ui::cardTone('COM_BIOTIME_CARD_WORKED'), ''],
    [Text::_('COM_BIOTIME_CARD_OVERTIME'), $e($f->duration($s->overtimeMinutes)), Ui::cardTone('COM_BIOTIME_CARD_OVERTIME'), ''],
];
?>
<div class="com-biotime biotime-attendance" data-bt-root="attendance">
    <div class="bt-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $e($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
        <?php echo LayoutHelper::render('biotime.monthnav', ['view' => 'attendance', 'year' => $this->year, 'month' => $this->month, 'today' => $this->period->today, 'f' => $f]); ?>
    </div>

    <?php echo LayoutHelper::render('biotime.toolbar', [
        'view'   => 'attendance',
        'year'   => $this->year,
        'month'  => $this->month,
        'f'      => $f,
        'fields' => [
            ['id' => 'bt-status', 'label' => Text::_('COM_BIOTIME_COL_STATUS'), 'html' => $statusSelect],
            ['break' => true],
            ['id' => 'bt-from', 'label' => Text::_('COM_BIOTIME_DATE_FROM'), 'html' => $dateInput('bt-from', 'from', $this->from)],
            ['id' => 'bt-to', 'label' => Text::_('COM_BIOTIME_DATE_TO'), 'html' => $dateInput('bt-to', 'to', $this->to)],
        ],
    ]); ?>

    <?php echo $this->notices($this->period); ?>

    <div class="bt-stats bt-stats--5">
        <?php foreach ($cards as [$label, $value, $tone, $filter]) :
            $inner = '<span class="bt-stat-label">' . $e($label) . '</span><span class="bt-stat-value">' . $value . '</span>';
            ?>
            <?php if ($filter !== '') :
                $value = $filter === '*' ? '' : $filter;
                ?>
                <a class="bt-stat bt-tone-<?php echo $tone; ?><?php echo $value === $this->status ? ' is-active' : ''; ?>" href="<?php echo $this->filterUrl($value); ?>#bt-records"<?php echo $value === $this->status ? ' aria-current="true"' : ''; ?>><?php echo $inner; ?></a>
            <?php else : ?>
                <div class="bt-stat bt-tone-<?php echo $tone; ?>"><?php echo $inner; ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <section class="bt-panel" id="bt-records">
        <div class="bt-panel-head">
            <h2 class="bt-panel-title"><?php echo $e($this->rangeTitle()); ?></h2>
            <span class="bt-panel-count"><?php echo $e(Text::plural('COM_BIOTIME_N_DAYS', $this->total)); ?><?php echo $this->status !== '' ? ' · ' . $e($filters[$this->status] ?? '') : ''; ?></span>
        </div>
        <?php if ($this->rows === []) : ?>
            <p class="bt-panel-body bt-empty"><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></p>
        <?php else : ?>
            <div class="bt-table-wrap">
                <table class="bt-table">
                    <caption class="visually-hidden"><?php echo $e($this->pageHeading . ' – ' . $this->monthTitle()); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_DATE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_SCHEDULE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_IN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_CHECK_OUT'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_TOTAL_LATE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_BIOTIME_COL_STATUS'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->rows as $day) : ?>
                        <tr class="<?php echo $day->status === 'holiday' ? 'bt-row-holiday' : ($day->weekend ? 'bt-row-weekend' : ''); ?><?php echo $day->date === $this->period->today ? ' bt-row-today' : ''; ?>">
                            <th scope="row"><a href="<?php echo $this->url('view=day&date=' . $day->date); ?>"><?php echo $e($f->date($day->date)); ?></a>
                                <span class="bt-sub"><?php echo $e($f->weekday($day->weekday())); ?></span></th>
                            <td><?php echo LayoutHelper::render('biotime.schedule', ['day' => $day, 'f' => $f]); ?></td>
                            <td><?php echo $check($day, 'in'); ?></td>
                            <td><?php echo $check($day, 'out'); ?></td>
                            <td><?php echo $min($day->late); ?></td>
                            <td><?php echo $min($day->early); ?></td>
                            <?php // Total Late: late-in + early-out of the day (1.0.5, was Worked). ?>
                            <td><?php echo $min(($day->late ?? 0) + ($day->early ?? 0)); ?></td>
                            <td>
                                <div class="bt-badges">
                                    <?php echo LayoutHelper::render('biotime.status', ['day' => $day, 'f' => $f]); ?>
                                    <?php foreach ($day->exceptions as $ex) : ?>
                                        <?php // Late and early have their own columns. ?>
                                        <?php if (!in_array($ex, ['missing_check_in', 'missing_check_out', 'late', 'early'], true)) : ?>
                                            <span class="bt-badge bt-badge--outline bt-tone-amber"><?php echo $e($f->exception($ex)); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php // Pages of days (1.2.9), as on the Manager Dashboard. ?>
            <?php if ($this->pages > 1) : ?>
                <nav class="bt-pager" aria-label="<?php echo $e(Text::_('COM_BIOTIME_ATTENDANCE_PAGES')); ?>">
                    <?php if ($this->page > 1) : ?><a class="bt-btn bt-btn--ghost" href="<?php echo $this->filterUrl($this->status, $this->page - 1); ?>#bt-records">&larr; <?php echo Text::_('COM_BIOTIME_MANAGER_PREV'); ?></a><?php endif; ?>
                    <span><?php echo $e(Text::sprintf('COM_BIOTIME_MANAGER_PAGE_OF', $this->page, $this->pages)); ?></span>
                    <?php if ($this->page < $this->pages) : ?><a class="bt-btn bt-btn--ghost" href="<?php echo $this->filterUrl($this->status, $this->page + 1); ?>#bt-records"><?php echo Text::_('COM_BIOTIME_MANAGER_NEXT'); ?> &rarr;</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
