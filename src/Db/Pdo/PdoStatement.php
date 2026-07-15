<?php

declare(strict_types=1);

namespace Ezdoc\Db\Pdo;

use Ezdoc\Db\Exception\QueryException;
use Ezdoc\Db\Statement;
use PDO;
use PDOException;
use PDOStatement as NativePdoStatement;

/**
 * Ezdoc\Db\Pdo\PdoStatement — Statement impl untuk PdoConnection.
 *
 * Wrap `PDOStatement` supaya caller talk lewat interface `Statement`. PDO
 * lebih ergonomic dari mysqli — bisa pass params array langsung ke execute()
 * tanpa manual bind_param type-string.
 *
 * @implementation-notes Standard PDO wrapping pattern. Reimplemented from spec.
 *
 * PHP 7.4+ compatible.
 */
final class PdoStatement implements Statement
{
    /** @var NativePdoStatement */
    private $stmt;

    /** @var string */
    private $sql;

    /** @var bool True setelah execute() dipanggil (untuk validate fetch order). */
    private $executed = false;

    public function __construct(NativePdoStatement $stmt, string $sql)
    {
        $this->stmt = $stmt;
        $this->sql = $sql;
    }

    public function execute(array $params = []): int
    {
        try {
            $ok = $this->stmt->execute($params !== [] ? array_values($params) : null);
        } catch (PDOException $e) {
            throw QueryException::withState(
                'PdoStatement execute failed: ' . $e->getMessage(),
                $this->sql,
                $params,
                $e->getCode() !== 0 ? (string) $e->getCode() : null,
                $e
            );
        }
        if (!$ok) {
            $err = $this->stmt->errorInfo();
            throw QueryException::withState(
                'PdoStatement execute returned false: ' . ($err[2] ?? 'unknown'),
                $this->sql,
                $params,
                $err[0] ?? null
            );
        }
        $this->executed = true;
        return $this->stmt->rowCount();
    }

    public function fetch(): ?array
    {
        if (!$this->executed) return null;
        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function fetchAll(): array
    {
        if (!$this->executed) return [];
        $rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function fetchScalar()
    {
        if (!$this->executed) return null;
        $v = $this->stmt->fetchColumn(0);
        return $v === false ? null : $v;
    }

    public function close(): void
    {
        try { $this->stmt->closeCursor(); } catch (\Throwable $ignore) { /* noop */ }
    }

    public function __destruct()
    {
        try { $this->close(); } catch (\Throwable $ignore) { /* noop */ }
    }
}
