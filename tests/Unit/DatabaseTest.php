<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Database\Query;
use BiotimePortal\Component\Biotime\Administrator\Database\SchemaInspector;
use BiotimePortal\Component\Biotime\Tests\Support\CatalogueQuery;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private function pgsql(array $override = []): DbConfig
    {
        $v = $override + ['host' => 'db.example.com', 'port' => 7496, 'name' => 'biotime', 'user' => 'joomla_portal', 'ssl' => 'verify', 'ca' => '/etc/ssl/zkb.pem'];

        return new DbConfig('pgsql', $v['host'], $v['port'], $v['name'], $v['user'], 'pw', $v['ssl'], $v['ca'], 5, 30);
    }

    public function testPostgresConnectionStringIsReadOnlyEncryptedAndQuoted(): void
    {
        $dsn = $this->pgsql()->dsn();

        self::assertStringStartsWith('pgsql:', $dsn);
        self::assertStringContainsString("host='db.example.com';port=7496;dbname='biotime'", $dsn);
        self::assertStringContainsString('sslmode=verify-full', $dsn);
        self::assertStringContainsString("sslrootcert='/etc/ssl/zkb.pem'", $dsn);
        self::assertStringContainsString('default_transaction_read_only=on', $dsn);
        self::assertStringContainsString('statement_timeout=30000', $dsn);
        self::assertStringNotContainsString('pw', str_replace('pgsql', '', $dsn), 'no password');
        self::assertStringContainsString('sslmode=require', $this->pgsql(['ssl' => 'require', 'ca' => ''])->dsn());
        self::assertStringContainsString('sslmode=disable', $this->pgsql(['ssl' => 'off', 'ca' => ''])->dsn());
        self::assertStringNotContainsString('sslrootcert', $this->pgsql(['ssl' => 'off'])->dsn());
        self::assertStringNotContainsString('pw', print_r($this->pgsql(), true), 'debug output hides the password');
    }

    public function testSqlServerConnectionString(): void
    {
        $dsn = (new DbConfig('sqlsrv', '10.0.0.5', 1433, 'biotime', 'portal', 'pw', 'verify'))->dsn();

        self::assertSame('sqlsrv:Server=10.0.0.5,1433;Database=biotime;LoginTimeout=5;Encrypt=yes;TrustServerCertificate=no;ApplicationIntent=ReadOnly;APP=Joomla ZKBioTime portal', $dsn);
        self::assertStringContainsString('TrustServerCertificate=yes', (new DbConfig('sqlsrv', 'h', 1433, 'b', 'u', 'pw', 'require'))->dsn());
        self::assertStringContainsString('Encrypt=no', (new DbConfig('sqlsrv', 'h', 1433, 'b', 'u', 'pw', 'off'))->dsn());
    }

    public function testValuesThatCouldAddConnectionOptionsAreRefused(): void
    {
        $bad = [
            ['host' => 'db sslmode=disable'], ['host' => 'db;sslmode=disable'], ['port' => 0], ['name' => "b' sslmode='disable"],
            ['user' => 'u;x'], ['ssl' => 'none'], ['ca' => "/a.pem' sslmode='disable"], ['ca' => '/a;b.pem'], ['ca' => ''],
        ];

        foreach ($bad as $override) {
            try {
                $this->pgsql($override);
                self::fail('accepted ' . json_encode($override));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertTrue(DbConfig::validHost('2001:db8::1'));
        self::assertSame(['db.example.com', 7496], DbConfig::parseAddress('DB.example.com', 7496));
        self::assertSame(['10.0.0.5', 15432], DbConfig::parseAddress('10.0.0.5:15432', 7496));
        self::assertSame(['2001:db8::1', 5432], DbConfig::parseAddress('[2001:db8::1]:5432', 7496));
        self::assertSame(['2001:db8::1', 7496], DbConfig::parseAddress('2001:db8::1', 7496));

        foreach (['db.example.com:0', 'db:x', 'postgres://db', 'db.example.com:7496/biotime', ''] as $bad) {
            try {
                DbConfig::parseAddress($bad, 7496);
                self::fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([['fw.example.com', 0], ['', 7496]] as [$ip, $port]) {
            try {
                new DbConfig('pgsql', 'db', 7496, 'b', 'u', 'pw', 'require', '', 5, 30, $ip, $port);
                self::fail('connect via accepted ' . $ip);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertStringContainsString('Server=[2001:db8::1],1433', (new DbConfig('sqlsrv', '2001:db8::1', 1433, 'b', 'u', 'pw'))->dsn());
    }

    public function testDriverErrorsAreSortedAndCleaned(): void
    {
        $error = static fn (string $message, string $state = 'HY000'): DbException => DbException::fromDriver(
            (static function () use ($message, $state): \PDOException {
                $e            = new \PDOException($message);
                $e->errorInfo = [$state];

                return $e;
            })(),
            'connect'
        );

        self::assertSame(DbException::AUTH, $error('FATAL: password authentication failed for user "joomla_portal"', '08006')->getKind());
        self::assertSame(DbException::AUTH, $error('FATAL: no pg_hba.conf entry for host "1.2.3.4"', '08006')->getKind());
        self::assertSame(DbException::SSL, $error('server certificate for "localhost" does not match host name "db"', '08006')->getKind());
        self::assertSame(DbException::DATABASE, $error('FATAL: database "biotim" does not exist', '08006')->getKind());
        self::assertSame(DbException::UNREACHABLE, $error('could not connect to server: Connection refused', '08006')->getKind());
        self::assertSame(DbException::UNREACHABLE, $error('timeout expired', '08006')->getKind());
        self::assertSame(DbException::AUTH, $error("[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Login failed for user 'portal'.", '28000')->getKind());
        self::assertSame('connect failed: host=db password=… user=x', DbException::clean("connect failed:\n host=db password='s3cret' user=x"));
    }

    public function testMissingPhpExtensionIsReportedBeforeConnecting(): void
    {
        $connector = new DbConnector(static fn () => self::fail('must not connect'), static fn (string $ext): bool => false);

        try {
            $connector->connect($this->pgsql());
            self::fail('no exception');
        } catch (DbException $e) {
            self::assertSame([DbException::DRIVER_MISSING, 'pdo_pgsql'], [$e->getKind(), $e->getDetail()]);
        }
    }

    public function testOnlySingleSelectStatementsAreSent(): void
    {
        $q = new Query(null, 'pgsql');

        // 1.3.7 (audit): also functions that change settings or the server, and comments.
        foreach (['DELETE FROM iclock_transaction', 'SELECT 1; DROP TABLE x', 'update x set y = 1',
                  "SELECT set_config('default_transaction_read_only', 'off', false)", 'SELECT pg_terminate_backend(123)',
                  'SELECT lo_unlink(1)', "SELECT dblink('x', 'y')", "SELECT pg_read_file('/etc/passwd')",
                  'SELECT 1 -- comment', 'SELECT /* x */ 1'] as $sql) {
            try {
                $q->rows($sql);
                self::fail('sent: ' . $sql);
            } catch (\LogicException $e) {
                self::assertStringContainsString('Only single SELECT', $e->getMessage());
            }
        }
    }

    /** The guard ignores the words inside string literals: a value may contain them. */
    public function testWordsInsideLiteralsAreNotRefused(): void
    {
        $sqlite = new \PDO('sqlite::memory:');
        $q      = new Query($sqlite, 'pgsql');

        self::assertSame([['v' => 'set_config( -- delete']], $q->rows("SELECT 'set_config( -- delete' AS v"));
    }

    public function testInspectorReportsNamesAndPermissionsOnly(): void
    {
        $q = new CatalogueQuery('pgsql', [
            'SHOW server_version' => [['server_version' => '10.23']],
            'pg_stat_ssl'         => [['ssl' => true, 'version' => 'TLSv1.3']],
            'pg_db_role_setting'  => [['v' => '']],
            'FROM pg_roles'       => [['rolsuper' => false, 'rolcreaterole' => false, 'rolcreatedb' => false, 'config' => 'default_transaction_read_only=on']],
            'has_database_privilege' => [['?column?' => true]],
            'FROM pg_class'       => [
                ['id' => 1, 'name' => 'att_leave', 'readable' => false, 'writable' => false],
                ['id' => 2, 'name' => 'auth_user', 'readable' => false, 'writable' => false],
                ['id' => 3, 'name' => 'iclock_transaction', 'readable' => true, 'writable' => false],
                ['id' => 4, 'name' => 'personnel_employee', 'readable' => true, 'writable' => false],
            ],
            'FROM pg_attribute'   => static fn (array $p): array => [['name' => 'col' . $p[':id'], 'type' => 'integer']],
            'FROM att_attshift'   => [['alias' => 'Week Sun-Thu', 'cycle_unit' => 1, 'shift_cycle' => 1, 'days' => '0=Day, 1=Day, 2=Day, 3=Day, 4=Day']],
            'FROM att_payloadtimecard' => [['count' => 42]],
            'FROM att_payloadbase' => [['count' => 0]],
        ]);

        $r = (new SchemaInspector())->inspect($q);

        self::assertSame(['10.23', true, 'TLSv1.3', false, true, true], [$r['version'], $r['ssl'], $r['ssl_detail'], $r['superuser'], $r['role_read_only'], $r['can_create']]);
        self::assertTrue($r['safe']);
        self::assertSame(['att_leave', 'iclock_transaction', 'personnel_employee'], array_column($r['tables'], 'name'));
        self::assertSame([['col1', 'integer']], $r['tables'][0]['columns']);
        self::assertSame(['auth_user'], $r['others'], 'other tables by name only, no columns');
        self::assertSame(['iclock_transaction' => true, 'iclock_terminal' => false], array_intersect_key(array_column($r['expected'], 'readable', 'name'), ['iclock_transaction' => 1, 'iclock_terminal' => 1]));

        self::assertSame([['name' => 'Week Sun-Thu', 'unit' => 1, 'cycle' => 1, 'days' => '0=Day, 1=Day, 2=Day, 3=Day, 4=Day']], $r['shifts']);
        self::assertSame(42, $r['calculated_days']);

        foreach ($q->sent as $sql) {
            // Only shift settings and a count of calculated days: no employee or punch data.
            self::assertDoesNotMatchRegularExpression('/\bFROM\s+(iclock_|personnel_|auth_)/i', $sql, 'never reads employees, punches or accounts');

            if (preg_match('/\bFROM\s+att_payload(base|timecard)\b/i', $sql)) {
                self::assertStringStartsWith('SELECT COUNT(*)', trim($sql));
            }
        }
    }

    public function testInspectorFlagsAUserThatMayChangeData(): void
    {
        $q = new CatalogueQuery('pgsql', [
            'SHOW server_version'    => [['server_version' => '16']],
            'pg_stat_ssl'            => [['ssl' => false, 'version' => null]],
            'pg_db_role_setting'     => [['v' => '']],
            'FROM pg_roles'          => [['rolsuper' => true, 'rolcreaterole' => true, 'rolcreatedb' => true, 'config' => '']],
            'has_database_privilege' => [['?column?' => true]],
            'FROM pg_class'          => [['id' => 3, 'name' => 'iclock_transaction', 'readable' => true, 'writable' => true]],
            'FROM pg_attribute'      => [],
            'FROM att_attshift'      => [],
            'FROM att_payloadtimecard' => [['count' => 0]],
            'FROM att_payloadbase'   => [['count' => 0]],
        ]);

        $r = (new SchemaInspector())->inspect($q);

        self::assertFalse($r['safe']);
        self::assertSame(['iclock_transaction'], $r['writable']);
        self::assertFalse($r['ssl']);
    }

    public function testReadOnlySetForThisDatabaseOnlyCounts(): void
    {
        $q = new CatalogueQuery('pgsql', [
            'SHOW server_version'    => [['server_version' => '15.2']],
            'pg_stat_ssl'            => [['ssl' => true, 'version' => 'TLSv1.3']],
            'pg_db_role_setting'     => [['v' => 'default_transaction_read_only=on']],
            'FROM pg_roles'          => [['rolsuper' => false, 'rolcreaterole' => false, 'rolcreatedb' => false, 'config' => '']],
            'has_database_privilege' => [['?column?' => false]],
            'FROM pg_class'          => [],
            'FROM att_attshift'      => [],
            'FROM att_payloadtimecard' => [['count' => 0]],
            'FROM att_payloadbase'   => [['count' => 0]],
        ]);

        self::assertTrue((new SchemaInspector())->inspect($q)['role_read_only'], 'pgAdmin Parameters with the database chosen');
    }

    public function testSqlServerCatalogue(): void
    {
        $q = new CatalogueQuery('sqlsrv', [
            'SERVERPROPERTY'      => [['v' => '15.0.2000.5']],
            'dm_exec_connections' => static fn (): array => throw new DbException(DbException::QUERY, 'denied'),
            'IS_SRVROLEMEMBER'    => [['sysadmin' => 0, 'owner' => 0, 'writer' => 0, 'ddl' => 0, 'can_create' => 0]],
            'FROM sys.objects'    => [['id' => 7, 'name' => 'iclock_transaction', 'readable' => 1, 'writable' => 0]],
            'FROM sys.columns'    => [['name' => 'emp_code', 'type' => 'nvarchar']],
        ]);

        $r = (new SchemaInspector())->inspect($q);

        self::assertSame(['15.0.2000.5', null, false, true, true], [$r['version'], $r['ssl'], $r['superuser'], $r['safe'], $r['hidden_names']]);
        self::assertSame([['emp_code', 'nvarchar']], $r['tables'][0]['columns']);
    }

    /**
     * Against a real PostgreSQL, when BIOTIME_TEST_PG is set to "host:port:database:user:password"
     * of a read-only user (docs/TESTING.md). Skipped otherwise.
     */
    public function testRealPostgres(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $r      = (new SchemaInspector())->inspect((new DbConnector())->connect($config));

        self::assertTrue($r['ssl']);
        self::assertTrue($r['safe'], 'the read-only user can change nothing');
        self::assertContains('iclock_transaction', array_column($r['tables'], 'name'));

        $pdo = new \PDO($config->dsn(), $user, $password, $config->pdoOptions());
        $this->expectExceptionMessage('read-only transaction');
        $pdo->exec('CREATE TABLE biotime_portal_write_test (i int)');
    }

    /**
     * 1.0.10: portal pages keep the connection open; one found closed is replaced once by a fresh
     * connection, and the query runs again there. Other errors are not retried.
     */
    public function testAKeptConnectionFoundClosedIsReplacedOnce(): void
    {
        $opened  = [];
        $closed  = new class ('sqlite::memory:') extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $e            = new \PDOException('SQLSTATE[08006]: server closed the connection unexpectedly');
                $e->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];

                throw $e;
            }
        };
        $fresh   = new \PDO('sqlite::memory:');
        $connect = static function (string $dsn, string $user, string $password, array $options) use (&$opened, $closed, $fresh): \PDO {
            $opened[] = (bool) ($options[\PDO::ATTR_PERSISTENT] ?? false);

            return \count($opened) === 1 ? $closed : $fresh;
        };
        $config = new DbConfig('pgsql', 'db.example.com', 5432, 'biotime', 'portal', 'pw', DbConfig::SSL_REQUIRE);
        $q      = (new DbConnector($connect, static fn (): bool => true))->connect($config, true);

        self::assertSame([['n' => 1]], $q->rows('SELECT 1 AS n'));
        self::assertSame([true, false], $opened, 'kept open first, then one fresh connection');

        // Without "keep open" (Test Database) nothing is retried.
        $opened = [];
        $once   = (new DbConnector(static function () use (&$opened, $closed): \PDO {
            $opened[] = 1;

            return $closed;
        }, static fn (): bool => true))->connect($config);

        try {
            $once->rows('SELECT 1');
            self::fail('no exception');
        } catch (DbException) {
            self::assertCount(1, $opened);
        }
    }

    /** Against the PostgreSQL fixture: a kept-open connection that the server ended works again. */
    public function testRealPostgresKeptConnectionSurvivesATermination(): void
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);
        $config = new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE);
        $q      = (new DbConnector())->connect($config, true);
        $pid    = (int) $q->value('SELECT pg_backend_pid()');

        // End that session from another connection, as a PostgreSQL restart would.
        $admin = getenv('BIOTIME_TEST_PG_ADMIN');

        if (!\is_string($admin) || $admin === '') {
            self::markTestSkipped('BIOTIME_TEST_PG_ADMIN not set');
        }

        [$ah, $ap, $ad, $au, $apw] = explode(':', $admin, 5);
        (new \PDO("pgsql:host={$ah};port={$ap};dbname={$ad}", $au, $apw))->query('SELECT pg_terminate_backend(' . $pid . ')');

        self::assertSame(1, (int) $q->value('SELECT 1'), 'the query ran again on a fresh connection');
        self::assertNotSame($pid, (int) $q->value('SELECT pg_backend_pid()'));
    }

    /** 1.0.10: only reading statements are sent, also inside a WITH; words in quotes do not count. */
    public function testOnlyReadingStatementsAreSent(): void
    {
        $q = new \BiotimePortal\Component\Biotime\Administrator\Database\Query(new \PDO('sqlite::memory:'), 'pgsql');

        foreach (['WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x', 'SELECT 1; DROP TABLE t', 'UPDATE t SET a = 1', 'WITH x AS (SELECT 1) INSERT INTO t SELECT * FROM x', 'SELECT set_config(\'a\', \'b\', false) FROM t WHERE 1 = 1 FOR UPDATE'] as $sql) {
            try {
                $q->rows($sql);
                self::fail('sent: ' . $sql);
            } catch (\LogicException) {
                self::assertTrue(true);
            }
        }

        self::assertSame([['w' => 'DELETE or UPDATE']], $q->rows("SELECT 'DELETE or UPDATE' AS w"), 'a word in a string literal is fine');
        self::assertSame([['update_time' => 1]], $q->rows('SELECT 1 AS update_time'), 'a column named like a keyword is fine');
    }
}
