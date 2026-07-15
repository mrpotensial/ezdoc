<?php

declare(strict_types=1);

namespace Ezdoc\Db;

use Ezdoc\Db\Schema\SchemaManager;

/**
 * Ezdoc\Db\Connection — driver-agnostic database contract.
 *
 * Semua Repository di ezdoc talk lewat interface ini — bukan langsung ke mysqli /
 * PDO. Konsekuensinya swap driver = swap adapter (1 file), tanpa touch Repository.
 *
 * Adapter yang di-ship out-of-the-box:
 *   - `Ezdoc\Db\Mysqli\MysqliConnection` — default, zero-dep, wrap raw mysqli.
 *     Consumer koneksi.php pattern tetap works (backward compat).
 *   - `Ezdoc\Db\Pdo\PdoConnection` — PDO wrapper. Support mysql/mariadb/sqlite/
 *     pgsql/sqlsrv driver via DSN. Untuk consumer yang tidak pakai mysql daemon
 *     (contoh: `App::demo()` mode SQLite).
 *
 * ## Design notes
 *
 * - **Prepared statement first-class**: `prepare()` + `execute()` split supaya
 *   caller bisa reuse statement (batch insert, dsb). Tapi 90% call cukup pakai
 *   `fetchOne()`/`fetchAll()`/`execute()` yang otomatis prepare+bind+execute.
 * - **Positional params (`?`) only**: named params (`:foo`) tidak universal
 *   across driver (mysqli tidak native support). Semua adapter accept `?`.
 * - **Return type `array<string,mixed>`** untuk rows — plain assoc array supaya
 *   consumer tidak lock ke Result object tertentu. Repository yang decide mau
 *   di-hydrate ke Document/Template value object.
 * - **Grammar accessor** — `grammar()` returns SQL-dialect helper. Repository
 *   bisa `$conn->grammar()->quoteIdentifier('column')` untuk portable code.
 * - **Transaction sugar** — `transaction(callable)` handle begin/commit/rollback
 *   dengan exception propagation. Nested transaction via savepoint (opsional
 *   per driver support).
 *
 * PHP 7.4+ compatible — no readonly props, no enum, no first-class callables.
 *
 * @implementation-notes Contract shape inspired by Doctrine DBAL Connection +
 *   Laravel Illuminate\Database\ConnectionInterface. Reimplemented from spec
 *   (no vendored code). Both are MIT-licensed prior art.
 */
interface Connection
{
    /**
     * Return the SQL grammar helper untuk platform ini.
     *
     * Repository pakai grammar helper untuk:
     *   - Quote identifier (backtick di MySQL, double-quote di Postgres, bracket
     *     di SQL Server)
     *   - Compile DDL (via Blueprint) — biasanya tidak dipanggil dari Repository,
     *     ini dipakai Migration runner
     *   - Compile QueryBuilder → SQL final
     */
    public function grammar(): \Ezdoc\Db\Grammar\Grammar;

    /**
     * Return SchemaManager untuk introspection + DDL execution.
     *
     * Dipakai Migration runner + `cli/spec-dump.php`. Repository normal
     * jangan sentuh — schema mutation adalah tanggung jawab migration.
     */
    public function schemaManager(): SchemaManager;

    /**
     * Prepare SQL statement, return handle yang siap execute berulang.
     *
     * @param string $sql SQL dengan positional placeholder `?`.
     * @throws \Ezdoc\Db\Exception\ConnectionException Kalau prepare gagal.
     */
    public function prepare(string $sql): Statement;

    /**
     * Prepare + execute — untuk one-shot INSERT/UPDATE/DELETE.
     *
     * @param string      $sql    SQL dengan positional placeholder `?`.
     * @param list<mixed> $params Parameter values, ordinal binding.
     * @return int Affected row count.
     * @throws \Ezdoc\Db\Exception\QueryException Kalau execute gagal.
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Prepare + execute SELECT, return first row atau null.
     *
     * @param string      $sql
     * @param list<mixed> $params
     * @return array<string,mixed>|null Assoc array, atau null kalau tidak ada row.
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Prepare + execute SELECT, return semua rows.
     *
     * @param string      $sql
     * @param list<mixed> $params
     * @return list<array<string,mixed>> List assoc arrays.
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Prepare + execute SELECT, return single column value (first column, first row).
     *
     * Convenience untuk `SELECT COUNT(*) …`, `SELECT MAX(id) …`, dsb.
     *
     * @param string      $sql
     * @param list<mixed> $params
     * @return mixed Value atau null kalau no row.
     */
    public function fetchScalar(string $sql, array $params = []);

    /**
     * Last inserted id (untuk table dgn autoincrement PK).
     *
     * @return int|string Depends on driver: mysql=int, postgres=string (bigint
     *   dapat exceed PHP int), sqlite=int. Repository kalau butuh cast, cast sendiri.
     */
    public function lastInsertId();

    /**
     * Transaction sugar dengan exception propagation.
     *
     * Callback receives `$this` (Connection) untuk conveniently execute inside.
     * Kalau callback throw, rollback + rethrow. Kalau success, commit.
     *
     * Nested call: implementation SHOULD support savepoint (Postgres/MySQL/SQLite
     * support; SQL Server dgn caveat). Kalau driver tidak support, bisa throw
     * `LogicException` "nested transaction not supported".
     *
     * @template T
     * @param callable(Connection): T $callback
     * @return T Value yg di-return callback.
     * @throws \Throwable Rethrows apapun yang di-throw callback.
     */
    public function transaction(callable $callback);

    /**
     * Begin manual transaction (untuk yang butuh control lebih detail dari
     * `transaction()` sugar). Prefer `transaction()` kalau bisa.
     */
    public function beginTransaction(): void;

    /**
     * Commit manual transaction.
     */
    public function commit(): void;

    /**
     * Rollback manual transaction.
     */
    public function rollback(): void;

    /**
     * True kalau sedang di dalam transaction (aktif).
     */
    public function inTransaction(): bool;

    /**
     * QueryBuilder factory — chainable fluent SQL builder.
     *
     * ```php
     * $rows = $conn->query()->select('*')->from('t')->where('id = ?', $id)->fetchAll();
     * ```
     */
    public function query(): QueryBuilder;

    /**
     * Underlying raw connection object — escape hatch untuk edge case yang
     * belum ter-abstract.
     *
     * PLEASE avoid pakai ini di Repository — semua path harus lewat interface.
     * Kalau kau butuh raw handle, kemungkinan besar interface butuh method baru.
     *
     * @return \mysqli|\PDO|mixed
     */
    public function raw();
}
