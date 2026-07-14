<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * Text column type — untuk long-form string content (>= 64KB range).
 *
 * DB SQL type per platform (di Grammar):
 *   - MySQL/MariaDB : `TEXT` / `LONGTEXT` (byte limits: 64KB / 4GB)
 *   - Postgres      : `TEXT` (unlimited)
 *   - SQLite        : `TEXT` (unlimited)
 *   - SQL Server    : `NVARCHAR(MAX)` (2GB)
 *
 * Beda dgn StringType (VARCHAR): TEXT ada size overhead di beberapa engine
 * (MySQL storage engine-dependent), jadi pakai StringType untuk column pendek.
 */
final class TextType implements Type
{
    public function name(): string { return 'text'; }

    public function toPhp($value)
    {
        if ($value === null) return null;
        return (string) $value;
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        return (string) $value;
    }
}
