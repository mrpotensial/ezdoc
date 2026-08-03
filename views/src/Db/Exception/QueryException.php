<?php

declare(strict_types=1);

namespace Ezdoc\Db\Exception;

/**
 * Thrown ketika SQL query gagal execute.
 *
 * Contoh:
 *   - Syntax error di SQL
 *   - Constraint violation (unique, foreign key, not-null)
 *   - Column tidak ada (schema drift)
 *   - Deadlock / lock timeout
 *
 * Untuk constraint violation spesifik, subclass ini nanti (v0.9.9+) bisa
 * di-specialize: UniqueViolationException, ForeignKeyViolationException, dsb.
 * Untuk sekarang plain QueryException cukup — caller inspect via SQLSTATE code.
 */
final class QueryException extends DbException
{
    /** @var string|null SQLSTATE 5-char code (dari driver). Null kalau driver
     *  tidak expose (mysqli lama). */
    private $sqlState;

    /**
     * @param string              $message
     * @param string              $sql
     * @param array<int,mixed>    $params
     * @param string|null         $sqlState SQLSTATE code dari driver
     * @param \Throwable|null     $previous
     */
    public static function withState(
        string $message,
        string $sql,
        array $params,
        ?string $sqlState,
        ?\Throwable $previous = null
    ): self {
        $ctx = ['sql' => $sql, 'params' => $params];
        if ($sqlState !== null) $ctx['sql_state'] = $sqlState;
        if ($previous !== null) $ctx['driver_error'] = $previous->getMessage();
        $e = new self($message, $ctx, $previous);
        $e->sqlState = $sqlState;
        return $e;
    }

    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }
}
