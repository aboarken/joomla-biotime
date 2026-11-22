<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Find Tables (1.3.0): what ZKBioTime's database holds, to identify the tables a feature needs.
 * Read only, with the portal's own read-only database user.
 *
 *  - tables(): every table and view the user can see, whether it may be read (all columns, some
 *    columns only, or none), and about how many rows it has (the database's own estimate: no row
 *    is read).
 *  - describe(): one table's columns (type, empty allowed, primary key, the table and column a
 *    foreign key points to), the tables that point to it, how many rows it has, how often each
 *    column is filled, and the values of code columns (status, type, flag…) with how often each
 *    is used.
 *
 * No personal data is returned: names, types and counts only. Values are shown only for columns
 * that hold codes (codeLike()), never for names, codes of people, dates, times or free text.
 * Table and column names in statements come from the database's own catalogue, never from input.
 */
final class TableExplorer
{
    /** Rows read to see how often each column is filled. */
    public const SAMPLE_ROWS = 10_000;

    /** Rows counted at most (a larger table shows "1,000,000+"). */
    public const COUNT_CAP = 1_000_000;

    /** Rows read to list the values of a code column. */
    private const VALUE_ROWS = 100_000;

    /** Values listed per code column; a column with more is not a code column. */
    public const MAX_VALUES = 12;

    /** Code columns whose values are listed, per table. */
    private const MAX_CODE_COLUMNS = 20;

    private const MAX_COLUMNS    = 150;
    private const MAX_REFERRERS  = 100;

    public function __construct(private readonly Query $q)
    {
    }

    /**
     * readable: every column may be read; partial: only some columns may be read (1.3.1: e.g.
     * auth_user, whose password column is never granted).
     *
     * @return list<array{name: string, readable: bool, partial: bool, estimate: ?int}>
     *
     * @throws DbException
     */
    public function tables(): array
    {
        $rows = $this->q->engine === DbConfig::ENGINE_SQLSRV
            ? $this->q->rows(
                "SELECT o.name AS name,
                        HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME(o.schema_id)) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') AS readable,
                        0 AS partial,
                        (SELECT SUM(p.rows) FROM sys.partitions p WHERE p.object_id = o.object_id AND p.index_id IN (0, 1)) AS estimate
                   FROM sys.objects o
                  WHERE o.type IN ('U', 'V') AND o.schema_id = SCHEMA_ID()
                  ORDER BY o.name"
            )
            : $this->q->rows(
                "SELECT c.relname AS name,
                        has_table_privilege(c.oid, 'SELECT') AS readable,
                        has_any_column_privilege(c.oid, 'SELECT') AS partial,
                        CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END AS estimate
                   FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'v', 'm')
                  ORDER BY c.relname"
            );

        return array_map(static fn (array $r): array => [
            'name'     => (string) $r['name'],
            'readable' => self::bool($r['readable']),
            'partial'  => !self::bool($r['readable']) && self::bool($r['partial']),
            'estimate' => $r['estimate'] === null ? null : (int) $r['estimate'],
        ], $rows);
    }

    /**
     * One table, or null when the user cannot see a table of that name.
     *
     * @return array{
     *     name: string,
     *     readable: bool,
     *     partial: bool,
     *     estimate: ?int,
     *     rows: ?int,
     *     capped: bool,
     *     sample: int,
     *     columns: list<array{name: string, type: string, nullable: bool, key: string, ref: string, readable: bool, filled: ?int, values: ?list<array{0: string, 1: int}>}>,
     *     referenced_by: list<array{table: string, column: string, to: string}>,
     *     error: ?string
     * }|null
     *
     * @throws DbException when the catalogue cannot be read
     */
    public function describe(string $name): ?array
    {
        $table = null;

        foreach ($this->tables() as $t) {
            if ($t['name'] === $name) {
                $table = $t;
            }
        }

        if ($table === null) {
            return null;
        }

        $sqlsrv  = $this->q->engine === DbConfig::ENGINE_SQLSRV;
        $columns = $sqlsrv ? $this->sqlsrvColumns($name) : $this->pgsqlColumns($name);
        $columns = \array_slice($columns, 0, self::MAX_COLUMNS);
        $result  = $table + ['rows' => null, 'capped' => false, 'sample' => 0, 'columns' => [], 'referenced_by' => $sqlsrv ? $this->sqlsrvReferrers($name) : $this->pgsqlReferrers($name), 'error' => null];

        foreach ($columns as $c) {
            $result['columns'][] = $c + ['filled' => null, 'values' => null];
        }

        // Only the columns the user may read are counted (all of them on a readable table).
        $readable = array_values(array_filter($result['columns'], static fn (array $c): bool => $c['readable']));

        if ((!$table['readable'] && !$table['partial']) || $readable === []) {
            return $result;
        }

        // The rows themselves: counts only. A failure (e.g. a view that cannot be read) keeps the
        // columns and says why.
        try {
            $from  = $this->quote($name);
            $count = (int) $this->q->value('SELECT COUNT(*) FROM (' . $this->limit('SELECT 1 AS one FROM ' . $from, self::COUNT_CAP + 1) . ') s');

            $result['rows']   = min($count, self::COUNT_CAP);
            $result['capped'] = $count > self::COUNT_CAP;

            $parts = [];
            $names = [];

            foreach ($result['columns'] as $i => $c) {
                if ($c['readable']) {
                    $parts[] = 'COUNT(' . $this->quote($c['name']) . ') AS f' . $i;
                    $names[] = $this->quote($c['name']);
                }
            }

            // The readable columns by name, never "*": a column the user may not read (a password
            // hash) is not even asked for.
            $filled           = $this->q->rows('SELECT COUNT(*) AS n, ' . implode(', ', $parts) . ' FROM (' . $this->limit('SELECT ' . implode(', ', $names) . ' FROM ' . $from, self::SAMPLE_ROWS) . ') s')[0] ?? [];
            $result['sample'] = (int) ($filled['n'] ?? 0);

            foreach ($result['columns'] as $i => &$c) {
                $c['filled'] = $c['readable'] && isset($filled['f' . $i]) ? (int) $filled['f' . $i] : null;
            }

            unset($c);

            $listed = 0;

            foreach ($result['columns'] as &$c) {
                if ($listed >= self::MAX_CODE_COLUMNS || !$c['readable'] || !self::codeLike($c['name'], $c['type'], $c['key'] !== '')) {
                    continue;
                }

                $listed++;
                $col    = $this->quote($c['name']);
                $rows   = $this->q->rows(
                    'SELECT v, COUNT(*) AS n FROM (' . $this->limit('SELECT ' . $col . ' AS v FROM ' . $from, self::VALUE_ROWS) . ') s GROUP BY v ORDER BY COUNT(*) DESC, v'
                );

                // More different values than a list of codes: not shown (it is data, not codes).
                if (\count($rows) > self::MAX_VALUES) {
                    continue;
                }

                $c['values'] = array_map(static fn (array $r): array => [self::shown($r['v']), (int) $r['n']], $rows);
            }

            unset($c);
        } catch (DbException $e) {
            $result['error'] = $e->getKind();
        }

        return $result;
    }

    /**
     * The values of a few known code columns (how often each is used), for Diagnostics: which
     * numbers this ZKBioTime server stores for statuses and punch types. Columns the server does
     * not have are left out.
     *
     * @param  list<array{0: string, 1: string}> $columns [table, column]
     * @return array<string, list<array{0: string, 1: int}>> "table.column" => values
     */
    public function codeValues(array $columns): array
    {
        $out = [];

        foreach ($columns as [$table, $column]) {
            if (TableCatalog::normalize($table) !== $table || TableCatalog::normalize($column) !== $column) {
                continue;
            }

            try {
                $rows = $this->q->rows(
                    'SELECT v, COUNT(*) AS n FROM (' . $this->limit('SELECT ' . $this->quote($column) . ' AS v FROM ' . $this->quote($table), self::VALUE_ROWS) . ') s GROUP BY v ORDER BY COUNT(*) DESC, v'
                );
            } catch (DbException) {
                continue;
            }

            $out[$table . '.' . $column] = array_map(static fn (array $r): array => [self::shown($r['v']), (int) $r['n']], \array_slice($rows, 0, self::MAX_VALUES));
        }

        return $out;
    }

    /**
     * Whether a column holds codes whose values may be shown: true/false columns, and short
     * numbers or texts named like a status, type, state, flag, kind, mode, unit, category or
     * level. Never a key (ids), a name, a person's code, a date or free text.
     */
    public static function codeLike(string $name, string $type, bool $isKey): bool
    {
        $name = strtolower($name);
        $type = strtolower($type);

        if ($isKey || $name === 'id' || str_ends_with($name, '_id') || str_ends_with($name, '_ptr_id')) {
            return false;
        }

        if ($type === 'boolean' || $type === 'bit') {
            return true;
        }

        if (!preg_match('/(^|_)(status|state|type|kind|flag|mode|unit|category|level|active|enable|enabled|valid|off)(_|$)|^is_/', $name)) {
            return false;
        }

        if (preg_match('/^(smallint|integer|int|tinyint|int2|int4)$/', $type)) {
            return true;
        }

        // Short texts only: character varying(12) or less.
        return (bool) preg_match('/^(character varying|varchar|nvarchar|character|char|nchar)\((\d+)\)$/', $type, $m) && (int) $m[2] <= 12;
    }

    /** A table or column name for a statement, quoted for the engine. */
    public function quote(string $name): string
    {
        return $this->q->engine === DbConfig::ENGINE_SQLSRV
            ? '[' . str_replace(']', ']]', $name) . ']'
            : '"' . str_replace('"', '""', $name) . '"';
    }

    private function limit(string $select, int $rows): string
    {
        return $this->q->engine === DbConfig::ENGINE_SQLSRV
            ? (string) preg_replace('/^SELECT /', 'SELECT TOP (' . $rows . ') ', $select, 1)
            : $select . ' LIMIT ' . $rows;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, key: string, ref: string}>
     */
    private function pgsqlColumns(string $table): array
    {
        $columns = $this->q->rows(
            "SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, NOT a.attnotnull AS nullable,
                    has_column_privilege(a.attrelid, a.attnum, 'SELECT') AS can_read
               FROM pg_attribute a
               JOIN pg_class c ON c.oid = a.attrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE c.relname = :t AND n.nspname = current_schema() AND a.attnum > 0 AND NOT a.attisdropped
              ORDER BY a.attnum",
            [':t' => $table]
        );

        // Primary keys, unique keys and foreign keys, one row per column of the key.
        $keys = $this->q->rows(
            "SELECT con.contype AS kind, a.attname AS col, cf.relname AS ref_table, af.attname AS ref_col
               FROM pg_constraint con
               JOIN pg_class c ON c.oid = con.conrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              CROSS JOIN LATERAL unnest(con.conkey, con.confkey) AS k(attnum, refnum)
               JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
               LEFT JOIN pg_class cf ON cf.oid = con.confrelid
               LEFT JOIN pg_attribute af ON af.attrelid = con.confrelid AND af.attnum = k.refnum
              WHERE c.relname = :t AND n.nspname = current_schema() AND con.contype IN ('p', 'f', 'u')",
            [':t' => $table]
        );

        return self::withKeys($columns, array_map(static fn (array $k): array => [
            'kind' => ['p' => 'PK', 'f' => 'FK', 'u' => 'UQ'][(string) $k['kind']] ?? '',
            'col'  => (string) $k['col'],
            'ref'  => $k['ref_table'] !== null ? $k['ref_table'] . '.' . $k['ref_col'] : '',
        ], $keys));
    }

    /** @return list<array{table: string, column: string, to: string}> */
    private function pgsqlReferrers(string $table): array
    {
        $rows = $this->q->rows(
            "SELECT c.relname AS tbl, a.attname AS col, af.attname AS ref_col
               FROM pg_constraint con
               JOIN pg_class c ON c.oid = con.conrelid
               JOIN pg_class cf ON cf.oid = con.confrelid
               JOIN pg_namespace n ON n.oid = cf.relnamespace
              CROSS JOIN LATERAL unnest(con.conkey, con.confkey) AS k(attnum, refnum)
               JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
               JOIN pg_attribute af ON af.attrelid = con.confrelid AND af.attnum = k.refnum
              WHERE cf.relname = :t AND n.nspname = current_schema() AND con.contype = 'f'
              ORDER BY c.relname, a.attname
              LIMIT " . self::MAX_REFERRERS,
            [':t' => $table]
        );

        return array_map(static fn (array $r): array => ['table' => (string) $r['tbl'], 'column' => (string) $r['col'], 'to' => (string) $r['ref_col']], $rows);
    }

    /**
     * SQL Server. UNVERIFIED on a real ZKBioTime server.
     *
     * @return list<array{name: string, type: string, nullable: bool, key: string, ref: string}>
     */
    private function sqlsrvColumns(string $table): array
    {
        $columns = $this->q->rows(
            "SELECT c.name AS name,
                    t.name + CASE WHEN t.name IN ('varchar', 'nvarchar', 'char', 'nchar')
                                  THEN '(' + CASE WHEN c.max_length = -1 THEN 'max' ELSE CAST(CASE WHEN t.name LIKE 'n%' THEN c.max_length / 2 ELSE c.max_length END AS varchar(10)) END + ')'
                                  ELSE '' END AS type,
                    c.is_nullable AS nullable,
                    HAS_PERMS_BY_NAME(QUOTENAME(SCHEMA_NAME()) + '.' + QUOTENAME(OBJECT_NAME(c.object_id)), 'OBJECT', 'SELECT') AS can_read
               FROM sys.columns c
               JOIN sys.types t ON t.user_type_id = c.user_type_id
              WHERE c.object_id = OBJECT_ID(QUOTENAME(SCHEMA_NAME()) + '.' + QUOTENAME(:t))
              ORDER BY c.column_id",
            [':t' => $table]
        );

        $keys = $this->q->rows(
            "SELECT 'PK' AS kind, c.name AS col, NULL AS ref_table, NULL AS ref_col
               FROM sys.indexes i
               JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
               JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
              WHERE i.object_id = OBJECT_ID(QUOTENAME(SCHEMA_NAME()) + '.' + QUOTENAME(:t)) AND i.is_primary_key = 1
             UNION ALL
             SELECT 'FK', c.name, OBJECT_NAME(fk.referenced_object_id), rc.name
               FROM sys.foreign_key_columns fk
               JOIN sys.columns c ON c.object_id = fk.parent_object_id AND c.column_id = fk.parent_column_id
               JOIN sys.columns rc ON rc.object_id = fk.referenced_object_id AND rc.column_id = fk.referenced_column_id
              WHERE fk.parent_object_id = OBJECT_ID(QUOTENAME(SCHEMA_NAME()) + '.' + QUOTENAME(:t2))",
            [':t' => $table, ':t2' => $table]
        );

        return self::withKeys($columns, array_map(static fn (array $k): array => [
            'kind' => (string) $k['kind'],
            'col'  => (string) $k['col'],
            'ref'  => $k['ref_table'] !== null ? $k['ref_table'] . '.' . $k['ref_col'] : '',
        ], $keys));
    }

    /** @return list<array{table: string, column: string, to: string}> */
    private function sqlsrvReferrers(string $table): array
    {
        $rows = $this->q->rows(
            "SELECT TOP (" . self::MAX_REFERRERS . ") OBJECT_NAME(fk.parent_object_id) AS tbl, c.name AS col, rc.name AS ref_col
               FROM sys.foreign_key_columns fk
               JOIN sys.columns c ON c.object_id = fk.parent_object_id AND c.column_id = fk.parent_column_id
               JOIN sys.columns rc ON rc.object_id = fk.referenced_object_id AND rc.column_id = fk.referenced_column_id
              WHERE fk.referenced_object_id = OBJECT_ID(QUOTENAME(SCHEMA_NAME()) + '.' + QUOTENAME(:t))
              ORDER BY OBJECT_NAME(fk.parent_object_id), c.name",
            [':t' => $table]
        );

        return array_map(static fn (array $r): array => ['table' => (string) $r['tbl'], 'column' => (string) $r['col'], 'to' => (string) $r['ref_col']], $rows);
    }

    /**
     * @param  list<array<string, mixed>>                       $columns
     * @param  list<array{kind: string, col: string, ref: string}> $keys
     * @return list<array{name: string, type: string, nullable: bool, key: string, ref: string, readable: bool}>
     */
    private static function withKeys(array $columns, array $keys): array
    {
        $out = [];

        foreach ($columns as $c) {
            $name  = (string) $c['name'];
            $kinds = [];
            $ref   = '';

            foreach ($keys as $k) {
                if ($k['col'] === $name && $k['kind'] !== '') {
                    $kinds[$k['kind']] = true;
                    $ref = $k['ref'] !== '' ? $k['ref'] : $ref;
                }
            }

            $out[] = [
                'name'     => $name,
                'type'     => (string) $c['type'],
                'nullable' => self::bool($c['nullable']),
                'key'      => implode(', ', array_keys($kinds)),
                'ref'      => $ref,
                'readable' => self::bool($c['can_read'] ?? false),
            ];
        }

        return $out;
    }

    /** A code value as text: "(empty)" for NULL, at most 30 characters. */
    private static function shown(mixed $value): string
    {
        if ($value === null) {
            return '(empty)';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_substr(trim(strip_tags((string) $value)), 0, 30);
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
