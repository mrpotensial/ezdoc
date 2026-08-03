<?php

declare(strict_types=1);

namespace Ezdoc\Db\Pdo;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Exception\ConnectionException;
use Ezdoc\Db\Exception\QueryException;
use Ezdoc\Db\Exception\SchemaException;
use Ezdoc\Db\Exception\TransactionException;
use Ezdoc\Db\Grammar\Grammar;
use Ezdoc\Db\Grammar\MariaDbGrammar;
use Ezdoc\Db\Grammar\MysqlGrammar;
use Ezdoc\Db\Grammar\PostgresGrammar;
use Ezdoc\Db\Grammar\SqliteGrammar;
use Ezdoc\Db\Grammar\SqlServerGrammar;
use Ezdoc\Db\QueryBuilder;
use Ezdoc\Db\Schema\SchemaManager;
use Ezdoc\Db\Statement;
use PDO;
use PDOException;

/**
 * Ezdoc\Db\Pdo\PdoConnection — Connection adapter untuk PDO (universal).
 *
 * Wrap `\PDO` object supaya semua 4 driver PDO (mysql, sqlite, pgsql, sqlsrv)
 * bisa dipakai lewat Connection interface yg sama. Enabler `App::demo()`
 * zero-config SQLite mode + kompatibilitas ke Postgres/SQL Server tanpa
 * consumer harus install extension baru (PDO built-in di PHP).
 *
 * ## Grammar auto-detect via PDO driver name
 *
 * `$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)` returns 'mysql', 'sqlite',
 * 'pgsql', atau 'sqlsrv'. Untuk 'mysql', kita cek server_version untuk
 * detect MariaDB (fork sharing driver name).
 *
 * ## Factory helper
 *
 * `PdoConnection::fromDsn($dsn, $user, $pass, $options)` construct PDO +
 * Connection sekali call — untuk consumer yg tidak punya PDO instance.
 *
 * @implementation-notes Standard PDO wrapping pattern. Reimplemented from spec.
 *
 * PHP 7.4+ compatible.
 */
final class PdoConnection implements Connection
{
    /** @var PDO */
    private $pdo;

    /** @var Grammar */
    private $grammar;

    /** @var int Nested transaction counter (savepoint depth). */
    private $txDepth = 0;

    /** @var SchemaManager|null Lazy-init. */
    private $schemaManager;

    /**
     * @param PDO          $pdo     Ready-connected PDO instance.
     * @param Grammar|null $grammar Override auto-detected grammar.
     */
    public function __construct(PDO $pdo, ?Grammar $grammar = null)
    {
        $this->pdo = $pdo;
        // Force exception mode — kita catch + wrap ke ezdoc exceptions.
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->grammar = $grammar ?? $this->detectGrammar($pdo);
    }

    /**
     * Factory dari DSN — untuk consumer yg tidak punya PDO instance ready.
     *
     * @param string             $dsn    e.g. 'sqlite::memory:', 'mysql:host=localhost;dbname=ezdoc'
     * @param string|null        $user
     * @param string|null        $pass
     * @param array<int,mixed>   $options PDO options
     * @throws ConnectionException
     */
    public static function fromDsn(string $dsn, ?string $user = null, ?string $pass = null, array $options = []): self
    {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new ConnectionException(
                'PdoConnection::fromDsn failed: ' . $e->getMessage(),
                ['dsn' => $dsn],
                $e
            );
        }
        return new self($pdo);
    }

    private function detectGrammar(PDO $pdo): Grammar
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        switch ($driver) {
            case 'sqlite':
                // Enable foreign key enforcement (SQLite default: off)
                @$pdo->exec('PRAGMA foreign_keys = ON');
                return new SqliteGrammar();
            case 'pgsql':
                return new PostgresGrammar();
            case 'sqlsrv':
            case 'dblib':
                return new SqlServerGrammar();
            case 'mysql':
                // Detect MariaDB via server_version substring
                $ver = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                if (stripos($ver, 'mariadb') !== false) {
                    return new MariaDbGrammar();
                }
                return new MysqlGrammar();
            default:
                throw new ConnectionException(
                    "PdoConnection: unsupported PDO driver '$driver' "
                    . '(supported: mysql, sqlite, pgsql, sqlsrv)'
                );
        }
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    public function schemaManager(): SchemaManager
    {
        if ($this->schemaManager === null) {
            // TODO(v0.9.9 W2 sisa): SchemaManager PDO impl — untuk sekarang throw
            // Migration runner sementara pakai execute() raw.
            throw new SchemaException(
                'SchemaManager not yet implemented for PdoConnection '
                . '(pending v0.9.9 W2 sisa). Use raw ALTER via execute() sementara.'
            );
        }
        return $this->schemaManager;
    }

    // ========================================================================
    // Prepare / execute / fetch
    // ========================================================================

    public function prepare(string $sql): Statement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
        } catch (PDOException $e) {
            throw QueryException::withState(
                'PdoConnection prepare failed: ' . $e->getMessage(),
                $sql,
                [],
                $e->getCode() !== 0 ? (string) $e->getCode() : null,
                $e
            );
        }
        if ($stmt === false) {
            throw QueryException::withState('PdoConnection prepare returned false', $sql, [], null);
        }
        return new PdoStatement($stmt, $sql);
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
        // Postgres returns string yg bisa exceed PHP int; consumer cast sendiri.
        return $this->pdo->lastInsertId();
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
        try {
            if ($this->txDepth === 0) {
                $this->pdo->beginTransaction();
            } else {
                $sp = $this->savepointName($this->txDepth);
                $this->pdo->exec('SAVEPOINT ' . $sp);
            }
        } catch (PDOException $e) {
            throw new TransactionException(
                'PdoConnection beginTransaction failed: ' . $e->getMessage(),
                [],
                $e
            );
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth === 0) {
            throw new TransactionException('commit() called without active transaction');
        }
        $this->txDepth--;
        try {
            if ($this->txDepth === 0) {
                $this->pdo->commit();
            } else {
                $sp = $this->savepointName($this->txDepth);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $sp);
            }
        } catch (PDOException $e) {
            throw new TransactionException(
                'PdoConnection commit failed: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    public function rollback(): void
    {
        if ($this->txDepth === 0) {
            throw new TransactionException('rollback() called without active transaction');
        }
        $this->txDepth--;
        try {
            if ($this->txDepth === 0) {
                $this->pdo->rollBack();
            } else {
                $sp = $this->savepointName($this->txDepth);
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $sp);
            }
        } catch (PDOException $e) {
            throw new TransactionException(
                'PdoConnection rollback failed: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    private function savepointName(int $depth): string
    {
        // Quote sesuai grammar untuk safe embedding (mostly identifier chars only)
        return $this->grammar->wrapIdentifier('ezdoc_sp_' . $depth);
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * @return PDO
     */
    public function raw()
    {
        return $this->pdo;
    }
}
