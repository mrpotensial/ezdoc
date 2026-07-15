<?php

declare(strict_types=1);

namespace Ezdoc\Db\Mysqli;

use Ezdoc\Db\Exception\QueryException;
use Ezdoc\Db\Statement;
use mysqli_stmt;

/**
 * Ezdoc\Db\Mysqli\MysqliStatement — Statement impl untuk MysqliConnection.
 *
 * Wrap `mysqli_stmt` supaya caller talk lewat interface `Statement`. Handle:
 *   - Positional param binding dgn auto-detect type (i/s/d)
 *   - Fetch dgn `get_result()` → assoc array
 *   - Cleanup dgn `close()`
 *
 * ## bind_param quirks
 *
 * `mysqli_stmt_bind_param()` di PHP <8.1 butuh references untuk semua param
 * setelah pertama. Kita pakai `call_user_func_array` + `array_map` ref trick
 * yang universal (works semua PHP 7.4+/8.x).
 *
 * @implementation-notes Wrap pattern standard mysqli_stmt handling.
 *   Reimplemented from spec.
 *
 * PHP 7.4+ compatible.
 */
final class MysqliStatement implements Statement
{
    /** @var mysqli_stmt */
    private $stmt;

    /** @var string SQL asli — untuk error context. */
    private $sql;

    /** @var \mysqli_result|null Hasil terakhir setelah execute (SELECT). */
    private $result;

    public function __construct(mysqli_stmt $stmt, string $sql)
    {
        $this->stmt = $stmt;
        $this->sql = $sql;
    }

    public function execute(array $params = []): int
    {
        // Bind params
        if ($params !== []) {
            $types = '';
            foreach ($params as $p) {
                if (is_int($p) || is_bool($p)) $types .= 'i';
                elseif (is_float($p))          $types .= 'd';
                elseif (is_null($p))           $types .= 's'; // driver handle as NULL
                else                           $types .= 's';
            }
            // Copy params ke variables agar bisa di-refs
            $vars = array_values($params);
            $refs = [];
            foreach ($vars as $i => $v) $refs[$i] = &$vars[$i];
            array_unshift($refs, $types);
            $ok = @call_user_func_array([$this->stmt, 'bind_param'], $refs);
            if (!$ok) {
                throw QueryException::withState(
                    'MysqliStatement bind_param failed: ' . $this->stmt->error,
                    $this->sql,
                    $params,
                    $this->stmt->sqlstate ?: null
                );
            }
        }

        $ok = @$this->stmt->execute();
        if (!$ok) {
            throw QueryException::withState(
                'MysqliStatement execute failed: ' . $this->stmt->error,
                $this->sql,
                $params,
                $this->stmt->sqlstate ?: null
            );
        }

        // Kalau SELECT, capture result; kalau bukan return affected rows.
        $meta = $this->stmt->result_metadata();
        if ($meta instanceof \mysqli_result) {
            $meta->close();
            $this->result = $this->stmt->get_result();
            return 0;
        }
        return $this->stmt->affected_rows;
    }

    public function fetch(): ?array
    {
        if ($this->result === null) return null;
        $row = $this->result->fetch_assoc();
        return $row === null ? null : $row;
    }

    public function fetchAll(): array
    {
        if ($this->result === null) return [];
        $rows = [];
        while (($row = $this->result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetchScalar()
    {
        if ($this->result === null) return null;
        $row = $this->result->fetch_array(MYSQLI_NUM);
        if ($row === null || !isset($row[0])) return null;
        return $row[0];
    }

    public function close(): void
    {
        if ($this->result !== null) {
            $this->result->close();
            $this->result = null;
        }
        @$this->stmt->close();
    }

    public function __destruct()
    {
        // Best-effort cleanup — supress kalau sudah closed
        try { $this->close(); } catch (\Throwable $ignore) { /* noop */ }
    }
}
