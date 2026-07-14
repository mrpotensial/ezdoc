<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

use Ezdoc\Db\Exception\DbException;

/**
 * DateTime column type — PHP `DateTimeInterface` <-> `YYYY-MM-DD HH:MM:SS` string.
 *
 * DB SQL type per platform (di Grammar):
 *   - MySQL/MariaDB : `DATETIME` (fractional sec via `DATETIME(6)`)
 *   - Postgres      : `TIMESTAMP WITHOUT TIME ZONE`
 *   - SQLite        : `TEXT` — ISO-8601 storage convention
 *   - SQL Server    : `DATETIME2`
 *
 * ## Timezone handling
 *
 * Simpan dalam UTC by convention (canonical form untuk cross-timezone systems).
 * Consumer yang mau timezone-aware wajib convert sebelum insert dan setelah
 * fetch. Postgres user boleh switch ke `TIMESTAMP WITH TIME ZONE` via subclass.
 */
final class DateTimeType implements Type
{
    /** Format canonical yang selalu accepted oleh 5 target DB. */
    public const FORMAT = 'Y-m-d H:i:s';

    public function name(): string { return 'datetime'; }

    public function toPhp($value)
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof \DateTimeInterface) return $value;
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception $e) {
            throw new DbException(
                'DateTimeType::toPhp — invalid datetime: ' . $e->getMessage(),
                ['raw' => (string) $value],
                $e
            );
        }
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        if ($value instanceof \DateTimeInterface) return $value->format(self::FORMAT);
        // Try parse untuk convenience — kalau caller pass string yang valid.
        try {
            $dt = new \DateTimeImmutable((string) $value);
            return $dt->format(self::FORMAT);
        } catch (\Exception $e) {
            throw new DbException(
                'DateTimeType::toDb — cannot convert to datetime: ' . $e->getMessage(),
                ['raw' => $value],
                $e
            );
        }
    }
}
