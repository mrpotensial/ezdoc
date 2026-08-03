<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * UUID column type.
 *
 * DB SQL type per platform (di Grammar):
 *   - MySQL/MariaDB : `CHAR(36)` — canonical hyphenated form
 *   - Postgres      : `UUID` (native, indexable, 16-byte storage)
 *   - SQLite        : `TEXT` — 36-char form
 *   - SQL Server    : `UNIQUEIDENTIFIER` — case-insensitive comparison
 *
 * PHP-side conversion adalah identity (string). Validation format optional
 * di caller. Kalau perlu lebih strict, `Ezdoc\UUID` helper punya `isValid()`.
 */
final class UuidType implements Type
{
    public function name(): string { return 'uuid'; }

    public function toPhp($value)
    {
        if ($value === null) return null;
        // Some Postgres drivers return uppercase; normalize to lowercase for
        // consistent comparison (RFC 4122 recommends lowercase).
        return strtolower((string) $value);
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        return strtolower((string) $value);
    }
}
