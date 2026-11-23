<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Site\View\Manager\ReportColumns;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \BiotimePortal\Component\Biotime\Site\View\Manager\HtmlView $this */

/*
 * Manager Dashboard report as a PDF file (Export PDF, 1.2.0): the HTML the server gives to mPDF
 * (RawView, PdfReport). The same content as the page report (print.php): letterhead (site name,
 * logo and header lines of Integration Settings) and footer with the page number on every page,
 * title band, filters, cards and the table. mPDF has no CSS grid, flexbox or variables, so the
 * layout is made of tables and the colours are written out: those of the portal's tones, read
 * from portal.css so the PDF keeps the page's colours.
 */

$f      = $this->f;
$e      = fn (?string $text): string => $this->escape((string) $text);
$min    = fn (?int $minutes): string => LayoutHelper::render('biotime.minutes', ['minutes' => $minutes, 'f' => $f]);
$head   = $this->letterhead;
$range  = $this->from === $this->to ? $f->date($this->from) : $f->date($this->from) . ' – ' . $f->date($this->to);
$labels = $this->statusLabels();
$site   = trim((string) Factory::getApplication()->get('sitename', ''));
$main   = $head['color'];
$dense  = max(array_map('count', $this->dateChunks ?: [[]])) > ReportColumns::A4;
$perRow = $this->paper === 'a3' ? 10 : 7;

// The tones of portal.css (strong colour and light background), e.g. .bt-tone-red.
$tones = [];
$css   = (string) @file_get_contents(JPATH_ROOT . '/media/com_biotime/css/portal.css');

if (preg_match_all('/\.bt-tone-([a-z]+)\s*\{\s*--bt-tone:\s*(#[0-9a-f]{6});\s*--bt-tone-soft:\s*(#[0-9a-f]{6});/i', $css, $m, PREG_SET_ORDER)) {
    foreach ($m as [, $name, $strong, $soft]) {
        $tones[strtolower($name)] = [$strong, $soft];
    }
}

$toneCss = '';

foreach ($tones as $name => [$strong, $soft]) {
    $toneCss .= '.bt-tone-' . $name . '{color:' . $strong . '}'
        . 'span.bt-badge.bt-tone-' . $name . '{background-color:' . $soft . '}'
        . 'td.card-' . $name . '{border-left:1.2mm solid ' . $strong . '}';
}

// The logo, as a file of the site (only an image below images/, checked by Settings).
$logo = $head['logo'] !== '' && is_file(JPATH_ROOT . '/' . $head['logo']) ? JPATH_ROOT . '/' . $head['logo'] : '';

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
$cards = $this->cards();

// Room for the letterhead (site name; logo and header lines when set) and the footer lines, in mm.
$top    = 6 + 9 + ($logo !== '' ? 22 : 0) + ($head['left'] !== '' || $head['right'] !== '' ? 5 : 0) + 4;
$bottom = 5 + max(5, 3.6 * \count($head['footer'])) + 4;

?>
<style>
@page {
    margin-top: <?php echo $top; ?>mm;
    margin-bottom: <?php echo $bottom; ?>mm;
    margin-header: 6mm;
    margin-footer: 5mm;
    odd-header-name: html_letterhead;
    even-header-name: html_letterhead;
    odd-footer-name: html_footer;
    even-footer-name: html_footer;
}
body { font-family: dejavusanscondensed; font-size: 8pt; color: #1f2937; }
.visually-hidden { display: none; }
.letterhead { width: 100%; border-bottom: 0.6mm solid <?php echo $main; ?>; padding-bottom: 2mm; }
.sitename { font-size: 15pt; font-weight: bold; color: <?php echo $main; ?>; text-align: center; }
.lines td { font-size: 7.5pt; color: #374151; }
.foot { width: 100%; border-top: 0.4mm solid <?php echo $main; ?>; font-size: 7pt; color: #374151; }
.foot a { color: <?php echo $main; ?>; text-decoration: none; }
.title { width: 100%; background-color: <?php echo $main; ?>; color: #ffffff; margin-bottom: 3mm; }
.title td { padding: 2.5mm 4mm; }
.title-name { font-size: 13pt; font-weight: bold; }
.title-range { font-size: 10pt; font-weight: bold; text-align: right; }
.meta { width: 100%; background-color: #f8fafc; border-left: 1mm solid <?php echo $main; ?>; margin-bottom: 3mm; }
.meta td { padding: 1.5mm 3mm; vertical-align: top; }
.meta-label { font-size: 6.5pt; font-weight: bold; color: #6b7280; text-transform: uppercase; }
.meta-value { font-weight: bold; }
.cards { width: 100%; border-collapse: separate; border-spacing: 1.5mm; margin-bottom: 2mm; }
.cards td.card { border: 0.2mm solid #e5e7eb; background-color: #ffffff; padding: 1.5mm 2mm; vertical-align: top; }
.card-label { font-size: 6.5pt; color: #6b7280; }
.card-value { font-size: 11pt; font-weight: bold; }
.card-value small { font-size: 8pt; color: #6b7280; }
.part { font-size: 9pt; font-weight: bold; color: <?php echo $main; ?>; margin: 2mm 0 1mm; }
table.data { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: <?php echo $dense ? '6.5pt' : '7.5pt'; ?>; }
table.data th, table.data td { border: 0.15mm solid #e5e7eb; padding: <?php echo $dense ? '0.8mm 0.6mm' : '1.2mm'; ?>; vertical-align: top; }
table.data thead th { background-color: <?php echo $main; ?>; color: #ffffff; font-size: <?php echo $dense ? '6pt' : '7pt'; ?>; text-align: left; }
table.data thead th.bt-num, table.data td.bt-num { text-align: left; }
table.data tbody th { text-align: left; font-weight: bold; }
.bt-sub { display: block; font-size: <?php echo $dense ? '5.5pt' : '6.5pt'; ?>; font-weight: normal; color: #6b7280; }
div.head-sub { font-size: <?php echo $dense ? '5.5pt' : '6.5pt'; ?>; font-weight: normal; color: #dbe4f0; }
.bt-inout-line { display: block; }
.bt-check-in { color: #15803d; font-weight: bold; }
.bt-check-out { color: #1d4ed8; font-weight: bold; }
.bt-check-missing { color: #dc2626; font-weight: bold; }
.bt-check-none { color: #9ca3af; }
.bt-late { color: #b45309; font-weight: bold; }
.bt-mgr-mins { display: block; color: #b45309; font-weight: bold; font-size: <?php echo $dense ? '5.5pt' : '6.5pt'; ?>; }
span.bt-badge { font-weight: bold; font-size: <?php echo $dense ? '5.5pt' : '6.5pt'; ?>; padding: 0.2mm 1mm; }
.bt-dot { display: none; }
td.bt-row-weekend { background-color: #eef2fb; }
td.bt-row-holiday { background-color: #f5f0ff; }
td.bt-mgr-late { background-color: #fffbeb; }
.alert { padding: 3mm; border: 0.3mm solid #f59e0b; background-color: #fffbeb; }
<?php echo $toneCss; ?>
</style>

<htmlpageheader name="letterhead">
    <table class="letterhead">
        <?php if ($logo !== '') : ?>
            <tr><td colspan="2" style="text-align: center;"><img src="<?php echo $e($logo); ?>" style="max-height: 20mm;"></td></tr>
        <?php endif; ?>
        <tr><td colspan="2" class="sitename"><?php echo $e($site); ?></td></tr>
        <?php if ($head['left'] !== '' || $head['right'] !== '') : ?>
            <tr class="lines"><td><?php echo $e($head['left']); ?></td><td style="text-align: right;"><?php echo $e($head['right']); ?></td></tr>
        <?php endif; ?>
    </table>
</htmlpageheader>

<htmlpagefooter name="footer">
    <table class="foot">
        <tr>
            <td style="width: 85%; text-align: center;">
                <?php foreach ($head['footer'] as $line) : ?>
                    <div><?php echo $e($line); ?></div>
                <?php endforeach; ?>
            </td>
            <td style="width: 15%; text-align: right; vertical-align: bottom; color: #6b7280;">{PAGENO} / {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>


<table class="title"><tr>
    <td class="title-name"><?php echo Text::_('COM_BIOTIME_REPORT_TITLE'); ?></td>
    <td class="title-range"><?php echo $e($range); ?></td>
</tr></table>

<?php if ($this->problem !== '' || $this->tooMany > 0) : ?>
    <div class="alert"><?php echo $e($this->tooMany > 0
        ? Text::sprintf('COM_BIOTIME_MANAGER_TOO_MANY', $this->tooMany, $this->maxEmployees)
        : Text::_($this->problem === 'nodepartment' ? 'COM_BIOTIME_MANAGER_NO_DEPARTMENT' : 'COM_BIOTIME_ERROR_' . strtoupper($this->problem))); ?></div>
<?php else : ?>
    <table class="meta">
        <?php foreach (array_chunk($meta, 3) as $metaRow) : ?>
            <tr>
                <?php foreach ($metaRow as [$label, $value]) : ?>
                    <td><div class="meta-label"><?php echo $e($label); ?></div><div class="meta-value"><?php echo $e($value); ?></div></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php foreach ($this->errors as $type => $kind) : ?>
        <div class="alert"><b><?php echo $e(Text::_('COM_BIOTIME_PART_' . strtoupper($type))); ?>:</b> <?php echo $e(Text::_('COM_BIOTIME_ERROR_' . strtoupper($kind))); ?></div>
    <?php endforeach; ?>

    <table class="cards">
        <?php foreach (array_chunk($cards, $perRow) as $cardRow) : ?>
            <tr>
                <?php foreach ($cardRow as [$label, $value, $tone]) : ?>
                    <td class="card card-<?php echo $e($tone); ?>" style="width: <?php echo round(100 / $perRow, 2); ?>%;">
                        <div class="card-label"><?php echo $e($label); ?></div>
                        <div class="card-value bt-tone-<?php echo $e($tone); ?>"><?php echo $value; ?></div>
                    </td>
                <?php endforeach; ?>
                <?php for ($i = \count($cardRow); $i < $perRow; $i++) : ?>
                    <td></td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($this->rows === [] || $this->dates === []) : ?>
        <p><?php echo Text::_('COM_BIOTIME_NO_DAYS'); ?></p>
    <?php else : ?>
        <?php foreach ($this->dateChunks as $i => $dates) :
            $last = $i === \count($this->dateChunks) - 1;
            ?>
            <?php if ($i > 0) : ?><pagebreak /><?php endif; ?>
            <?php if (\count($this->dateChunks) > 1) : ?>
                <div class="part"><?php echo $e(Text::sprintf('COM_BIOTIME_REPORT_PART', $i + 1, \count($this->dateChunks), $f->date($dates[0]) . ' – ' . $f->date(end($dates)))); ?></div>
            <?php endif; ?>
            <?php
            // Fixed column widths (1.2.1): the employee column and the three totals take a set
            // share of the page, the dates share the rest equally, and text wraps inside its
            // column, so long names or large totals never push columns off the page.
            $nameWidth  = $this->paper === 'a3' ? 10 : 14;
            $totalWidth = $last ? ($this->paper === 'a3' ? 3.4 : 6.5) : 0;
            $dateWidth  = round((100 - $nameWidth - 3 * $totalWidth) / max(1, \count($dates)), 3);
            // A narrow date column breaks its date after a separator (2026-10- / 24), never inside a number.
            $dateHead = static fn (string $text): string => (string) preg_replace('~([-/.])(?=\d)~u', '$1&#8203;', $e($text));
            ?>
            <table class="data" repeat_header="1">
                <thead>
                    <tr>
                        <th style="width: <?php echo $nameWidth; ?>%;"><?php echo Text::_('COM_BIOTIME_MANAGER_EMPLOYEE'); ?></th>
                        <?php foreach ($dates as $date) : ?>
                            <th style="width: <?php echo $dateWidth; ?>%;"><?php echo $dateHead($f->date($date)); ?><div class="head-sub"><?php echo $e($f->weekday((int) (new DateTimeImmutable($date))->format('N'), true)); ?></div></th>
                        <?php endforeach; ?>
                        <?php if ($last) : ?>
                            <th class="bt-num" style="width: <?php echo $totalWidth; ?>%;"><?php echo Text::_('COM_BIOTIME_COL_LATE'); ?></th>
                            <th class="bt-num" style="width: <?php echo $totalWidth; ?>%;"><?php echo Text::_('COM_BIOTIME_COL_EARLY'); ?></th>
                            <th class="bt-num" style="width: <?php echo $totalWidth; ?>%;"><?php echo Text::_('COM_BIOTIME_MANAGER_TOTAL'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->rows as $row) :
                    $emp = $row['employee'];
                    ?>
                    <tr>
                        <th><?php echo $e($emp['name']); ?><div class="bt-sub"><?php echo $e(implode(' · ', array_filter([$emp['emp_code'], $emp['department']]))); ?></div></th>
                        <?php foreach ($dates as $date) : ?>
                            <?php echo LayoutHelper::render('biotime.managercell', ['day' => $row['days'][$date] ?? null, 'f' => $f, 'blocks' => true]); ?>
                        <?php endforeach; ?>
                        <?php if ($last) : ?>
                            <td class="bt-num"><?php echo $min($row['late']); ?></td>
                            <td class="bt-num"><?php echo $min($row['early']); ?></td>
                            <td class="bt-num"><?php echo $row['late'] + $row['early'] > 0 ? '<span class="bt-late">' . $e($f->duration($row['late'] + $row['early'])) . '</span>' : '<span class="bt-check-none">—</span>'; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
