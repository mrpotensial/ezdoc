<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * Integer column type (up to 32-bit signed / PHP native int).
 *
 * Untuk 64-bit values yang berpotensi exceed PHP int di 32-bit systems, pakai
 * `BigIntType` yang preserve sebagai string.
 */
final class IntegerType implements Type
{
    public function name(): string { return 'integer'; }

    public function toPhp($value)
    {
        if ($value === null) return null;
        return (int) $value;
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        return (int) $value;
    }
}
