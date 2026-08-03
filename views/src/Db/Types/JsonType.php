<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

use Ezdoc\Db\Exception\DbException;

/**
 * JSON column type — PHP array/object <-> JSON string.
 *
 * DB SQL type per platform (di Grammar):
 *   - MySQL 5.7+     : `JSON` (native, with SQL functions)
 *   - MariaDB 10.2+  : `JSON` alias to `LONGTEXT` (with CHECK constraint on older)
 *   - Postgres 12+   : `JSONB` (preferred over JSON — indexable, deduplicated keys)
 *   - SQLite 3.9+    : `TEXT` + optional `CHECK (json_valid(col))`
 *   - SQL Server 2016+: `NVARCHAR(MAX)` + optional `CHECK (ISJSON(col) = 1)`
 *
 * ## PHP-side conversion
 *
 * `toPhp`: decode as associative array (`json_decode(..., true)`). Kalau null
 * atau empty string, return null.
 *
 * `toDb`: encode dengan `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` untuk
 * output yang readable + reproducible (canonical form untuk hashing).
 *
 * ## Canonical ordering (untuk content_hash reproducibility)
 *
 * `toDb` TIDAK sort keys. Kalau caller butuh canonical form untuk hashing,
 * caller sendiri yang sort dulu sebelum pass ke Connection. Alasan: sort di
 * Type layer berarti setiap fetch → update round-trip bakal reorder JSON,
 * annoying untuk data yang order-sensitive.
 */
final class JsonType implements Type
{
    public function name(): string { return 'json'; }

    public function toPhp($value)
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value; // Postgres native JSONB → array (some drivers)
        $decoded = json_decode((string) $value, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new DbException(
                'JsonType::toPhp — invalid JSON: ' . json_last_error_msg(),
                ['raw' => (string) $value]
            );
        }
        return $decoded;
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        if (is_string($value)) {
            // Assume caller already encoded — trust it, but validate.
            $decoded = json_decode($value, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new DbException(
                    'JsonType::toDb — string is not valid JSON: ' . json_last_error_msg(),
                    ['raw' => $value]
                );
            }
            return $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new DbException(
                'JsonType::toDb — json_encode failed: ' . json_last_error_msg()
            );
        }
        return $encoded;
    }
}
