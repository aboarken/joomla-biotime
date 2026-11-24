<?php

/**
 * Installer script for com_biotime.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                private const MIN_PHP    = '8.3.0';
                private const MIN_JOOMLA = '6.0.0';

                /** The version installed before this update ('' on a new install or when unknown, 1.3.7). */
                private string $fromVersion = '';

                /**
                 * Files and folders shipped by older versions that no longer exist.
                 * Joomla's upgrade does not delete them, so they are removed here.
                 * Paths are relative to the component's site / administrator folder.
                 * Add an entry whenever a release removes a file or feature.
                 */
                private const OBSOLETE_SITE = [
                    // 0.4.0: My Schedules, Attendance Reports and My Profile removed.
                    'src/Controller/ReportController.php',
                    'src/Model/SchedulesModel.php',
                    'src/Model/ReportsModel.php',
                    'src/Model/ProfileModel.php',
                    'src/View/Schedules',
                    'src/View/Reports',
                    'src/View/Profile',
                    'tmpl/schedules',
                    'tmpl/reports',
                    'tmpl/profile',
                    // 0.4.2: Biometric Devices replaced by My Punches.
                    'src/Model/DevicesModel.php',
                    'src/View/Devices',
                    'tmpl/devices',
                ];

                private const OBSOLETE_ADMIN = [
                    // 0.4.0: PDF/Excel exports and the profile service went with the removed pages.
                    'src/Export',
                    'src/Profile',
                    'lib',
                    // 0.4.2: the device list went with Biometric Devices.
                    'src/Devices',
                    // 0.4.4: leave is no longer read.
                    'src/Attendance/LeaveRecord.php',
                    // 0.4.6: replaced by CurlTransport (one connection reused per page load).
                    'src/Api/Transport/JoomlaHttpTransport.php',
                    // 0.4.7: only the tests used it (the portal caches in the database).
                    'src/Cache/StoredUserCache.php',
                    // 1.0.0: the REST API is no longer used; every page reads ZKBioTime's database.
                    'src/Api',
                    'src/Discovery',
                    'src/Service/ZkbClientFactory.php',
                    'src/Settings/DbTokenStore.php',
                    'src/Attendance/SourceConfig.php',
                    'src/Controller/EndpointsController.php',
                    'src/View/Endpoints',
                    'tmpl/endpoints',
                ];

                /** Media files (media/com_biotime) of removed features. */
                private const OBSOLETE_MEDIA = [
                    // 1.0.0: only Find Endpoints used it. 1.3.4: again unused (Find Tables' Copy report
                    // was removed); this also deletes the copy that 1.3.0 – 1.3.3 installed.
                    'js/admin.js',
                ];

                /** Settings rows of removed options. */
                private const OBSOLETE_SETTINGS = [
                    'module_exports', 'export_per_hour', 'report_max_days',
                    // 1.0.18: the Charts switch, unused since 0.9.0.
                    'module_charts',
                    // 0.4.4: the Leave Source and the Leave information switch.
                    'module_leave', 'leave_path', 'leave_param_emp', 'leave_param_from', 'leave_param_to',
                    'leave_date_format', 'leave_extra', 'leave_fields', 'leave_duration_unit', 'leave_status_map',
                    // 1.0.0: the REST API connection (with its encrypted password, token and cached
                    // token), the Daily Attendance Source and the choice of data source.
                    'server_url', 'server_port', 'auth_method', 'api_username', 'api_password_enc', 'api_token_enc',
                    'verify_tls', 'ca_bundle', 'tls_pin', 'connect_via', 'page_size', 'max_pages',
                    'token_cache_enc', 'token_cache_renew_at', 'data_source',
                    'daily_path', 'daily_param_emp', 'daily_param_from', 'daily_param_to', 'daily_date_format',
                    'daily_extra', 'daily_fields', 'daily_duration_unit', 'daily_status_map',
                ];

                /** Front-end views that no longer exist; menu items pointing to them are trashed. */
                private const OBSOLETE_VIEWS = ['schedules', 'reports', 'profile'];

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    // The PDF report's temporary files (1.3.7: the folder was left behind).
                    try {
                        $tmp = rtrim((string) Factory::getApplication()->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\') . '/com_biotime_pdf';

                        if (is_dir($tmp)) {
                            Folder::delete($tmp);
                        }
                    } catch (\Throwable) {
                        // Not critical: Joomla's tmp folder can be emptied by hand.
                    }

                    // The component's own log (1.3.8): it holds user ids and database error text.
                    try {
                        $log = rtrim((string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs'), '/\\') . '/com_biotime.php';

                        if (is_file($log)) {
                            File::delete($log);
                        }
                    } catch (\Throwable) {
                        // Not critical.
                    }

                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $app = Factory::getApplication();

                    if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_MIN_PHP', self::MIN_PHP), 'error');

                        return false;
                    }

                    if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_MIN_JOOMLA', self::MIN_JOOMLA), 'error');

                        return false;
                    }

                    if ($type === 'update') {
                        $this->fromVersion = $this->installedVersion();
                    }

                    if (!\extension_loaded('openssl') || !\in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                        $app->enqueueMessage(Text::_('COM_BIOTIME_INSTALL_NO_OPENSSL'), 'error');

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'install') {
                        Factory::getApplication()->enqueueMessage(Text::_('COM_BIOTIME_INSTALL_NEXT_STEPS'), 'info');
                    }

                    if ($type !== 'update') {
                        return true;
                    }

                    $removed = $this->removeObsoleteFiles(JPATH_SITE . '/components/com_biotime', self::OBSOLETE_SITE)
                        + $this->removeObsoleteFiles(JPATH_ADMINISTRATOR . '/components/com_biotime', self::OBSOLETE_ADMIN)
                        + $this->removeObsoleteFiles(JPATH_ROOT . '/media/com_biotime', self::OBSOLETE_MEDIA);

                    $db = Factory::getContainer()->get(DatabaseInterface::class);

                    $renamed = $this->renameDevicesMenuItems($db);
                    $this->copyDevicesPermission($db);
                    // Once, when coming from before 0.4.2 (1.3.7 audit: it ran on every update and
                    // turned a chosen YYYY-MM-DD back into DD/MM/YYYY).
                    if ($this->fromVersion !== '' && version_compare($this->fromVersion, '0.4.2', '<')) {
                        $this->switchDateFormat($db);
                    }
                    $trashed = $this->trashObsoleteMenuItems($db);
                    $this->deleteObsoleteSettings($db);

                    $app = Factory::getApplication();

                    if ($removed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_CLEANED_FILES', $removed), 'info');
                    }

                    if ($renamed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_RENAMED_MENU', $renamed), 'info');
                    }

                    if ($trashed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_TRASHED_MENU', $trashed), 'warning');
                    }

                    return true;
                }

                /**
                 * @param  list<string>  $paths
                 */
                private function removeObsoleteFiles(string $base, array $paths): int
                {
                    $count = 0;

                    foreach ($paths as $relative) {
                        $path = $base . '/' . $relative;

                        try {
                            if (is_dir($path)) {
                                $count += Folder::delete($path) ? 1 : 0;
                            } elseif (is_file($path)) {
                                $count += File::delete($path) ? 1 : 0;
                            }
                        } catch (\Throwable) {
                            // Leave it; the files are unused and harmless.
                        }
                    }

                    return $count;
                }

                /**
                 * Menu items for removed views would lead to an error page. They are moved to the
                 * trash (not deleted), so an admin can still restore or review them.
                 */
                private function trashObsoleteMenuItems(DatabaseInterface $db): int
                {
                    $count = 0;

                    foreach (self::OBSOLETE_VIEWS as $view) {
                        $link  = 'index.php?option=com_biotime&view=' . $view;
                        $like  = $link . '&%';
                        $query = $db->createQuery()
                            ->update($db->quoteName('#__menu'))
                            ->set($db->quoteName('published') . ' = -2')
                            ->where($db->quoteName('client_id') . ' = 0')
                            ->where($db->quoteName('published') . ' <> -2')
                            ->where('(' . $db->quoteName('link') . ' = :link OR ' . $db->quoteName('link') . ' LIKE :like)')
                            ->bind(':link', $link)
                            ->bind(':like', $like);

                        try {
                            $db->setQuery($query)->execute();
                            $count += (int) $db->getAffectedRows();
                        } catch (\Throwable) {
                            // Not critical.
                        }
                    }

                    return $count;
                }

                /**
                 * 0.4.2: Biometric Devices became My Punches. Its menu items keep working: their
                 * link points to the new page, and the title changes where it was the default one.
                 */
                private function renameDevicesMenuItems(DatabaseInterface $db): int
                {
                    $old = 'index.php?option=com_biotime&view=devices';
                    $new = 'index.php?option=com_biotime&view=punches';

                    try {
                        $db->setQuery(
                            $db->createQuery()
                                ->update($db->quoteName('#__menu'))
                                ->set($db->quoteName('title') . ' = ' . $db->quote('My Punches'))
                                ->where($db->quoteName('client_id') . ' = 0')
                                ->where($db->quoteName('link') . ' = :link')
                                ->where($db->quoteName('title') . ' = ' . $db->quote('Biometric Devices'))
                                ->bind(':link', $old)
                        )->execute();

                        $db->setQuery(
                            $db->createQuery()
                                ->update($db->quoteName('#__menu'))
                                ->set($db->quoteName('link') . ' = :new')
                                ->where($db->quoteName('client_id') . ' = 0')
                                ->where($db->quoteName('link') . ' = :old')
                                ->bind(':new', $new)
                                ->bind(':old', $old)
                        )->execute();

                        return (int) $db->getAffectedRows();
                    } catch (\Throwable) {
                        return 0;
                    }
                }

                /** 0.4.2: groups allowed to view Biometric Devices may view My Punches. */
                private function copyDevicesPermission(DatabaseInterface $db): void
                {
                    try {
                        $rules = (string) $db->setQuery(
                            $db->createQuery()
                                ->select($db->quoteName('rules'))
                                ->from($db->quoteName('#__assets'))
                                ->where($db->quoteName('name') . ' = ' . $db->quote('com_biotime'))
                        )->loadResult();

                        $data = json_decode($rules !== '' ? $rules : '{}', true);

                        if (!\is_array($data) || !isset($data['zkb.devices.view']) || isset($data['zkb.punches.view'])) {
                            return;
                        }

                        $data['zkb.punches.view'] = $data['zkb.devices.view'];
                        unset($data['zkb.devices.view']);
                        $json = json_encode($data);

                        $db->setQuery(
                            $db->createQuery()
                                ->update($db->quoteName('#__assets'))
                                ->set($db->quoteName('rules') . ' = :rules')
                                ->where($db->quoteName('name') . ' = ' . $db->quote('com_biotime'))
                                ->bind(':rules', $json)
                        )->execute();
                    } catch (\Throwable) {
                        // Not critical: the permission can be set again under Options.
                    }
                }

                /** The installed com_biotime version, from Joomla's extension record; '' when unknown. */
                private function installedVersion(): string
                {
                    try {
                        $db    = Factory::getContainer()->get(DatabaseInterface::class);
                        $cache = $db->setQuery(
                            $db->createQuery()
                                ->select($db->quoteName('manifest_cache'))
                                ->from($db->quoteName('#__extensions'))
                                ->where($db->quoteName('element') . ' = ' . $db->quote('com_biotime'))
                                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                        )->loadResult();
                        $version = json_decode((string) $cache, true)['version'] ?? '';

                        return \is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : '';
                    } catch (\Throwable) {
                        return '';
                    }
                }

                /** 0.4.2: dates are shown as DD/MM/YYYY; sites still on the old default switch to it. */
                private function switchDateFormat(DatabaseInterface $db): void
                {
                    try {
                        $db->setQuery(
                            $db->createQuery()
                                ->update($db->quoteName('#__biotime_settings'))
                                ->set($db->quoteName('value') . ' = ' . $db->quote('d/m/Y'))
                                ->where($db->quoteName('name') . ' = ' . $db->quote('date_format'))
                                ->where($db->quoteName('value') . ' = ' . $db->quote('Y-m-d'))
                        )->execute();
                    } catch (\Throwable) {
                        // Not critical: the format can be chosen in Integration Settings.
                    }
                }

                private function deleteObsoleteSettings(DatabaseInterface $db): void
                {
                    if (self::OBSOLETE_SETTINGS === []) {
                        return;
                    }

                    $query = $db->createQuery()
                        ->delete($db->quoteName('#__biotime_settings'))
                        ->whereIn($db->quoteName('name'), self::OBSOLETE_SETTINGS, ParameterType::STRING);

                    try {
                        $db->setQuery($query)->execute();
                    } catch (\Throwable) {
                        // Not critical: unknown settings rows are ignored by the component.
                    }
                }
            }
        );
    }
};
