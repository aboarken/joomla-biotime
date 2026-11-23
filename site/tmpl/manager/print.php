<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use BiotimePortal\Component\Biotime\Site\View\Manager\ReportColumns;
use Joomla\CMS\Uri\Uri;

/** @var \BiotimePortal\Component\Biotime\Site\View\Manager\HtmlView $this */

/*
 * Manager Dashboard report, "Export PDF" (1.0.11): opened in a new tab on a page of its own
 * (format=raw, RawView, 1.1.2: only the report, no site template, cookie notices or pop-ups), it
 * opens the print dialog, where "Save as PDF" makes the PDF. The page has the letterhead of
 * Integration Settings (logo, header texts, footer on every page), a title band, the filters,
 * the cards and every employee shown (not only one page of them). Always A4 landscape (1.0.14);
 * a period longer than 12 days is cut into several tables, each with
 * the employee column again and the totals in the last one.
 */

$f      = $this->f;
$e      = fn (string $text): string => $this->escape($text);
$min    = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);
$head   = $this->letterhead;
$range  = $this->from === $this->to ? $f->date($this->from) : $f->date($this->from) . ' – ' . $f->date($this->to);
$labels = $this->statusLabels();
$app    = Factory::getApplication();
$site   = trim((string) $app->get('sitename', ''));

// The paper and the main colour (HtmlView::reportCss()). The report's own page (RawView, 1.1.2)
// writes them into its <head>; inside the site template they go through the asset manager.
$this->getDocument()->getWebAssetManager()->addInlineStyle($this->reportCss());

// Footer lines: web and e-mail addresses become links.
$footerLine = static function (string $line) use ($e): string {
    return (string) preg_replace_callback(
        '~\b(https?://[^\s<>"]+[^\s<>".,;:)])|([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})~u',
        static fn (array $m): string => $m[1] !== ''
            ? '<a href="' . $m[1] . '">' . $m[1] . '</a>'
            : '<a href="mailto:' . $m[2] . '">' . $m[2] . '</a>',
        $e($line)
    );
};

$logo = $head['logo'] !== '' ? Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $head['logo']))) : '';

// Filters of the report.
$deptName = Text::_('COM_BIOTIME_MANAGER_ALL_DEPARTMENTS');

foreach ($this->departments as $d) {
    if ($d['id'] === $this->dept) {
        $deptName = $d['name'];
    }
}

$empName = Text::_('COM_BIOTIME_MANAGER_ALL_EMPLOYEES');

foreach ($this->team as $t) {
    if ($t['emp_code'] === $this->emp) {
        $empName = $t['name'] . ' (' . $t['emp_code'] . ')';
    }
}

$meta = [
    [Text::_('COM_BIOTIME_REPORT_PERIOD'), $range . ' · ' . Text::plural('COM_BIOTIME_N_DAYS', \count($this->dates))],
    [Text::_('COM_BIOTIME_MANAGER_DEPARTMENT'), $deptName],
    [Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'), $empName . ($this->code !== '' ? ' · ' . Text::_('COM_BIOTIME_MANAGER_SEARCH_ID') . ': ' . $this->code : '')],
    [Text::_('COM_BIOTIME_COL_STATUS'), $labels[$this->status] ?? $labels['']],
    [Text::_('COM_BIOTIME_REPORT_EMPLOYEES'), (string) $this->rowCount],
    [Text::_('COM_BIOTIME_REPORT_PRINTED'), trim($this->printedAt . ($this->printedBy !== '' ? ' · ' . $this->printedBy : ''))],
];
?>
<div class="com-biotime bt-report bt-report--landscape bt-report--<?php echo $this->paper; ?><?php echo max(array_map('count', $this->dateChunks ?: [[]])) > ReportColumns::A4 ? ' bt-report--dense' : ''; ?>" data-bt-root="manager-report" data-bt-report data-autoprint="<?php echo $this->problem === '' && $this->tooMany === 0 ? '1' : '0'; ?>">
    <div class="bt-report-tools">
        <button type="button" class="bt-btn" data-bt-print><?php echo Text::_('COM_BIOTIME_REPORT_PRINT'); ?></button>
        <button type="button" class="bt-btn bt-btn--ghost" data-bt-close><?php echo Text::_('COM_BIOTIME_REPORT_CLOSE'); ?></button>
        <span class="bt-report-tip"><?php echo Text::_('COM_BIOTIME_REPORT_TIP'); ?></span>
    </div>

    <div class="bt-report-sheet">
        <?php // On paper the footer is fixed at the bottom of every page; the frame's tfoot keeps room for it. ?>
        <?php if ($head['footer'] !== []) : ?>
            <footer class="bt-report-foot">
                <?php foreach ($head['footer'] as $line) : ?>
                    <p dir="auto"><?php echo $footerLine($line); ?></p>
                <?php endforeach; ?>
            </footer>
        <?php endif; ?>

        <table class="bt-report-frame" role="presentation">
            <thead>
                <tr><td>
                    <header class="bt-report-letterhead">
                        <div class="bt-report-logo">
                            <?php // The site name is always at the top (1.0.14); a logo, when one is set, above it. ?>
                            <?php if ($logo !== '') : ?>
                                <img src="<?php echo $e($logo); ?>" alt="">
                            <?php endif; ?>
                            <span class="bt-report-sitename"><?php echo $e($site); ?></span>
                        </div>
                        <?php if ($head['left'] !== '' || $head['right'] !== '') : ?>
                            <div class="bt-report-lines">
                                <span dir="auto"><?php echo $e($head['left']); ?></span>
                                <span dir="auto" class="bt-report-right"><?php echo $e($head['right']); ?></span>
                            </div>
                        <?php endif; ?>
                    </header>
                </td></tr>
            </thead>
            <tfoot>
                <tr><td><div class="bt-report-foot-space"></div></td></tr>
            </tfoot>
            <tbody>
                <tr><td>
                    <div class="bt-report-title">
                        <h1><?php echo Text::_('COM_BIOTIME_REPORT_TITLE'); ?></h1>
                        <span><?php echo $e($range); ?></span>
                    </div>

                    <?php if ($this->problem !== '') : ?>
                        <div class="bt-alert bt-tone-<?php echo $this->problem === 'nodepartment' ? 'amber' : 'red'; ?>" role="alert">
                            <?php echo $e(Text::_($this->problem === 'nodepartment' ? 'COM_BIOTIME_MANAGER_NO_DEPARTMENT' : 'COM_BIOTIME_ERROR_' . strtoupper($this->problem))); ?>
                        </div>
                    <?php elseif ($this->tooMany > 0) : ?>
                        <div class="bt-alert bt-tone-amber" role="status"><?php echo $e(Text::sprintf('COM_BIOTIME_MANAGER_TOO_MANY', $this->tooMany, $this->maxEmployees)); ?></div>
                    <?php else : ?>
                        <dl class="bt-report-meta">
                            <?php foreach ($meta as [$label, $value]) : ?>
                                <div><dt><?php echo $e($label); ?></dt><dd dir="auto"><?php echo $e($value); ?></dd></div>
                            <?php endforeach; ?>
                        </dl>

                        <?php foreach ($this->errors as $type => $kind) : ?>
                            <div class="bt-alert bt-tone-red" role="alert">
                                <strong><?php echo $e(Text::_('COM_BIOTIME_PART_' . strtoupper($type))); ?>:</strong>
                                <?php echo $e(Text::_('COM_BIOTIME_ERROR_' . strtoupper($kind))); ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="bt-stats bt-report-cards">
                            <?php foreach ($this->cards() as [$label, $value, $tone, $filter]) : ?>
                                <div class="bt-stat bt-tone-<?php echo $tone; ?><?php echo $filter !== '' && ($filter === '*' ? '' : $filter) === $this->status ? ' is-active' : ''; ?>">
                                    <span class="bt-stat-label"><?php echo $e($label); ?></span><span class="bt-stat-value"><?php echo $value; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($this->rows === [] || $this->dates === []) : ?>
                            <p class="bt-empty"><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></p>
                        <?php else : ?>
                            <?php foreach ($this->dateChunks as $i => $dates) :
                                $last = $i === \count($this->dateChunks) - 1;
                                ?>
                                <section class="bt-report-block">
                                    <?php if (\count($this->dateChunks) > 1) : ?>
                                        <h2 class="bt-report-part"><?php echo $e(Text::sprintf('COM_BIOTIME_REPORT_PART', $i + 1, \count($this->dateChunks), $f->date($dates[0]) . ' – ' . $f->date(end($dates)))); ?></h2>
                                    <?php endif; ?>
                                    <table class="bt-table bt-manager-table bt-report-table">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="bt-mgr-name"><?php echo Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'); ?></th>
                                                <?php foreach ($dates as $date) : ?>
                                                    <th scope="col" class="bt-mgr-date"><?php echo $e($f->date($date)); ?><span class="bt-sub"><?php echo $e($f->weekday((int) (new DateTimeImmutable($date))->format('N'), true)); ?></span></th>
                                                <?php endforeach; ?>
                                                <?php if ($last) : ?>
                                                    <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></th>
                                                    <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></th>
                                                    <th scope="col" class="bt-num"><?php echo Text::_('COM_BIOTIME_MANAGER_TOTAL'); ?></th>
                                                <?php endif; ?>
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
                                                <?php foreach ($dates as $date) : ?>
                                                    <?php echo LayoutHelper::render('biotime.managercell', ['day' => $row['days'][$date] ?? null, 'f' => $f]); ?>
                                                <?php endforeach; ?>
                                                <?php if ($last) : ?>
                                                    <td class="bt-num"><?php echo $min($row['late']); ?></td>
                                                    <td class="bt-num"><?php echo $min($row['early']); ?></td>
                                                    <td class="bt-num"><?php echo $row['late'] + $row['early'] > 0 ? '<strong class="bt-late">' . $e($f->duration($row['late'] + $row['early'])) . '</strong>' : '<span class="bt-check-none">—</span>'; ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td></tr>
            </tbody>
        </table>
    </div>
</div>
