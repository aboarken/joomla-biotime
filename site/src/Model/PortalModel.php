<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Attendance\DateRange;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Support\Formatter;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseModel;

/**
 * Base of the portal models. Models resolve the identity themselves (memoised per request), so no
 * model returns data without a matched employee even if a controller check were missed. The month
 * or date from the URL only narrows the logged-in employee's own data.
 */
abstract class PortalModel extends BaseModel
{
    private ?Period $period = null;

    public function identity(): Identity
    {
        $user = Factory::getApplication()->getIdentity();

        if ($user === null || $user->guest) {
            throw new NotAllowed(Text::_('COM_BIOTIME_NOT_AUTHORISED'), 403);
        }

        $identity = $this->biotime()->getIdentityResolver()->resolve((int) $user->id, (string) $user->email);

        if (!$identity->isMatched()) {
            throw new NotAllowed(Text::_('COM_BIOTIME_UNMATCHED'), 403);
        }

        return $identity;
    }

    public function settings(): Settings
    {
        return $this->biotime()->getSettingsRepository()->load();
    }

    public function attendance(): AttendanceService
    {
        return $this->biotime()->getAttendanceService();
    }

    /** [year, month] from ?month=YYYY-MM, else the current month in the ZKBioTime server's time zone. */
    public function selectedMonth(): array
    {
        $input = Factory::getApplication()->getInput();
        $value = $input->getString('month', '');

        // The month and year selectors send y + mo.
        $y  = $input->getInt('y', 0);
        $mo = $input->getInt('mo', 0);

        if ($y >= 2000 && $y <= 2100 && $mo >= 1 && $mo <= 12) {
            $value = \sprintf('%04d-%02d', $y, $mo);
        }

        $today   = $this->attendance()->today();
        $current = [(int) substr($today, 0, 4), (int) substr($today, 5, 2)];

        if (preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $value, $m)) {
            $chosen = [(int) $m[1], (int) $m[2]];

            // Between January of the first year in the year lists (2025) and the current month:
            // later months have no punches yet, and are never read from ZKBioTime.
            return match (true) {
                $chosen[0] < \BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService::FIRST_YEAR => [\BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService::FIRST_YEAR, 1],
                $chosen > $current => $current,
                default            => $chosen,
            };
        }

        return $current;
    }

    public function period(): Period
    {
        [$year, $month] = $this->selectedMonth();

        return $this->period ??= $this->attendance()->month($this->identity(), $year, $month);
    }

    /**
     * The school year so far: from 1 August (this year's, or last year's before August) to today,
     * in the ZKBioTime server's time zone. My Dashboard shows it (0.9.0).
     *
     * @return array{0: string, 1: string} [from, to] as YYYY-MM-DD
     */
    public function schoolYear(): array
    {
        $today = $this->attendance()->today();

        return [DateRange::schoolYearStart($today), $today];
    }

    /**
     * From date – to date from ?from=&to= (DD/MM/YYYY, or YYYY-MM-DD in links); null when neither
     * is given (see DateRange::of()).
     *
     * @return array{0: string, 1: string}|null
     */
    public function selectedRange(): ?array
    {
        $input = Factory::getApplication()->getInput();

        return DateRange::of($input->getString('from', ''), $input->getString('to', ''), $this->attendance()->today());
    }

    /** Every day from $from to $to (YYYY-MM-DD, from selectedRange() or schoolYear()). */
    public function rangePeriod(string $from, string $to): Period
    {
        return $this->period ??= $this->attendance()->range($this->identity(), new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    public function formatter(): Formatter
    {
        $settings = $this->settings();

        return new Formatter($settings->dateFormat(), $settings->timeFormat(), static fn (string $key): string => Text::_($key));
    }

    /**
     * The ZKBioTime server's time zone when it differs from the user's Joomla time zone, so pages
     * can label times; null when they are the same. Times are never shifted.
     */
    public function zoneLabel(): ?string
    {
        $server = $this->attendance()->serverZone()->getName();
        $user   = Factory::getApplication()->getIdentity();
        $mine   = (string) ($user?->getParam('timezone', '') ?: Factory::getApplication()->get('offset', 'UTC'));

        return $server !== $mine ? $server : null;
    }

    public function can(string $action): bool
    {
        return (bool) Factory::getApplication()->getIdentity()?->authorise($action, 'com_biotime');
    }

    /**
     * The one log line each page load writes: what was read, hidden or skipped, and why.
     * No personal data: the Joomla user id, counts and reasons only.
     */
    public function logPage(string $view, string $context): void
    {
        $parts = [];

        foreach ($this->attendance()->stats() as $type => $s) {
            $parts[] = $s['error'] !== null
                ? $type . ': error ' . $s['error']
                : ($s['state'] !== 'ok'
                    ? $type . ': ' . $s['state']
                    : \sprintf('%s: %d read%s, %d hidden (other employee), %d skipped (unreadable)', $type, $s['read'], $s['cached'] > 0 ? ' (cached)' : '', $s['hidden'], $s['skipped']));
        }

        $q = \BiotimePortal\Component\Biotime\Administrator\Database\Query::class;

        // How long the page waited for ZKBioTime's database (1.0.2): a slow page with many
        // queries or a long total points at the connection to the BioTime server.
        $this->biotime()->getLogger()->info(\sprintf(
            'Page %s %s, Joomla user %d: %s; database: connect %d ms, %d queries, %d ms; page: %d ms',
            $view,
            $context,
            (int) Factory::getApplication()->getIdentity()?->id,
            $parts !== [] ? implode('; ', $parts) : 'nothing read',
            (int) round($q::$connectSeconds * 1000),
            $q::$count,
            (int) round($q::$seconds * 1000),
            (int) round((microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000)
        ));
    }

    /**
     * A list read from ZKBioTime, kept in the user's cache for the cache time (1.2.8: My Leaves,
     * My Approvals), so the page can open at once with the last data and update itself. A read
     * error is never cached. $stale false: never a copy past the cache time (1.3.7: lists that
     * depend on the user's current ZKBioTime role, e.g. the requests waiting for them).
     *
     * @param  \Closure(): list<array<string, mixed>> $read
     * @return list<array<string, mixed>>
     */
    protected function cachedList(string $key, \Closure $read, bool $stale = true): array
    {
        $cache  = $this->biotime()->getUserCache();
        $cached = $cache->get($this->identity(), $key, $stale);

        if (isset($cached['l']) && \is_array($cached['l'])) {
            return $cached['l'];
        }

        $list = $read();
        $cache->set($this->identity(), $key, ['l' => $list]);

        return $list;
    }

    public function logger(): \Psr\Log\LoggerInterface
    {
        return $this->biotime()->getLogger();
    }

    protected function biotime(): BiotimeComponent
    {
        $component = Factory::getApplication()->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
