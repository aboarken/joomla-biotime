<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Find Tables → Diagnostics (1.3.0): every check in one run, as a text report to copy into a
 * support request: the portal's settings (no address, user or password), the connection (encrypted,
 * read-only), each table the portal knows (found, readable, about how many rows), the codes this
 * server stores for statuses and punch types, and how much ZKBioTime calculated recently.
 *
 * Names of tables and columns, settings and counts only: no person, code, date or text of any
 * employee.
 */
final class TableDiagnostics
{
    /** Code columns whose values tell how this server stores statuses and punch types. */
    public const CODE_COLUMNS = [
        ['workflow_workflowinstance', 'approval_status'],
        ['workflow_nodeinstance', 'approval_status'],
        ['iclock_transaction', 'punch_state'],
        ['iclock_transaction', 'verify_type'],
        ['att_paycode', 'code_type'],
    ];

    /**
     * @param array<string, string> $portal lines about the portal itself (label => value)
     */
    public static function run(Query $q, array $portal, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out   = ['ZKBioTime portal diagnostics', 'Run: ' . $now->format('Y-m-d H:i') . ' UTC', ''];

        $out[] = '== Portal ==';

        foreach ($portal as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = '== Connection ==';

        try {
            $info = (new SchemaInspector())->inspect($q);
        } catch (DbException $e) {
            $out[] = 'FAILED: ' . $e->getKind() . ($e->getDetail() !== '' ? ' (' . $e->getDetail() . ')' : '');

            return implode("\n", $out) . "\n";
        }

        $yes   = static fn (?bool $b): string => $b === null ? 'unknown' : ($b ? 'yes' : 'no');
        $out[] = 'Server version: ' . $info['version'];
        $out[] = 'Connection encrypted: ' . $yes($info['ssl']) . ($info['ssl_detail'] !== '' ? ' (' . $info['ssl_detail'] . ')' : '');
        $out[] = 'Administrator account: ' . $yes($info['superuser']);
        $out[] = 'Read-only by default: ' . $yes($info['role_read_only']);
        $out[] = 'Tables it may change: ' . ($info['writable'] === [] ? 'none' : \count($info['writable']) . ' (' . implode(', ', \array_slice($info['writable'], 0, 20)) . ')');
        $out[] = 'Safe for the portal: ' . ($info['safe'] ? 'yes' : 'NO');

        $explorer = new TableExplorer($q);

        try {
            $tables = $explorer->tables();
        } catch (DbException $e) {
            $tables = [];
            $out[]  = 'Table list FAILED: ' . $e->getKind();
        }

        $byName = array_column($tables, null, 'name');
        $width  = max(array_map(static fn (array $t): int => \strlen($t['name']), TableCatalog::all()));

        $out[] = '';
        $out[] = '== Tables the portal knows ==';

        foreach (TableCatalog::all() as $t) {
            $found  = $byName[$t['name']] ?? null;
            // Some columns only (1.3.1): by design for auth_user, whose password column is never granted.
            $state  = $found === null ? 'missing' : ($found['readable'] ? 'ok' : (($found['partial'] ?? false) ? 'some columns' : 'NOT READABLE'));
            $rows   = $found !== null && $found['estimate'] !== null ? '~' . number_format($found['estimate']) . ' rows' : '';
            $out[]  = str_pad('[' . $state . ']', 15) . str_pad($t['name'], $width + 2) . str_pad($rows, 18) . $t['section'] . ($t['portal'] !== '' ? ': ' . $t['portal'] : ($t['planned'] ? ': Leave Request (planned)' : ''));
        }

        $out[] = '';
        $out[] = '== Codes in use (value ×rows) ==';
        $codes = $explorer->codeValues(array_values(array_filter(self::CODE_COLUMNS, static fn (array $c): bool => ($byName[$c[0]]['readable'] ?? false) === true)));

        foreach (self::CODE_COLUMNS as [$table, $column]) {
            $values = $codes[$table . '.' . $column] ?? null;
            $out[]  = $table . '.' . $column . ': ' . ($values === null ? '(not read)' : ($values === [] ? '(no rows)' : implode(' · ', array_map(static fn (array $v): string => $v[0] . ' ×' . $v[1], $values))));
        }

        $out[] = '';
        $out[] = '== Recent data ==';
        $out[] = 'Days calculated by ZKBioTime in the last 31 days: ' . ($info['calculated_days'] === null ? 'unknown' : number_format($info['calculated_days']));
        $out[] = 'Shifts defined: ' . \count($info['shifts']);

        $known  = array_column(TableCatalog::all(), 'name');
        $others = array_values(array_filter(array_keys($byName), static fn (string $n): bool => !\in_array($n, $known, true)));

        $out[] = '';
        $out[] = '== Other tables (' . \count($others) . ') ==';
        $out[] = $others === [] ? '(none)' : wordwrap(implode(', ', \array_slice($others, 0, 400)), 100);

        return implode("\n", $out) . "\n";
    }
}
