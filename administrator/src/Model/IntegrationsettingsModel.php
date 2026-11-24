<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;
use Joomla\Database\DatabaseInterface;
use BiotimePortal\Component\Biotime\Administrator\Controller\SettingsController;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\SchemaInspector;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsValidator;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;

final class IntegrationsettingsModel extends FormModel
{
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_biotime.settings', 'settings', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $state = Factory::getApplication()->getUserState(SettingsController::STATE_KEY, []);
        $data  = \is_array($state) && $state !== [] ? $state : $this->settings()->toFormData();

        // Secret fields are always empty: stored secrets are never sent back to the browser.
        $data['db_password']       = '';
        $data['clear_db_password'] = '0';

        return $data;
    }

    public function settings(): Settings
    {
        return $this->component()->getSettingsRepository()->load();
    }

    public function secretSource(): string
    {
        return $this->component()->getSecretProvider()->getSource();
    }

    public function secretEnvTooShort(): bool
    {
        return $this->component()->getSecretProvider()->envTooShort();
    }

    /**
     * Validate, encrypt a new database password and store. The raw post is validated by
     * SettingsValidator, so no form filter can alter the password.
     *
     * @param  array<string, mixed>  $data  Raw posted jform data
     *
     * @throws ValidationFailed  carrying language keys
     */
    /**
     * @return list<string> the names of the settings that changed (1.3.7, for the audit log: names
     *                      only, never a value; the password is "db_password")
     */
    public function save(array $data): array
    {
        $component = $this->component();
        $validator = new SettingsValidator(
            static fn (string $plain, string $context): string => $component->getKeyVault()->encrypt($plain, $context)
        );

        $before = $this->settings();
        $values = $validator->validate($data, $before);

        $this->component()->getSettingsRepository()->save($values);

        $changed = [];

        foreach ($values as $name => $value) {
            if ($before->get((string) $name) !== (string) $value) {
                $changed[] = $name === 'db_password_enc' ? 'db_password' : (string) $name;
            }
        }

        // Cached months were read from the old server, or shaped by the old time zone or unit
        // (1.3.8): they are dropped, so nobody is shown them for up to a day (and a week more while
        // a page updates).
        if (array_intersect($changed, ['db_engine', 'db_host', 'db_port', 'db_name', 'db_connect_via', 'server_timezone', 'db_duration_unit']) !== []) {
            try {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                $db->setQuery($db->createQuery()->delete($db->quoteName('#__biotime_cache')))->execute();
            } catch (\Throwable $e) {
                $this->component()->getLogger()->warning('The ZKBioTime cache could not be emptied after a settings change: ' . $e::class);
            }
        }

        return $changed;
    }

    /**
     * Connect to ZKBioTime's database with the saved settings and describe what the portal's user
     * can see: names only, never a row of data (SchemaInspector).
     *
     * @return array<string, mixed>
     *
     * @throws DbException
     */
    public function testDatabase(): array
    {
        try {
            $vault = $this->component()->getKeyVault();
        } catch (KeyVaultException $e) {
            throw new DbException(DbException::NOT_CONFIGURED, 'No encryption secret for the database password', '', $e);
        }

        $config  = $this->settings()->dbConfig($vault);
        $started = hrtime(true);
        $report  = (new SchemaInspector())->inspect((new DbConnector())->connect($config));

        return $report + [
            'engine'     => $config->engine,
            'host'       => $config->host,
            'port'       => $config->port,
            'via'        => $config->viaIp !== '' ? DbConfig::formatAddress($config->viaIp, $config->viaPort ?: $config->port) : '',
            'database'   => $config->database,
            'user'       => $config->user,
            'ssl_mode'   => $config->ssl,
            'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'at'         => time(),
        ];
    }

    private function component(): BiotimeComponent
    {
        $component = Factory::getApplication()->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
