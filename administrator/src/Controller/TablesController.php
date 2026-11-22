<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\Query;
use BiotimePortal\Component\Biotime\Administrator\Database\TableCatalog;
use BiotimePortal\Component\Biotime\Administrator\Database\TableDiagnostics;
use BiotimePortal\Component\Biotime\Administrator\Database\TableExplorer;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/**
 * Find Tables (1.3.0): list the tables of ZKBioTime's database, check one table (columns, keys,
 * how often each column is filled, the values of code columns) and run Diagnostics. Every task
 * needs POST, a valid CSRF token and core.admin; only SELECT statements are sent, with the
 * portal's read-only database user. Results are kept in the administrator's session only.
 */
final class TablesController extends BaseController
{
    /**
     * Pages are shown only through DisplayController, which requires core.admin. Joomla also
     * offers every controller's inherited display() as a task (task=tables.display), which would
     * skip that check, so it is refused here.
     */
    public function display($cachable = false, $urlparams = [])
    {
        throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }

    public const STATE_LIST  = 'com_biotime.tables.list';
    public const STATE_CHECK = 'com_biotime.tables.check';
    public const STATE_DIAG  = 'com_biotime.tables.diagnostics';

    private const URL = 'index.php?option=com_biotime&view=tables';

    /** Table lists, table checks and Diagnostics per administrator and hour. */
    private const PER_HOUR = 60;

    private const ACTION = 'tables.explore';

    /** Every table the database user can see: readable or not, about how many rows. */
    public function find(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        try {
            $tables = (new TableExplorer($this->connect()))->tables();
        } catch (DbException $e) {
            $this->failed($e, 'list');

            return;
        }

        $this->app->setUserState(self::STATE_LIST, ['tables' => $tables, 'at' => time()]);
        $readable = \count(array_filter($tables, static fn (array $t): bool => $t['readable']));
        $this->audit(AuditLogInterface::RESULT_OK, 'list ' . $readable . '/' . \count($tables));
        $this->app->enqueueMessage(Text::sprintf('COM_BIOTIME_TABLES_FOUND', \count($tables), $readable), 'success');
        $this->setRedirect(Route::_(self::URL, false));
    }

    /** One table: columns, keys, links, how often filled, code values. */
    public function check(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        $typed = mb_substr(trim($this->input->post->getString('table', '')), 0, 128);
        $name  = TableCatalog::normalize($typed);

        if ($name === null) {
            $this->app->setUserState(self::STATE_CHECK, ['table' => $typed, 'result' => null]);
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_TABLES_ERR_NAME'), 'warning');
            $this->setRedirect(Route::_(self::URL . '#bt-check', false));

            return;
        }

        try {
            $result = (new TableExplorer($this->connect()))->describe($name);
        } catch (DbException $e) {
            $this->app->setUserState(self::STATE_CHECK, ['table' => $name, 'result' => null]);
            $this->failed($e, 'check ' . $name, '#bt-check');

            return;
        }

        $this->app->setUserState(self::STATE_CHECK, ['table' => $name, 'result' => $result, 'at' => time()]);

        if ($result === null) {
            $this->audit(AuditLogInterface::RESULT_ERROR, 'check ' . $name . ' not found');
            $this->app->enqueueMessage(Text::sprintf('COM_BIOTIME_TABLES_ERR_NOT_FOUND', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')), 'warning');
        } else {
            $this->audit(AuditLogInterface::RESULT_OK, 'check ' . $name . ' columns ' . \count($result['columns']));
            $this->app->enqueueMessage(
                $result['readable'] ? Text::sprintf('COM_BIOTIME_TABLES_CHECKED', \count($result['columns'])) : Text::_('COM_BIOTIME_TABLES_NOT_READABLE_MSG'),
                $result['readable'] && $result['error'] === null ? 'success' : 'warning'
            );
        }

        $this->setRedirect(Route::_(self::URL . '#bt-check', false));
    }

    /** Diagnostics: every check in one run, as a text report to copy. */
    public function diagnose(): void
    {
        $this->guard();

        if (!$this->allowed()) {
            return;
        }

        $settings = $this->component()->getSettingsRepository()->load();
        $portal   = [
            'Component version'    => $this->version(),
            'Joomla / PHP'         => JVERSION . ' / ' . PHP_VERSION,
            'Database engine'      => $settings->dbEngine() === DbConfig::ENGINE_SQLSRV ? 'SQL Server' : 'PostgreSQL',
            'Encryption (SSL)'     => $settings->get('db_ssl') !== '' ? $settings->get('db_ssl') : '—',
            'Connect via set'      => $settings->dbVia()[0] !== '' ? 'yes' : 'no',
            'Cache (minutes)'      => (string) $settings->cacheMinutes(),
            'Weekend days (ISO)'   => implode(', ', $settings->weekendDays()),
            'ZKBioTime time zone'  => $settings->serverTimezone() !== '' ? $settings->serverTimezone() : '—',
            'Detailed logging'     => $settings->detailedLog() ? 'on' : 'off',
        ];

        try {
            $report = TableDiagnostics::run($this->connect(), $portal);
        } catch (DbException $e) {
            $lines = ['ZKBioTime portal diagnostics', 'FAILED before the first query: ' . $e->getKind() . ($e->getDetail() !== '' ? ' (' . $e->getDetail() . ')' : ''), ''];

            foreach ($portal as $label => $value) {
                $lines[] = $label . ': ' . $value;
            }

            $report = implode("\n", $lines) . "\n";
        }

        $this->app->setUserState(self::STATE_DIAG, ['report' => $report, 'at' => time()]);
        $this->audit(AuditLogInterface::RESULT_OK, 'diagnostics');
        $this->app->enqueueMessage(Text::_('COM_BIOTIME_DIAG_DONE'), 'success');
        $this->setRedirect(Route::_(self::URL . '#bt-diag', false));
    }

    /**
     * A connection with the saved settings (the same read-only user as the portal pages).
     *
     * @throws DbException
     */
    private function connect(): Query
    {
        try {
            $vault = $this->component()->getKeyVault();
        } catch (KeyVaultException $e) {
            throw new DbException(DbException::NOT_CONFIGURED, 'No encryption secret for the database password', '', $e);
        }

        return (new DbConnector())->connect($this->component()->getSettingsRepository()->load()->dbConfig($vault));
    }

    private function failed(DbException $e, string $what, string $anchor = ''): void
    {
        $this->audit($e->getKind() === DbException::NOT_CONFIGURED ? AuditLogInterface::RESULT_DENIED : AuditLogInterface::RESULT_ERROR, $what . ' ' . $e->getKind());
        $this->component()->getLogger()->warning('Find Tables: ' . $what . ': ' . $e->getKind() . ($e->getDetail() !== '' ? ' (' . $e->getDetail() . ')' : ''));
        $this->app->enqueueMessage(Text::_($e->getLanguageKey()), 'error');
        $this->setRedirect(Route::_(self::URL . $anchor, false));
    }

    private function version(): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('manifest_cache'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_biotime'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
        $cache = json_decode((string) $db->setQuery($query)->loadResult(), true);

        return \is_array($cache) && \is_string($cache['version'] ?? null) ? $cache['version'] : '—';
    }

    private function allowed(): bool
    {
        if ($this->component()->getRateLimiter()->allow($this->actorId(), self::ACTION, self::PER_HOUR)) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('COM_BIOTIME_TABLES_TOO_MANY'), 'warning');
        $this->setRedirect(Route::_(self::URL, false));

        return false;
    }

    private function audit(string $result, string $detail): void
    {
        $this->component()->getAuditLog()->record($this->actorId(), self::ACTION, $result, mb_substr($detail, 0, 255), $this->actorId());
    }

    private function guard(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_biotime')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function actorId(): int
    {
        return (int) $this->app->getIdentity()->id;
    }

    private function component(): BiotimeComponent
    {
        $component = $this->app->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
