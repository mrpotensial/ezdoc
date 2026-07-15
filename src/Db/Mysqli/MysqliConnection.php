<?php

declare(strict_types=1);

namespace Ezdoc\Db\Mysqli;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Exception\ConnectionException;
use Ezdoc\Db\Exception\QueryException;
use Ezdoc\Db\Exception\SchemaException;
use Ezdoc\Db\Exception\TransactionException;
use Ezdoc\Db\Grammar\Grammar;
use Ezdoc\Db\Grammar\MysqlGrammar;
use Ezdoc\Db\QueryBuilder;
use Ezdoc\Db\Schema\SchemaManager;
use Ezdoc\Db\Statement;
use mysqli;

/**
 * Ezdoc\Db\Mysqli\MysqliConnection — Connection adapter untuk raw mysqli.
 *
 * Adapter default zero-dep untuk consumer yang sudah punya `mysqli` instance
 * (contoh: konvensi `koneksi.php` global `$conn`). Adapter ini bridge existing
 * runtime ke Connection interface — Repository bisa refactor tanpa breaking
 * consumer bootstrap.
 *
 * ## Grammar detection
 *
 * MariaDB & MySQL sama-sama pakai mysqli driver, tapi SQL dialect sedikit beda
 * (JSON type semantics, ENUM behavior). Constructor auto-detect via
 * `$mysqli->server_info`. Consumer bisa override via `$grammar` argument
 * kalau autodetect salah.
 *
 * ## Backward compatibility guarantee
 *
 * Consumer yang punya `$conn` global dari `koneksi.php`:
 *   $ezdocDb = new MysqliConnection($conn);
 * Semua behavior existing tetap works — mysqli global tidak dimodifikasi,
 * charset/collation/timezone yg sudah di-set consumer preserved.
 *
 * @implementation-notes Adapter pattern standard. Reimplemented from spec.
 *
 * PHP 7.4+ compatible.
 */
final class MysqliConnection implements Connection
{
    /** @var mysqli */
    private $conn;

    /** @var Grammar */
    private $grammar;

    /** @var int Nested transaction counter (savepoint depth). */
    private $txDepth = 0;

    /** @var SchemaManager|null Lazy-init. */
    private $schemaManager;

    /**
     * @param mysqli       $conn    Ready-connected mysqli instance dari consumer bootstrap.
     * @param Grammar|null $grammar Override auto-detected grammar (mysql/mariadb).
     */
    public function __construct(mysqli $conn, ?Grammar $grammar = null)
    {
        $this->conn = $conn;
        $this->grammar = $grammar ?? $this->detectGrammar($conn);
    }

    /**
     * Auto-detect MySQL vs MariaDB via server_info substring.
     * MariaDB grammar (W2) — sementara default ke MysqlGrammar.
     */
    private function detectGrammar(mysqli $conn): Grammar
    {
        // server_info ex: "8.0.35" (MySQL), "10.11.6-MariaDB-log" (MariaDB)
        $info = (string) ($conn->server_info ?? '');
        if (stripos($info, 'mariadb') !== false) {
            // TODO(v0.9.9 W2): return new MariaDbGrammar()
            // Sementara pakai MysqlGrammar — dialect overlap ~95%, differences
            // mostly JSON semantics + ENUM which akan di-fix di MariaDbGrammar.
            return new MysqlGrammar();
        }
        return new MysqlGrammar();
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    public function schemaManager(): SchemaManager
    {
        if ($this->schemaManager === null) {
            // TODO(v0.9.9 W2): MysqliSchemaManager impl
            throw new SchemaException(
                'SchemaManager not yet implemented for MysqliConnection '
                . '(pending v0.9.9 W2). Use raw ALTER via execute() sementara.'
            );
        }
        return $this->schemaManager;
    }

    // ========================================================================
    // Prepare / execute / fetch
    // ========================================================================

    public function prepare(string $sql): Statement
    {
        $stmt = @$this->conn->prepare($sql);
        if ($stmt === false) {
            throw QueryException::withState(
                'MysqliConnection prepare failed: ' . $this->conn->error,
                $sql,
                [],
                $this->conn->sqlstate ?: null
            );
        }
        return new MysqliStatement($stmt, $sql);
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql);
        try {
            return $stmt->execute($params);
        } finally {
            $stmt->close();
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepare($sql);
        try {
            $stmt->execute($params);
            return $stmt->fetch();
        } finally {
            $stmt->close();
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql);
        try {
            $stmt->execute($params);
            return $stmt->fetchAll();
        } finally {
            $stmt->close();
        }
    }

    public function fetchScalar(string $sql, array $params = [])
    {
        $stmt = $this->prepare($sql);
        try {
            $stmt->execute($params);
            return $stmt->fetchScalar();
        } finally {
            $stmt->close();
        }
    }

    public function lastInsertId()
    {
        return (int) $this->conn->insert_id;
    }

    // ========================================================================
    // Transactions
    // ========================================================================

    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try { $this->rollback(); } catch (\Throwable $ignore) { /* rethrow original */ }
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->txDepth === 0) {
            $ok = @$this->conn->begin_transaction();
            if (!$ok) {
                throw new TransactionException(
                    'MysqliConnection beginTransaction failed: ' . $this->conn->error
                );
            }
        } else {
            // Savepoint untuk nested
            $sp = 'ezdoc_sp_' . $this->txDepth;
            if (!@$this->conn->query("SAVEPOINT `$sp`")) {
                throw new TransactionException(
                    "MysqliConnection SAVEPOINT $sp failed: " . $this->conn->error
                );
            }
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth === 0) {
            throw new TransactionException('commit() called without active transaction');
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $ok = @$this->conn->commit();
            if (!$ok) {
                throw new TransactionException(
                    'MysqliConnection commit failed: ' . $this->conn->error
                );
            }
        } else {
            $sp = 'ezdoc_sp_' . $this->txDepth;
            if (!@$this->conn->query("RELEASE SAVEPOINT `$sp`")) {
                throw new TransactionException(
                    "MysqliConnection RELEASE SAVEPOINT $sp failed: " . $this->conn->error
                );
            }
        }
    }

    public function rollback(): void
    {
        if ($this->txDepth === 0) {
            throw new TransactionException('rollback() called without active transaction');
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $ok = @$this->conn->rollback();
            if (!$ok) {
                throw new TransactionException(
                    'MysqliConnection rollback failed: ' . $this->conn->error
                );
            }
        } else {
            $sp = 'ezdoc_sp_' . $this->txDepth;
            if (!@$this->conn->query("ROLLBACK TO SAVEPOINT `$sp`")) {
                throw new TransactionException(
                    "MysqliConnection ROLLBACK TO SAVEPOINT $sp failed: " . $this->conn->error
                );
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * @return mysqli
     */
    public function raw()
    {
        return $this->conn;
    }
}
