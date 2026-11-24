<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsValidator;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;

final class SettingsValidatorTest extends TestCase
{
    private KeyVault $vault;

    protected function setUp(): void
    {
        $this->vault = new KeyVault('a-joomla-configuration-secret-value');
    }

    private function validator(?\Closure $fileExists = null): SettingsValidator
    {
        return new SettingsValidator(fn (string $p, string $c): string => $this->vault->encrypt($p, $c), $fileExists ?? static fn (): bool => true);
    }

    /** @return array<string, string> */
    private function input(array $override = []): array
    {
        return $override + [
            'connect_timeout' => '5',
            'timeout'         => '30',
            'detailed_log'    => '1',
            'cache_minutes'   => '5',
            'date_format'     => 'Y-m-d',
            'time_format'     => 'H:i',
            'server_timezone' => '',
            'weekend_days'    => ['5', '6'],
            'match_field'     => 'employeeid',
            'auto_match'      => '1',
        ];
    }

    /** @return list<string> */
    private function errors(array $input, ?Settings $current = null): array
    {
        try {
            $this->validator()->validate($input, $current ?? new Settings());
        } catch (ValidationFailed $e) {
            return $e->getKeys();
        }

        return [];
    }

    /** 1.0.19: New users waits for an administrator unless "Approve automatically" is chosen. */
    public function testNewUsersWaitForApprovalUnlessChosenOtherwise(): void
    {
        self::assertFalse((new Settings())->autoApproveMatches(), 'default: wait for administrator approval');
        self::assertSame('0', $this->validator()->validate($this->input(), new Settings())['auto_approve_matches']);
        $on = $this->validator()->validate($this->input(['auto_approve_matches' => '1']), new Settings());
        self::assertSame('1', $on['auto_approve_matches']);
        self::assertTrue((new Settings($on))->autoApproveMatches());
        self::assertSame('0', $this->validator()->validate($this->input(['auto_approve_matches' => 'yes please']), new Settings())['auto_approve_matches']);
    }

    public function testWeekendDaysAreStoredAsIsoWeekdays(): void
    {
        self::assertSame('5,6', $this->validator()->validate($this->input(), new Settings())['weekend_days']);
        self::assertSame('6,7', $this->validator()->validate($this->input(['weekend_days' => ['7', '6', '9', 'x']]), new Settings())['weekend_days']);
        self::assertSame('', $this->validator()->validate($this->input(['weekend_days' => []]), new Settings())['weekend_days'], 'no weekend at all is allowed');
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_WEEKEND', $this->errors($this->input(['weekend_days' => ['1', '2', '3', '4', '5', '6', '7']])));
        self::assertSame([5, 6], (new Settings())->weekendDays(), 'default: Friday and Saturday');
        self::assertSame([1, 7], (new Settings(['weekend_days' => '7,1']))->weekendDays());
    }

    /** @return array<string, string> */
    private function database(array $override = []): array
    {
        return $override + [
            'db_engine'   => 'pgsql',
            'db_server'   => 'DB.Example.com:7496',
            'db_name'     => 'biotime',
            'db_user'     => 'joomla_portal',
            'db_password' => 'db secret 1',
            'db_ssl'      => 'verify',
            'db_ca'       => '/etc/ssl/zkb-db.pem',
        ] + $this->input();
    }

    public function testDatabaseSettingsAreCheckedAndThePasswordEncrypted(): void
    {
        $values = $this->validator()->validate($this->database(), new Settings());

        self::assertSame(['pgsql', 'db.example.com', '7496', 'biotime', 'joomla_portal', 'verify'], [$values['db_engine'], $values['db_host'], $values['db_port'], $values['db_name'], $values['db_user'], $values['db_ssl']]);
        self::assertSame('db secret 1', $this->vault->decrypt($values['db_password_enc'], KeyVault::CONTEXT_DB_PASSWORD));
        self::assertArrayNotHasKey('db_password', $values);

        $config = (new Settings($values))->dbConfig($this->vault);
        self::assertSame('db secret 1', $config->password());
        self::assertStringNotContainsString('db secret 1', $config->dsn(), 'the password is never in the connection string');
        self::assertArrayNotHasKey('db_password_enc', (new Settings($values))->toFormData());

        self::assertSame([], $this->errors(['db_engine' => '', 'db_server' => '', 'db_user' => '', 'db_password' => ''] + $this->database()), 'off: nothing required');
        self::assertSame('db.example.com:7496', (new Settings($values))->toFormData()['db_server'], 'one field in the form');
        self::assertSame(['db.example.com', '1433'], array_values(array_intersect_key($this->validator()->validate(['db_engine' => 'sqlsrv', 'db_server' => 'db.example.com', 'db_ca' => ''] + $this->database(), new Settings()), ['db_host' => 1, 'db_port' => 1])), 'default port of the engine');
        self::assertSame([], $this->errors($this->input()), 'forms without the database fields');
    }

    public function testDatabaseSettingsRefuseUnsafeValues(): void
    {
        $cases = [
            [['db_engine' => 'mysql'], 'COM_BIOTIME_SETTINGS_ERR_DB_ENGINE'],
            [['db_server' => 'db sslmode=disable'], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_server' => ''], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_server' => 'db.example.com:70000'], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_server' => 'https://db.example.com'], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_connect_via' => 'fw.example.com:7496'], 'COM_BIOTIME_SETTINGS_ERR_DB_VIA'],
            [['db_connect_via' => '203.0.113.10:x'], 'COM_BIOTIME_SETTINGS_ERR_DB_VIA'],
            [['db_engine' => 'sqlsrv', 'db_ca' => '', 'db_connect_via' => '203.0.113.10'], 'COM_BIOTIME_SETTINGS_ERR_DB_VIA_SQLSRV'],
            [['db_name' => "biotime';x"], 'COM_BIOTIME_SETTINGS_ERR_DB_NAME'],
            [['db_user' => 'a b'], 'COM_BIOTIME_SETTINGS_ERR_DB_USER'],
            [['db_user' => 'Postgres'], 'COM_BIOTIME_SETTINGS_ERR_DB_ADMIN_USER'],
            [['db_user' => 'sa'], 'COM_BIOTIME_SETTINGS_ERR_DB_ADMIN_USER'],
            [['db_ssl' => 'maybe'], 'COM_BIOTIME_SETTINGS_ERR_DB_SSL'],
            [['db_ca' => ''], 'COM_BIOTIME_SETTINGS_ERR_DB_CA_REQUIRED'],
            [['db_ca' => "/etc/a.pem'; sslmode='disable"], 'COM_BIOTIME_SETTINGS_ERR_DB_CA'],
            [['db_password' => ''], 'COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD_REQUIRED'],
        ];

        foreach ($cases as [$override, $key]) {
            self::assertContains($key, $this->errors($override + $this->database()), json_encode($override));
        }

        self::assertSame([], $this->errors(['db_ssl' => 'require', 'db_ca' => ''] + $this->database()), 'Require needs no CA file');
        self::assertSame([], $this->errors(['db_engine' => 'sqlsrv', 'db_server' => 'db.example.com:1433', 'db_ca' => ''] + $this->database()), 'SQL Server checks against the system certificates');
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_DB_CA', (function (): array {
            try {
                $this->validator(static fn (): bool => false)->validate($this->database(), new Settings());
            } catch (ValidationFailed $e) {
                return $e->getKeys();
            }

            return [];
        })(), 'a CA file that does not exist');
    }

    public function testDatabaseConnectViaIsAnIpAddressAndKeepsTheServerNameForTheCertificate(): void
    {
        $values = $this->validator()->validate(['db_server' => 'zkb-server.example.local:7496', 'db_connect_via' => ' 203.0.113.10:15432 '] + $this->database(), new Settings());

        self::assertSame(['zkb-server.example.local', '7496', '203.0.113.10:15432'], [$values['db_host'], $values['db_port'], $values['db_connect_via']]);

        $dsn = (new Settings($values))->dbConfig($this->vault)->dsn();
        self::assertStringContainsString("host='zkb-server.example.local';port=15432", $dsn, 'certificate checked against the name, connection to the public port');
        self::assertStringContainsString("hostaddr='203.0.113.10'", $dsn);

        $noPort = $this->validator()->validate(['db_connect_via' => '203.0.113.10'] + $this->database(), new Settings());
        self::assertSame('203.0.113.10:7496', $noPort['db_connect_via'], 'without a port: the server\'s port');
        self::assertSame('[2001:db8::5]:7496', $this->validator()->validate(['db_connect_via' => '2001:db8::5'] + $this->database(), new Settings())['db_connect_via']);
        self::assertStringNotContainsString('hostaddr', (new Settings($this->validator()->validate($this->database(), new Settings())))->dbConfig($this->vault)->dsn(), 'empty: direct');
    }

    public function testStoredDatabasePasswordIsNotKeptForAnotherServer(): void
    {
        $current = new Settings($this->validator()->validate($this->database(), new Settings()));
        $same    = $this->database(['db_password' => '']);

        self::assertSame([], $this->errors($same, $current), 'same server: kept');
        self::assertArrayNotHasKey('db_password_enc', $this->validator()->validate($same, $current), 'not overwritten: the stored one stays');

        foreach ([['db_server' => 'attacker.example.com:7496'], ['db_server' => 'db.example.com:5432'], ['db_connect_via' => '198.51.100.7'], ['db_name' => 'other'], ['db_user' => 'someone'], ['db_engine' => 'sqlsrv', 'db_ca' => ''], ['db_ssl' => 'off'], ['db_ssl' => 'require'], ['db_ca' => '/tmp/attacker-ca.pem']] as $override) {
            self::assertContains('COM_BIOTIME_SETTINGS_ERR_REENTER_DB_PASSWORD', $this->errors($override + $same, $current), json_encode($override));
        }

        self::assertSame([], $this->errors(['db_server' => 'db2.example.com'] + $this->database(), $current), 'typed again: fine');
        self::assertSame('', $this->validator()->validate(['clear_db_password' => '1', 'db_engine' => ''] + $same, $current)['db_password_enc'], 'removed');
    }

    /** 1.3.8 (audit): "Off" could not be saved while a password was stored (its fields are hidden then). */
    public function testTheConnectionCanBeSwitchedOffWithAStoredPassword(): void
    {
        $current = new Settings($this->validator()->validate($this->database(), new Settings()));
        $off     = $this->database(['db_engine' => '', 'db_password' => '']);

        self::assertSame([], $this->errors($off, $current));
        self::assertArrayNotHasKey('db_password_enc', $this->validator()->validate($off, $current), 'the stored password stays');

        // Switching on again, for any server, asks for the password.
        $offNow = new Settings($this->validator()->validate($off, $current) + ['db_password_enc' => $current->get('db_password_enc')]);
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_REENTER_DB_PASSWORD', $this->errors($this->database(['db_password' => '', 'db_server' => 'attacker.example.com:7496']), $offNow));
    }

    public function testValidSettingsAreStoredWithTheEncryptedDatabasePassword(): void
    {
        $values = $this->validator()->validate($this->database(), new Settings());

        self::assertSame('db secret 1', $this->vault->decrypt($values['db_password_enc'], KeyVault::CONTEXT_DB_PASSWORD));
        self::assertArrayNotHasKey('db_password', $values, 'plain password is never stored');
        self::assertSame(array_keys(Settings::DEFAULTS), array_values(array_intersect(array_keys(Settings::DEFAULTS), array_keys($values))));
        self::assertSame([], array_diff(array_keys($values), array_keys(Settings::DEFAULTS)), 'only known names');

        foreach (['server_url', 'api_username', 'api_password_enc', 'auth_method', 'tls_pin', 'page_size', 'data_source', 'daily_path', 'token_cache_enc'] as $removed) {
            self::assertArrayNotHasKey($removed, Settings::DEFAULTS, $removed . ': the API settings are gone (1.0.0)');
        }
    }

    public function testRulesAreCheckedAndAllErrorsCollected(): void
    {
        $errors = $this->errors($this->input([
            'connect_timeout' => '40',
            'timeout'         => '20',
            'cache_minutes'   => '99',
            'match_field'     => 'Bad Name!',
        ]));

        foreach (['COM_BIOTIME_SETTINGS_ERR_TIMEOUTS', 'COM_BIOTIME_SETTINGS_ERR_CACHE', 'COM_BIOTIME_SETTINGS_ERR_MATCH_FIELD'] as $key) {
            self::assertContains($key, $errors);
        }

        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_TIMEOUT_RANGE'], $this->errors($this->input(['timeout' => '0'])));
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD'], $this->errors($this->database(['db_password' => "a\nb"])));
    }

    public function testNoEncryptionSecretIsReported(): void
    {
        $validator = new SettingsValidator(static fn (): string => throw new KeyVaultException('none'), static fn (): bool => true);

        try {
            $validator->validate($this->database(), new Settings());
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertContains('COM_BIOTIME_SETTINGS_ERR_NO_SECRET', $e->getKeys());
        }
    }

    public function testErrorsNeverContainTheEnteredSecret(): void
    {
        try {
            $this->validator()->validate($this->database(['db_password' => "secret\x01value", 'db_server' => '']), new Settings());
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertStringNotContainsString('secret', $e->getMessage() . print_r($e->getKeys(), true));
        }
    }

    public function testSettingsNeverSendSecretsToTheForm(): void
    {
        $settings = new Settings(['db_engine' => 'pgsql', 'db_host' => 'db.example.com', 'db_user' => 'u', 'db_password_enc' => 'v1:abc']);

        $form = $settings->toFormData();

        foreach (Settings::SECRET_NAMES as $name) {
            self::assertArrayNotHasKey($name, $form);
        }

        self::assertStringNotContainsString('v1:', print_r($settings, true));
        self::assertTrue($settings->hasDbPassword());
    }

    public function testSettingsClampStoredValues(): void
    {
        $settings = new Settings(['timeout' => '9999', 'connect_timeout' => '0', 'cache_minutes' => '500', 'db_duration_unit' => 'hours', 'db_engine' => 'oracle', 'date_format' => 'x', 'server_timezone' => 'Mars/Base', 'match_field' => 'Bad Name']);

        self::assertSame(300, $settings->timeout());
        self::assertSame(1, $settings->connectTimeout());
        self::assertSame('auto', $settings->dbDurationUnit());
        self::assertSame('', $settings->dbEngine());
        self::assertSame(60, $settings->cacheMinutes());
        self::assertSame('d/m/Y', $settings->dateFormat(), 'default: DD/MM/YYYY');
        self::assertSame('', $settings->serverTimezone());
        self::assertSame('', $settings->matchField());
        self::assertSame('employeeid', (new Settings())->matchField());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badSystemValues(): iterable
    {
        yield 'match field' => [['match_field' => 'employee id!'], 'COM_BIOTIME_SETTINGS_ERR_MATCH_FIELD'];
        yield 'cache' => [['cache_minutes' => '61'], 'COM_BIOTIME_SETTINGS_ERR_CACHE'];
        yield 'time zone' => [['server_timezone' => 'Nowhere/City'], 'COM_BIOTIME_SETTINGS_ERR_TIMEZONE'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSystemValues')]
    public function testInvalidSystemValuesAreRefused(array $override, string $key): void
    {
        self::assertSame([$key], $this->errors($this->input($override)));
    }
}
