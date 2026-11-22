<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConnector;
use BiotimePortal\Component\Biotime\Administrator\Database\Query;
use BiotimePortal\Component\Biotime\Administrator\Database\TableCatalog;
use BiotimePortal\Component\Biotime\Administrator\Database\TableDiagnostics;
use BiotimePortal\Component\Biotime\Administrator\Database\TableExplorer;
use PHPUnit\Framework\TestCase;

/**
 * Find Tables (1.3.0): the table catalogue, which columns are codes (their values may be shown),
 * and, against the PostgreSQL fixture, the table list, one table's description and Diagnostics.
 */
final class TableExplorerTest extends TestCase
{
    public function testOnlyCodeColumnsHaveTheirValuesShown(): void
    {
        self::assertTrue(TableExplorer::codeLike('approval_status', 'smallint', false));
        self::assertTrue(TableExplorer::codeLike('punch_state', 'character varying(5)', false));
        self::assertTrue(TableExplorer::codeLike('verify_type', 'integer', false));
        self::assertTrue(TableExplorer::codeLike('active', 'boolean', false));
        self::assertTrue(TableExplorer::codeLike('is_superuser', 'boolean', false));

        // Never a key, a name, a person's code, a date, a long text or a number that is not a code.
        self::assertFalse(TableExplorer::codeLike('id', 'integer', true));
        self::assertFalse(TableExplorer::codeLike('status_id', 'integer', false));
        self::assertFalse(TableExplorer::codeLike('approval_status', 'smallint', true));
        self::assertFalse(TableExplorer::codeLike('emp_code', 'character varying(20)', false));
        self::assertFalse(TableExplorer::codeLike('first_name', 'character varying(100)', false));
        self::assertFalse(TableExplorer::codeLike('status_text', 'character varying(200)', false));
        self::assertFalse(TableExplorer::codeLike('approval_time', 'timestamp with time zone', false));
        self::assertFalse(TableExplorer::codeLike('leave_day', 'double precision', false));
        self::assertFalse(TableExplorer::codeLike('apply_reason', 'text', false));
    }

    public function testTheCatalogueAndTableNames(): void
    {
        $leave = TableCatalog::find('att_leave');
        self::assertNotNull($leave);
        self::assertStringContainsString('My Leaves', $leave['portal']);
        self::assertFalse($leave['planned']);
        self::assertTrue(TableCatalog::find('workflow_workflownode')['planned'] ?? false);
        self::assertNull(TableCatalog::find('nothing'));
        self::assertSame(\count(TableCatalog::all()), \count(array_unique(array_column(TableCatalog::all(), 'name'))));

        self::assertSame('att_leave', TableCatalog::normalize('  ATT_Leave '));
        self::assertNull(TableCatalog::normalize('att_leave; DROP TABLE x'));
        self::assertNull(TableCatalog::normalize('"att_leave"'));
        self::assertNull(TableCatalog::normalize('public.att_leave'));
        self::assertNull(TableCatalog::normalize(''));
    }

    /** Against the PostgreSQL fixture (tests/Support/sql/zkbiotime-pgsql.sql), when BIOTIME_TEST_PG is set. */
    public function testTablesKeysLinksAndCodesOfARealDatabase(): void
    {
        $x = new TableExplorer($this->real());

        $tables = array_column($x->tables(), null, 'name');
        self::assertTrue($tables['att_leave']['readable']);
        self::assertArrayHasKey('iclock_transaction', $tables);

        $leave   = $x->describe('att_leave');
        $columns = array_column($leave['columns'], null, 'name');
        self::assertSame('PK, FK', $columns['workflowinstance_ptr_id']['key']);
        self::assertSame('workflow_workflowinstance.id', $columns['workflowinstance_ptr_id']['ref']);
        self::assertSame($leave['rows'], $columns['start_time']['filled'], 'a required column is always filled');
        self::assertNull($columns['apply_reason']['values'], 'free text is never shown');

        $wf = $x->describe('workflow_workflowinstance');
        self::assertContains(['table' => 'att_leave', 'column' => 'workflowinstance_ptr_id', 'to' => 'id'], $wf['referenced_by']);
        $codes = array_column($wf['columns'], 'values', 'name');
        self::assertSame([['1', 3], ['2', 2], ['3', 1]], $codes['approval_status']);
        self::assertNull($codes['employee_id'], 'ids are never listed');

        $people = array_column($x->describe('personnel_employee')['columns'], 'values', 'name');
        self::assertSame([null], array_values(array_unique(array_map(static fn ($v) => $v, $people))), 'no value of an employee is shown');

        self::assertNull($x->describe('no_such_table'));
        self::assertSame(['iclock_transaction.punch_state' => [['0', 4], ['1', 1]]], $x->codeValues([['iclock_transaction', 'punch_state'], ['missing_table', 'status'], ['bad name', 'x']]));
    }

    /**
     * 1.3.1: auth_user holds password hashes, so only eight of its columns are granted
     * (docs/DATABASE.md). It is "some columns", not "not readable"; only the granted columns are
     * counted, and the password column is never read.
     */
    public function testATableWithSomeColumnsGrantedIsCountedOnThoseOnly(): void
    {
        $x = new TableExplorer($this->real());

        $users = array_column($x->tables(), null, 'name')['auth_user'];
        self::assertFalse($users['readable']);
        self::assertTrue($users['partial']);
        self::assertFalse(array_column($x->tables(), null, 'name')['att_leave']['partial'], 'a readable table is not partial');

        $d       = $x->describe('auth_user');
        $columns = array_column($d['columns'], null, 'name');
        self::assertNull($d['error']);
        self::assertGreaterThan(0, $d['rows']);
        self::assertFalse($columns['password']['readable']);
        self::assertNull($columns['password']['filled']);
        self::assertNull($columns['password']['values']);
        self::assertTrue($columns['username']['readable']);
        self::assertSame($d['rows'], $columns['id']['filled']);
        self::assertNotNull($columns['is_superuser']['values'], 'a granted true/false column lists its values');
    }

    public function testDiagnosticsReportHoldsNoPersonalData(): void
    {
        $report = TableDiagnostics::run($this->real(), ['Component version' => '1.3.0'], new \DateTimeImmutable('2026-11-22 10:00', new \DateTimeZone('UTC')));

        self::assertStringContainsString("Run: 2026-11-22 10:00 UTC\n", $report);
        self::assertStringContainsString('Component version: 1.3.0', $report);
        self::assertStringContainsString('Safe for the portal: yes', $report);
        self::assertMatchesRegularExpression('/\[ok\]\s+att_leave\s/', $report);
        self::assertMatchesRegularExpression('/\[some columns\]\s+auth_user\s/', $report);
        self::assertMatchesRegularExpression('/\[missing\]\s+workflow_workflownode\s.*Leave Request \(planned\)/', $report);
        self::assertStringContainsString('workflow_workflowinstance.approval_status: 1 ×3 · 2 ×2 · 3 ×1', $report);

        foreach (['Alice', 'Bob', 'E1', 'a@example.com', 'portal-pass', 'joomla_portal', '127.0.0.1'] as $private) {
            self::assertStringNotContainsString($private, $report);
        }
    }

    private function real(): Query
    {
        $env = getenv('BIOTIME_TEST_PG');

        if (!\is_string($env) || $env === '' || !\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('BIOTIME_TEST_PG not set');
        }

        [$host, $port, $db, $user, $password] = explode(':', $env, 5);

        return (new DbConnector())->connect(new DbConfig('pgsql', $host, (int) $port, $db, $user, $password, DbConfig::SSL_REQUIRE));
    }
}
