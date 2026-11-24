<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Model\IntegrationsettingsModel;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;

/**
 * Integration Settings tasks. Every task needs POST, a valid CSRF token and core.admin.
 */
final class SettingsController extends BaseController
{
    public const STATE_KEY = 'com_biotime.edit.settings.data';

    /** The last Test Database report (names only), kept in the administrator's session. */
    public const STATE_DB = 'com_biotime.settings.database';

    private const URL = 'index.php?option=com_biotime&view=integrationsettings';

    /** Database tests per administrator and hour. */
    private const TESTS_PER_HOUR = 20;

    public function save(): void
    {
        $this->guard();

        $data = $this->input->post->get('jform', [], 'array');

        try {
            $changed = $this->model()->save($data);
            $this->app->setUserState(self::STATE_KEY, null);
            // Which settings changed (1.3.7 audit): names only, never a value.
            $this->component()->getAuditLog()->record($this->actorId(), 'settings.save', AuditLogInterface::RESULT_OK, mb_substr('changed: ' . ($changed === [] ? 'none' : implode(',', $changed)), 0, 255), $this->actorId());
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_SETTINGS_SAVED'), 'success');
        } catch (ValidationFailed $e) {
            // Keep the entered values for the form, but never the password.
            unset($data['db_password']);
            $this->app->setUserState(self::STATE_KEY, $data);
            $this->component()->getAuditLog()->record($this->actorId(), 'settings.save', AuditLogInterface::RESULT_DENIED, 'invalid', $this->actorId());

            foreach ($e->getKeys() as $key) {
                $this->app->enqueueMessage(Text::_($key), 'error');
            }
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    /**
     * Connect to ZKBioTime's database and list what the portal's user can see (names only).
     */
    public function testDatabase(): void
    {
        $this->guard();

        if (!$this->allowed('settings.testdb')) {
            return;
        }

        $audit = $this->component()->getAuditLog();

        try {
            $report = $this->model()->testDatabase();
            $this->app->setUserState(self::STATE_DB, $report + ['error' => null, 'detail' => '']);
            $audit->record($this->actorId(), 'settings.testdb', AuditLogInterface::RESULT_OK, $report['engine'] . ($report['safe'] ? ' safe' : ' not-read-only'), $this->actorId());
            $this->app->enqueueMessage(Text::sprintf('COM_BIOTIME_DB_TEST_OK', $report['elapsed_ms']), $report['safe'] ? 'success' : 'warning');
        } catch (DbException $e) {
            $this->app->setUserState(self::STATE_DB, ['error' => $e->getKind(), 'detail' => $e->getDetail(), 'at' => time()]);
            $audit->record($this->actorId(), 'settings.testdb', $e->getKind() === DbException::NOT_CONFIGURED ? AuditLogInterface::RESULT_DENIED : AuditLogInterface::RESULT_ERROR, $e->getKind(), $this->actorId());
            $this->component()->getLogger()->warning('Test Database: ' . $e->getKind() . ($e->getDetail() !== '' ? ' (' . $e->getDetail() . ')' : ''));
            $this->app->enqueueMessage(Text::_($e->getLanguageKey()), 'error');
        }

        $this->setRedirect(Route::_(self::URL . '#biotime-db-report', false));
    }

    private function allowed(string $action): bool
    {
        if ($this->component()->getRateLimiter()->allow($this->actorId(), $action, self::TESTS_PER_HOUR)) {
            return true;
        }

        $this->app->enqueueMessage(Text::_('COM_BIOTIME_ERROR_TOO_MANY_TESTS'), 'warning');
        $this->setRedirect(Route::_(self::URL, false));

        return false;
    }

    private function guard(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_biotime')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function model(): IntegrationsettingsModel
    {
        /** @var IntegrationsettingsModel $model */
        $model = $this->getModel('Integrationsettings', 'Administrator', ['ignore_request' => true]);

        return $model;
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
