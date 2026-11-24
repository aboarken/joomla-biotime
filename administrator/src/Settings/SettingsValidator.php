<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;

/**
 * Turns the posted Integration Settings form into the values to store. Pure: no Joomla, no
 * database, so every rule is covered by unit tests.
 *
 * - A new database password is encrypted; an empty field keeps the stored one.
 * - "Remove stored database password" deletes it.
 * - All errors are collected and returned as language keys.
 */
final class SettingsValidator
{
    /** @var \Closure(string): bool */
    private \Closure $fileExists;

    /**
     * @param  \Closure(string, string): string  $encrypt     (plaintext, context) => ciphertext; may throw KeyVaultException
     * @param  (\Closure(string): bool)|null     $fileExists  Injected for tests
     */
    public function __construct(private readonly \Closure $encrypt, ?\Closure $fileExists = null)
    {
        $this->fileExists = $fileExists ?? static fn (string $path): bool => is_file($path) && is_readable($path);
    }

    /**
     * @param  array<string, mixed>  $input  Raw posted values (jform)
     *
     * @return array<string, string>  Values to store
     *
     * @throws ValidationFailed
     */
    public function validate(array $input, Settings $current): array
    {
        $errors = [];
        $values = [];

        // Timeouts of the database connection and of each query.
        $connect = self::int($input['connect_timeout'] ?? null);
        $total   = self::int($input['timeout'] ?? null);

        if ($connect === null || $connect < 1 || $connect > 60 || $total === null || $total < 1 || $total > 300) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TIMEOUT_RANGE';
        } elseif ($connect > $total) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TIMEOUTS';
        }

        $values['connect_timeout'] = (string) ($connect ?? 5);
        $values['timeout']         = (string) ($total ?? 30);

        // System.
        $values['detailed_log'] = self::flag($input['detailed_log'] ?? '1') ? '1' : '0';

        $values['cache_minutes'] = (string) max(0, min(Settings::MAX_CACHE_MINUTES, self::int($input['cache_minutes'] ?? null) ?? Settings::DEFAULT_CACHE_MINUTES));

        if (self::int($input['cache_minutes'] ?? null) === null || (int) $input['cache_minutes'] > Settings::MAX_CACHE_MINUTES) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_CACHE';
        }

        $values['date_format'] = \in_array(self::str($input['date_format'] ?? ''), Settings::DATE_FORMATS, true) ? self::str($input['date_format']) : 'd/m/Y';
        $values['time_format'] = \in_array(self::str($input['time_format'] ?? ''), Settings::TIME_FORMATS, true) ? self::str($input['time_format']) : 'H:i';

        $zone = trim(self::str($input['server_timezone'] ?? ''));

        if ($zone !== '' && !\in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TIMEZONE';
            $zone     = '';
        }

        $values['server_timezone'] = $zone;

        // Letterhead of the reports (1.0.11). The Media field adds "#joomlaImage://…" to the path.
        $logo = trim(explode('#', self::str($input['report_logo'] ?? ''), 2)[0]);

        if ($logo !== '' && !Settings::isReportLogo($logo)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_REPORT_LOGO';
            $logo     = '';
        }

        $values['report_logo'] = $logo;

        foreach (['report_header_left', 'report_header_right'] as $name) {
            $values[$name] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(self::str($input[$name] ?? '')))), 0, 200);
        }

        $footer = array_slice(array_filter(array_map(
            static fn (string $l): string => mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', $l)), 0, 300),
            explode("\n", str_replace("\r", '', strip_tags(self::str($input['report_footer'] ?? ''))))
        ), static fn (string $l): bool => $l !== ''), 0, 6);
        $values['report_footer'] = implode("\n", $footer);

        $color = strtolower(trim(self::str($input['report_color'] ?? '')));

        if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_REPORT_COLOR';
        }

        $values['report_color'] = preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : Settings::DEFAULTS['report_color'];

        // Weekend days: ISO weekdays 1–7 (none is allowed: every day is then a working day).
        $weekend = $input['weekend_days'] ?? '';
        $weekend = \is_array($weekend) ? array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', $weekend) : self::str($weekend);
        $values['weekend_days'] = implode(',', Settings::parseWeekdays($weekend));

        if (\count(Settings::parseWeekdays($weekend)) === 7) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_WEEKEND';
        }

        // Employee mapping: custom user field name (lower case, as Joomla stores field names).
        $matchField = strtolower(trim(self::str($input['match_field'] ?? '')));

        if ($matchField !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $matchField)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_MATCH_FIELD';
            $matchField = '';
        }

        $values['match_field'] = $matchField;

        // Employee mapping (auto_approve_matches, 1.0.19: off unless chosen).
        foreach (['auto_match', 'auto_approve_matches'] as $flag) {
            $values[$flag] = self::flag($input[$flag] ?? '0') ? '1' : '0';
        }

        $this->database($input, $current, $values, $errors);

        if ($errors !== []) {
            throw new ValidationFailed(array_values(array_unique($errors)));
        }

        return $values;
    }

    /**
     * ZKBioTime's database, which every portal page reads. Off ('' engine): the values are kept as
     * typed, nothing is required. On: every value is checked as DbConfig checks it, the password is required, and
     * a stored password is only kept for the server and database it was entered for.
     *
     * @param  array<string, mixed>   $input
     * @param  array<string, string>  $values
     * @param  list<string>           $errors
     */
    private function database(array $input, Settings $current, array &$values, array &$errors): void
    {
        $text   = static fn (string $name, int $max): string => mb_substr(trim(self::str($input[$name] ?? '')), 0, $max);
        $engine = self::str($input['db_engine'] ?? '');
        $on     = $engine !== '';

        if ($on && !\in_array($engine, DbConfig::ENGINES, true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_ENGINE';
            $engine   = '';
        }

        $default = DbConfig::DEFAULT_PORTS[$engine] ?? DbConfig::DEFAULT_PORTS[DbConfig::ENGINE_PGSQL];
        $server  = $text('db_server', 300);
        $host    = '';
        $port    = $default;

        // Host and port in one field ("db.example.com:7496").
        if ($server !== '') {
            try {
                [$host, $port] = DbConfig::parseAddress($server, $default);
            } catch (\InvalidArgumentException) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER';
            }
        } elseif ($on) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER';
        }

        // Connect via: the IP address (and port) Joomla really connects to, e.g. the firewall's
        // public address, while the certificate is checked against the server's name.
        $via    = $text('db_connect_via', 60);
        $viaOut = '';

        if ($via !== '') {
            try {
                [$viaIp, $viaPort] = DbConfig::parseAddress($via, $port);

                if (filter_var($viaIp, FILTER_VALIDATE_IP) === false) {
                    $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_VIA';
                } elseif ($engine === DbConfig::ENGINE_SQLSRV) {
                    $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_VIA_SQLSRV';
                } else {
                    $viaOut = DbConfig::formatAddress($viaIp, $viaPort);
                }
            } catch (\InvalidArgumentException) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_VIA';
            }
        }
        $name = $text('db_name', 63);
        $user = $text('db_user', 63);
        $ssl  = self::str($input['db_ssl'] ?? DbConfig::SSL_VERIFY);
        $ca   = $text('db_ca', 500);

        if ($name !== '' && !DbConfig::validName($name) || $on && $name === '') {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_NAME';
        }

        if ($user !== '' && !DbConfig::validUser($user) || $on && $user === '') {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_USER';
        } elseif (\in_array(strtolower($user), DbConfig::ADMIN_USERS, true)) {
            // The administrator accounts can change everything: never stored for the portal.
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_ADMIN_USER';
        }

        if (!\in_array($ssl, DbConfig::SSL_MODES, true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_SSL';
            $ssl      = DbConfig::SSL_VERIFY;
        }

        if ($ca !== '' && (!DbConfig::validPath($ca) || !($this->fileExists)($ca))) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_CA';
        } elseif ($on && $engine === DbConfig::ENGINE_PGSQL && $ssl === DbConfig::SSL_VERIFY && $ca === '') {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_CA_REQUIRED';
        }

        $values['db_engine'] = $engine;
        $values['db_host']   = $host;
        $values['db_port']   = (string) $port;
        $values['db_connect_via'] = $viaOut;
        $values['db_name']   = $name;
        $values['db_user']   = $user;
        $values['db_ssl']    = $ssl;
        $values['db_ca']     = $ca;

        // Password: encrypted; an empty field keeps the stored one.
        $password = self::str($input['db_password'] ?? '');
        $has      = $current->hasDbPassword() && !self::flag($input['clear_db_password'] ?? '0');

        if (!$has) {
            $values['db_password_enc'] = '';
        }

        if ($password !== '') {
            if (\strlen($password) > 256 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD';
                $has      = true;
            } else {
                $encrypted = $this->encryptOrFail($password, KeyVault::CONTEXT_DB_PASSWORD, $errors);

                if ($encrypted !== null) {
                    $values['db_password_enc'] = $encrypted;
                    $has                       = true;
                }
            }
        }

        $moved = $current->get('db_host') !== '' && (
            $engine !== $current->get('db_engine') || $host !== $current->get('db_host')
            || $values['db_port'] !== $current->get('db_port') || $viaOut !== $current->get('db_connect_via')
            || $name !== $current->get('db_name')
            || strtolower($user) !== strtolower($current->get('db_user'))
            // A weaker encryption or another CA could expose the stored password (1.0.10).
            || $ssl !== $current->get('db_ssl') || $ca !== $current->get('db_ca')
        );

        // Switching the connection off (1.3.8) keeps the stored password: nothing connects, and the
        // fields that would let the administrator re-enter or clear it are hidden then. Switching
        // it on again counts as a change of engine, so the password is asked for again.
        if ($moved && $engine !== '' && $password === '' && $has && $current->hasDbPassword()) {
            $errors[]                  = 'COM_BIOTIME_SETTINGS_ERR_REENTER_DB_PASSWORD';
            $values['db_password_enc'] = '';
            $has                       = false;
        }

        if ($on && !$has) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD_REQUIRED';
        }

        $unit = self::str($input['db_duration_unit'] ?? 'auto');

        $values['db_duration_unit'] = \in_array($unit, AttendanceReader::UNITS, true) ? $unit : 'auto';
    }

    /**
     * @param  list<string>  $errors
     */
    private function encryptOrFail(#[\SensitiveParameter] string $plain, string $context, array &$errors): ?string
    {
        try {
            return ($this->encrypt)($plain, $context);
        } catch (KeyVaultException) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_NO_SECRET';

            return null;
        }
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : '';
    }

    private static function int(mixed $value): ?int
    {
        $value = trim(self::str($value));

        return preg_match('/^[0-9]{1,6}$/', $value) ? (int) $value : null;
    }

    private static function flag(mixed $value): bool
    {
        return \in_array(self::str($value), ['1', 'on', 'true'], true);
    }
}
