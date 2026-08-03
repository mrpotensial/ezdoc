<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * Boolean column type.
 *
 * DB representation:
 *   - MySQL/MariaDB: TINYINT(1) — 0 / 1
 *   - Postgres: BOOLEAN — 't' / 'f' (or 1 / 0)
 *   - SQLite: INTEGER — 0 / 1
 *   - SQL Server: BIT — 0 / 1
 *
 * Grammar handle SQL type mapping. Type-level conversion normalize semua ke
 * PHP bool.
 */
final class BooleanType implements Type
{
    public function name(): string { return 'boolean'; }

    public function toPhp($value)
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value;
        // Accept both '0'/'1' (mysqli, sqlite, sqlsrv) and 't'/'f' (pgsql non-native)
        if (is_string($value)) {
            $v = strtolower($value);
            if ($v === 't' || $v === 'true')  return true;
            if ($v === 'f' || $v === 'false') return false;
        }
        return (bool) (int) $value;
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        return $value ? 1 : 0;
    }
}
