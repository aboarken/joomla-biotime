<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Uri\Uri;

/**
 * Every portal page passes through here:
 *  1. login required;
 *  2. a known view and its Joomla permission (403 otherwise);
 *  3. a matched ZKBioTime employee (otherwise only the "could not be matched" page).
 *
 * 1.2.6: the employee's own pages open at once. When the data they were last shown with is older
 * than the cache time (but at most a few days), the page is shown with it and the note "Showing
 * your data from … Updating…" above the title; the page then asks for itself again in the
 * background (bt_fresh=1, never from older data) and replaces its content (media/js/refresh.js).
 */
final class DisplayController extends BaseController
{
    use IdentityGuardTrait;

    /** view => required permission; a list means any one of them. */
    public const VIEWS = [
        'dashboard'  => 'zkb.dashboard.view',
        'attendance' => 'zkb.attendance.view',
        'calendar'   => 'zkb.calendar.view',
        'punches'    => 'zkb.punches.view',
        'day'        => ['zkb.attendance.view', 'zkb.calendar.view', 'zkb.dashboard.view'],
        'manager'    => 'zkb.manager.view',
        'leaves'     => 'zkb.leaves.view',
        'approvals'  => 'zkb.approvals.view',
    ];

    /**
     * Pages that may open with older data while they update (1.2.6; every portal page since
     * 1.2.8). Which employees a manager may see is never taken from an older copy (ManagerModel).
     */
    private const STALE_VIEWS = ['dashboard', 'attendance', 'calendar', 'punches', 'day', 'manager', 'leaves', 'approvals'];

    protected $default_view = 'dashboard';

    public function display($cachable = false, $urlparams = [])
    {
        $this->noCache();

        if (!$this->requireLogin()) {
            return $this;
        }

        $view = $this->input->getCmd('view', $this->default_view);

        if (!isset(self::VIEWS[$view])) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        // Only the Manager Dashboard's report has a page of its own (format=raw, 1.1.2): it is
        // the report without the site template, its cookie notices and pop-ups.
        if ($this->input->getCmd('format', 'html') !== 'html'
            && ($view !== 'manager' || !\in_array($this->input->getCmd('layout', ''), ['print', 'pdf'], true))) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $user    = $this->app->getIdentity();
        $allowed = array_filter((array) self::VIEWS[$view], static fn (string $a): bool => $user->authorise($a, 'com_biotime'));

        if ($allowed === []) {
            throw new NotAllowed(Text::_('COM_BIOTIME_NOT_AUTHORISED'), 403);
        }

        $raw = $this->input->getCmd('format', 'html') !== 'html';

        // The report (PDF or print page, 1.3.7 audit) reads the whole team without the cache and
        // counts against the manager's hourly limit: only from the dashboard's own buttons, which
        // carry the form token, never from a link or image on another page.
        if ($view === 'manager' && \in_array($this->input->getCmd('layout', ''), ['print', 'pdf'], true) && !$this->checkToken('get', false)) {
            throw new NotAllowed(Text::_('COM_BIOTIME_REPORT_EXPIRED'), 403);
        }

        // A normal page load may open with older data; the update itself (bt_fresh) never does.
        $fresh = $this->input->getBool('bt_fresh', false);
        $cache = $this->biotime()->getUserCache();
        $cache->allowStale(!$fresh && \in_array($view, self::STALE_VIEWS, true) && $this->input->getCmd('format', 'html') === 'html');

        try {
            $identity = $this->currentIdentity();
        } catch (SourceException $e) {
            // The report file or page has no "could not be matched" page of its own (1.3.7).
            if ($raw) {
                throw new NotAllowed(Text::_('COM_BIOTIME_NOT_AUTHORISED'), 403);
            }

            $this->input->set('view', 'unmatched');
            $this->input->set('layout', 'unavailable');
            $this->input->set('kind', $e->getKind());

            return parent::display(false, $urlparams);
        }

        if (!$identity->isMatched()) {
            if ($raw) {
                throw new NotAllowed(Text::_('COM_BIOTIME_NOT_AUTHORISED'), 403);
            }

            $this->input->set('view', 'unmatched');
            $this->input->set('layout', 'default');
        } elseif ($view !== 'manager' || !\in_array($this->input->getCmd('layout', 'default'), $this->input->getCmd('format', 'html') === 'html' ? ['print'] : ['print', 'pdf'], true)) {
            // Only the layouts the component has (1.0.18): the Manager Dashboard's report is the
            // one layout a request may choose (print; pdf only as the file, format=raw, 1.2.0);
            // anything else is the page itself.
            $this->input->set('layout', 'default');
        }

        $result = parent::display(false, $urlparams);
        $since  = $cache->staleSince();

        if ($since !== null && !$fresh) {
            $this->updateInBackground($since);
        }

        return $result;
    }

    /**
     * The page was shown with older data: it asks for itself again (the component's part only,
     * never from older data) and replaces its content, see media/js/refresh.js.
     */
    private function updateInBackground(int $since): void
    {
        // Path and query of this page on this site only: never a "//host" (protocol-relative) path.
        // Never "//host" or "/\host" (both read as another site by browsers, 1.3.7).
        $page = new Uri('/' . ltrim(Uri::getInstance()->toString(['path', 'query']), '/\\'));
        $page->setVar('bt_fresh', '1');
        $part = clone $page;
        $part->setVar('tmpl', 'component');

        $document = $this->app->getDocument();
        $document->addScriptOptions('com_biotime.refresh', [
            'url'   => $part->toString(['path', 'query']),
            'page'  => $page->toString(['path', 'query']),
            'since' => HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $since), Text::_('DATE_FORMAT_LC2')),
        ]);

        // Without JavaScript nothing would replace the older data: the page reloads at once with
        // current data (that page never opens with older data, so this happens only once).
        $document->addCustomTag('<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($page->toString(['path', 'query']), ENT_QUOTES, 'UTF-8') . '"></noscript>');

        Text::script('COM_BIOTIME_REFRESH_UPDATING');
        Text::script('COM_BIOTIME_REFRESH_FAILED');
        Text::script('COM_BIOTIME_REFRESH_READY');
        Text::script('COM_BIOTIME_REFRESH_RELOAD');

        $assets = $document->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_biotime');
        $assets->useScript('com_biotime.refresh');
    }
}
