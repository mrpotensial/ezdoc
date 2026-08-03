<?php

declare(strict_types=1);

namespace Ezdoc\Db\Exception;

use Ezdoc\Exceptions\EzdocException;

/**
 * Base exception untuk semua error di DB layer (`Ezdoc\Db\*`).
 *
 * Extends `EzdocException` supaya `catch (EzdocException $e)` di caller catch
 * juga DB errors. Subclass untuk kategori spesifik: ConnectionException,
 * QueryException, TransactionException, SchemaException.
 *
 * @implementation-notes Structure aligned dengan Doctrine DBAL Exception
 *   hierarchy. Reimplemented from spec.
 */
class DbException extends EzdocException
{
    /** @var int HTTP 500 default — DB error internal, jangan expose ke user. */
    protected $statusCode = 500;

    /**
     * Factory yg wrap original driver exception (mysqli warning, PDOException, dll)
     * jadi typed DbException dengan SQL + params attached ke context.
     *
     * @param string              $message
     * @param string              $sql
     * @param array<int,mixed>    $params
     * @param \Throwable|null     $previous
     */
    public static function fromDriver(string $message, string $sql = '', array $params = [], ?\Throwable $previous = null): self
    {
        $ctx = [];
        if ($sql !== '')       $ctx['sql'] = $sql;
        if ($params !== [])    $ctx['params'] = $params;
        if ($previous !== null) $ctx['driver_error'] = $previous->getMessage();
        return new static($message, $ctx, $previous);
    }
}
