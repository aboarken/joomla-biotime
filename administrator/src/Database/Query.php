<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Runs prepared SELECT statements on a database connection. Anything else is refused before it
 * reaches the database.
 */
class Query
{
    /** Queries run and the time they took this request, for the page's log line (1.0.2). */
    public static int $count = 0;

    public static float $seconds = 0.0;

    /** Time spent connecting (with the TLS handshake) this request. */
    public static float $connectSeconds = 0.0;

    /**
     * @param (\Closure(): \PDO)|null $reconnect a fresh connection, used once when a kept-open
     *                                         connection turns out to be closed (1.0.10)
     */
    public function __construct(private ?\PDO $pdo, public readonly string $engine, private ?\Closure $reconnect = null)
    {
    }

    /** The statement without its string literals and quoted names, for the keyword check above. */
    private static function withoutLiterals(string $sql): string
    {
        // [name] is SQL Server's quoting (1.3.8): a column named [set] or [update] is not a keyword.
        return preg_replace('/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|\[(?:[^\]]|\]\])*\]/', '\'\'', $sql) ?? $sql;
    }

    /** SQLSTATE classes of a connection that is gone (08 connection exception, 57P0x shutdown). */
    private static function connectionLost(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return str_starts_with($state, '08') || \in_array($state, ['57P01', '57P02', '57P03'], true)
            || preg_match('/server closed the connection|no connection to the server|SSL (SYSCALL error|connection has been closed)|terminating connection/i', $e->getMessage()) === 1;
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function rows(string $sql, array $params = []): array
    {
        // Defence in depth (1.0.10): the session is read-only anyway, but a WITH may not carry a
        // data-changing statement, and nothing that writes or changes settings is sent at all.
        // 1.3.7 (audit): also no function that changes settings or the server (set_config,
        // pg_terminate_backend, large objects, dblink, server files), and no comments.
        $code = self::withoutLiterals($sql);

        if (!preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $sql) || preg_match('/;\s*\S/', $sql) || preg_match('~--|/\*~', $code)
            || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|ALTER|DROP|CREATE|GRANT|REVOKE|COPY|SET|CALL|DO|LOCK|VACUUM)\b/i', $code)
            || preg_match('/\b(set_config|pg_terminate_backend|pg_cancel_backend|pg_reload_conf|pg_rotate_logfile|pg_read_(binary_)?file|pg_ls_dir|lo_\w+|dblink\w*|nextval|setval|pg_advisory\w*)\s*\(/i', $code)) {
            throw new \LogicException('Only single SELECT statements may be run');
        }

        if ($this->pdo === null) {
            throw new \LogicException('No connection');
        }

        $start = microtime(true);

        try {
            try {
                $statement = $this->pdo->prepare($sql);
                $statement->execute($params);
            } catch (\PDOException $e) {
                if ($this->reconnect === null || !self::connectionLost($e)) {
                    throw $e;
                }

                // The kept-open connection was closed (server restart, network): once more on a
                // fresh one. Only SELECTs run here, so repeating one is harmless.
                $this->pdo       = ($this->reconnect)();
                $this->reconnect = null;
                $statement       = $this->pdo->prepare($sql);
                $statement->execute($params);
            }

            return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            throw DbException::fromDriver($e, 'query');
        } finally {
            self::$count++;
            self::$seconds += microtime(true) - $start;
        }
    }

    /**
     * The first value of the first row, or null.
     *
     * @param array<string, scalar|null> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->rows($sql, $params)[0] ?? null;

        return $row === null ? null : (array_values($row)[0] ?? null);
    }
}
