<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * String column type — VARCHAR/CHAR/NVARCHAR di platform level (resolve di Grammar).
 *
 * Default identity conversion; null preserved.
 */
final class StringType implements Type
{
    public function name(): string { return 'string'; }

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
