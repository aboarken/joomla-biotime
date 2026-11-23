<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\DayFilter;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Site\Model\ManagerModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use BiotimePortal\Component\Biotime\Site\View\Ui;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Manager Dashboard (1.0.0): the workforce of the manager's department and the departments below
 * it. Filters: department, employee, From – To (today by default). Cards: workforce and the same
 * figures as My Attendance, for everyone shown. Below them one row per employee: a column per
 * date with that day's check-in and check-out, then late in, early out and both together over the
 * period.
 */
class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var list<array{id: int, name: string, parent: ?int, depth: int}> */
    public array $departments = [];

    /** @var list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}> */
    public array $team = [];

    /** @var list<array{id: int, emp_code: string, name: string, department_id: ?int, department: ?string, position: ?string}> */
    public array $employees = [];

    public int $dept = 0;

    public string $emp = '';

    /** Employee ID search (1.0.4). */
    public string $code = '';

    public string $from = '';

    public string $to = '';

    public string $status = '';

    public string $today = '';

    public ?Summary $summary = null;

    /**
     * One row per employee shown (with the status filter: those with at least one matching day):
     * their days by date, and their Late In and Early Out over the period (minutes).
     *
     * @var list<array{employee: array{emp_code: string, name: string, department: ?string}, days: array<string, Day>, late: int, early: int}>
     */
    public array $rows = [];

    /** Days with a check-in (morning punches) and with a check-out (afternoon punches), 1.0.2. */
    public int $morningPunches = 0;

    public int $afternoonPunches = 0;

    /** @var list<string> the period's dates up to today, the table's columns */
    public array $dates = [];

    /** @var array<string, string> part => error kind */
    public array $errors = [];

    /** Why nothing can be shown ('' when it can): 'nodepartment' or a database error kind. */
    public string $problem = '';

    /**
     * More employees match than are read at once (1.0.4): the manager narrows the list with a
     * department, an employee or an Employee ID first. Keeps the page small enough for Joomla
     * (whose plugins scan the whole page) and quick to read.
     */
    public const MAX_EMPLOYEES = TeamLimit::EMPLOYEES;

    /** How many employees this period lets the page read at once (TeamLimit, 1.3.6). */
    public int $maxEmployees = self::MAX_EMPLOYEES;

    /** Employees per page of the table (1.0.4). */
    public const PAGE_SIZE = 50;

    /** How many employees match when there are too many to read (MAX_EMPLOYEES); 0 otherwise. */
    public int $tooMany = 0;

    public int $page = 1;

    /** Employees in the table on every page together. */
    public int $rowCount = 0;

    public int $pages = 1;

    /** Export PDF (1.0.11): the report has every row, the letterhead and paper-sized tables. */
    public bool $report = false;

    /**
     * The report's date columns, in tables that fit the paper (1.0.11, ReportColumns).
     *
     * @var list<list<string>>
     */
    public array $dateChunks = [];

    /** The report's paper (1.0.16): 'a4' or 'a3', always landscape (ReportColumns). */
    public string $paper = 'a4';

    /** @var array{logo: string, left: string, right: string, footer: list<string>, color: string} */
    public array $letterhead = ['logo' => '', 'left' => '', 'right' => '', 'footer' => [], 'color' => '#1d3f74'];

    /** Who made the report and when (site time zone), for its heading. */
    public string $printedBy = '';

    public string $printedAt = '';

    public function display($tpl = null): void
    {
        /** @var ManagerModel $model */
        $model = $this->getModel();
        $input = Factory::getApplication()->getInput();

        // The report: on a page (print) or as a PDF file made on the server (pdf, 1.2.0).
        $this->report = \in_array($this->getLayout(), ['print', 'pdf'], true);

        $this->status = DayFilter::valid($input->getCmd('status', ''));
        $this->today  = $model->attendance()->today();
        [$this->from, $this->to] = $model->managerRange();
        $this->year  = (int) substr($this->to, 0, 4);
        $this->month = (int) substr($this->to, 5, 2);

        try {
            $this->departments = $model->departments();
            $this->problem     = $this->departments === [] ? 'nodepartment' : '';

            // A PDF report reads every employee without the cache: at most a few an hour (1.0.18).
            if ($this->problem === '' && $this->report && !$model->allowExport()) {
                $this->problem = 'exportlimit';
            }

            if ($this->problem === '') {
                $this->team      = $model->team();
                $this->dept      = $model->selectedDepartment();
                $this->emp       = $model->selectedEmployee();
                $this->code      = $model->searchedCode();
                $this->employees = $model->shownEmployees();

                $this->maxEmployees = TeamLimit::employees($this->from, min($this->to, $this->today));

                if (\count($this->employees) > $this->maxEmployees) {
                    $this->tooMany   = \count($this->employees);
                    $this->employees = [];
                }

                // The page uses the cache (1.2.8); the printed report and the PDF read current data.
                $read         = $model->teamDays($this->employees, $this->from, $this->to, !$this->report);
                $this->errors = $read['errors'];
                $all          = [];

                for ($d = new \DateTimeImmutable($this->from); $d->format('Y-m-d') <= min($this->to, $this->today); $d = $d->modify('+1 day')) {
                    $this->dates[] = $d->format('Y-m-d');
                }

                foreach ($this->employees as $e) {
                    $row     = ['employee' => $e, 'days' => [], 'late' => 0, 'early' => 0];
                    $matches = $this->status === '';

                    foreach ($read['days'][$e['emp_code']] ?? [] as $day) {
                        $all[]                   = $day;
                        $this->morningPunches   += $day->checkIn !== null ? 1 : 0;
                        $this->afternoonPunches += $day->checkOut !== null ? 1 : 0;
                        $row['days'][$day->date] = $day;
                        $row['late']            += $day->late ?? 0;
                        $row['early']           += $day->early ?? 0;
                        $matches                 = $matches || DayFilter::matches($day, $this->status);
                    }

                    if ($matches) {
                        $this->rows[] = $row;
                    }
                }

                $this->summary = Summary::of($all, $this->today);

                // The cards count every employee shown; the table shows PAGE_SIZE of them at a time.
                $this->rowCount = \count($this->rows);

                if ($this->report) {
                    // The report has every row; its dates are cut into tables that fit the paper.
                    $this->paper      = ReportColumns::paper($input->getCmd('paper', 'a4'));
                    $this->dateChunks = ReportColumns::of($this->dates, $this->paper);
                } else {
                    $this->pages = max(1, (int) ceil($this->rowCount / self::PAGE_SIZE));
                    $this->page  = min(max(1, $input->getInt('page', 1)), $this->pages);
                    $this->rows  = \array_slice($this->rows, ($this->page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
                }
            }
        } catch (DbException $e) {
            $this->problem = $e->getKind();
            $model->logger()->warning('Manager Dashboard: the team could not be read (' . $e->getKind() . ')');
        }

        $this->preparePage($model, 'manager', 'COM_BIOTIME_MANAGER_TITLE');

        if ($this->report) {
            $this->prepareReport($model);
        }

        $model->logPage($this->report ? 'manager-pdf' : 'manager', $this->from . '..' . $this->to . ' employees=' . \count($this->employees) . ($this->status !== '' ? ' status=' . $this->status : ''));

        parent::display($tpl);
    }

    /**
     * The report's paper (A4 or A3, always landscape), its main colour and the room kept for its
     * footer (1.1.2: one place for the page inside the template and the report's own page). The
     * colour is checked as #rrggbb when read (Settings::letterhead()).
     */
    public function reportCss(): string
    {
        $footer = $this->letterhead['footer'];

        return '@page { size: ' . ($this->paper === 'a3' ? 'A3' : 'A4') . ' landscape; }'
            . ' .com-biotime.bt-report { --bt-report: ' . $this->letterhead['color'] . '; --bt-foot-h: ' . ($footer === [] ? 0 : 4 + 4.2 * min(\count($footer), 6)) . 'mm; }';
    }

    private function prepareReport(ManagerModel $model): void
    {
        $app = Factory::getApplication();
        $now = Factory::getDate('now', $app->get('offset', 'UTC'));

        $this->letterhead = $model->settings()->letterhead();
        $this->printedBy  = (string) $app->getIdentity()?->name;
        $this->printedAt  = $this->f->date($now->format('Y-m-d', true)) . ' ' . $this->f->time($now->format('H:i', true));

        // The file name the browser offers when saving as PDF.
        $this->getDocument()->setTitle(Text::_('COM_BIOTIME_REPORT_FILE') . ' ' . $this->from . ($this->to !== $this->from ? ' - ' . $this->to : ''));
        $this->getDocument()->getWebAssetManager()->useScript('com_biotime.report');
    }

    /**
     * The status filters (value => label), as in the Status list (1.0.11: shared with the PDF).
     *
     * @return array<string, string>
     */
    public function statusLabels(): array
    {
        return [
            ''                 => Text::_('COM_BIOTIME_FILTER_ALL'),
            'attended'         => Text::_('COM_BIOTIME_FILTER_ATTENDED'),
            'absent'           => Text::_('COM_BIOTIME_STATUS_ABSENT'),
            'leave'            => Text::_('COM_BIOTIME_CARD_LEAVES'),
            'missingmorning'   => Text::_('COM_BIOTIME_CARD_MISSING_MORNING'),
            'missingafternoon' => Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'),
            'late'             => Text::_('COM_BIOTIME_FILTER_LATE'),
            'early'            => Text::_('COM_BIOTIME_FILTER_EARLY'),
            'holiday'          => Text::_('COM_BIOTIME_STATUS_HOLIDAY'),
            'weekend'          => Text::_('COM_BIOTIME_STATUS_WEEKEND'),
            'weekendworked'    => Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'),
        ];
    }

    /**
     * The cards: [label, value html, tone, status filter ('' = none, '*' = every day)]; [] when
     * nothing was read (1.0.11: shared with the PDF).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function cards(): array
    {
        $s = $this->summary;
        $f = $this->f;

        if ($s === null) {
            return [];
        }

        return [
            // Workforce is the selected card by default and clears the status filter (1.0.10); All Days
            // counts the days of everyone shown.
            [Text::_('COM_BIOTIME_MANAGER_WORKFORCE'), (string) \count($this->employees), Ui::cardTone('COM_BIOTIME_MANAGER_WORKFORCE'), '*'],
            [Text::_('COM_BIOTIME_CARD_ALL_DAYS'), (string) $s->allDays, Ui::cardTone('COM_BIOTIME_CARD_ALL_DAYS'), ''],
            [Text::_('COM_BIOTIME_CARD_PRESENT_DAYS'), $s->presentDays . ' <small>/ ' . $s->workingDays . '</small>', Ui::cardTone('COM_BIOTIME_CARD_PRESENT_DAYS'), 'attended'],
            [Text::_('COM_BIOTIME_CARD_ABSENT_DAYS'), (string) $s->absentDays, Ui::cardTone('COM_BIOTIME_CARD_ABSENT_DAYS'), 'absent'],
            [Text::_('COM_BIOTIME_CARD_LEAVES'), (string) $s->leaveDays, Ui::cardTone('COM_BIOTIME_CARD_LEAVES'), 'leave'],
            [Text::_('COM_BIOTIME_CARD_HOLIDAYS'), (string) $s->holidayDays, Ui::cardTone('COM_BIOTIME_CARD_HOLIDAYS'), 'holiday'],
            [Text::_('COM_BIOTIME_CARD_WEEKENDS'), (string) $s->offDays, Ui::cardTone('COM_BIOTIME_CARD_WEEKENDS'), 'weekend'],
            [Text::_('COM_BIOTIME_CARD_WEEKEND_WORKED'), (string) $s->weekendWorked, Ui::cardTone('COM_BIOTIME_CARD_WEEKEND_WORKED'), 'weekendworked'],
            [Text::_('COM_BIOTIME_CARD_RATE'), $s->rate === null ? '—' : $s->rate . ' <small>%</small>', Ui::cardTone('COM_BIOTIME_CARD_RATE'), ''],
            [Text::_('COM_BIOTIME_CARD_MISSING_MORNING'), (string) $s->missingMorningDays, Ui::cardTone('COM_BIOTIME_CARD_MISSING_MORNING'), 'missingmorning'],
            [Text::_('COM_BIOTIME_CARD_MISSING_AFTERNOON'), (string) $s->missingAfternoonDays, Ui::cardTone('COM_BIOTIME_CARD_MISSING_AFTERNOON'), 'missingafternoon'],
            // Days with a check-in (morning punch) and with a check-out (afternoon punch), 1.0.2.
            [Text::_('COM_BIOTIME_MANAGER_MORNING_PUNCHES'), (string) $this->morningPunches, Ui::cardTone('COM_BIOTIME_MANAGER_MORNING_PUNCHES'), ''],
            [Text::_('COM_BIOTIME_MANAGER_AFTERNOON_PUNCHES'), (string) $this->afternoonPunches, Ui::cardTone('COM_BIOTIME_MANAGER_AFTERNOON_PUNCHES'), ''],
            [Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $this->escape($f->time($s->avgCheckIn)), Ui::cardTone('COM_BIOTIME_CARD_AVG_CHECK_IN'), ''],
            [Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $this->escape($f->time($s->avgCheckOut)), Ui::cardTone('COM_BIOTIME_CARD_AVG_CHECK_OUT'), ''],
            [Text::_('COM_BIOTIME_CARD_LATE_TOTAL'), $this->escape($f->duration($s->lateMinutes)), Ui::cardTone('COM_BIOTIME_CARD_LATE_TOTAL'), 'late'],
            [Text::_('COM_BIOTIME_CARD_EARLY_TOTAL'), $this->escape($f->duration($s->earlyMinutes)), Ui::cardTone('COM_BIOTIME_CARD_EARLY_TOTAL'), 'early'],
            [Text::_('COM_BIOTIME_CARD_LATE_EARLY'), $this->escape($f->duration($s->lateEarlyMinutes())), Ui::cardTone('COM_BIOTIME_CARD_LATE_EARLY'), ''],
            [Text::_('COM_BIOTIME_CARD_WORKED'), $this->escape($f->duration($s->workedMinutes)), Ui::cardTone('COM_BIOTIME_CARD_WORKED'), ''],
            [Text::_('COM_BIOTIME_CARD_OVERTIME'), $this->escape($f->duration($s->overtimeMinutes)), Ui::cardTone('COM_BIOTIME_CARD_OVERTIME'), ''],
        ];
    }

    /** This page with the same filters, changed by $changes ('' removes one). */
    public function filterUrl(array $changes = []): string
    {
        $query = array_filter(array_merge([
            'dept'   => $this->dept > 0 ? (string) $this->dept : '',
            'emp'    => $this->emp,
            'code'   => $this->code,
            'from'   => $this->from,
            'to'     => $this->to,
            'status' => $this->status,
        ], $changes), static fn ($v): bool => $v !== '' && $v !== null);

        return $this->url('view=manager' . ($query !== [] ? '&' . http_build_query($query) : ''));
    }
}
