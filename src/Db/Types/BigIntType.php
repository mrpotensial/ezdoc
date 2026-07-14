<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * Big integer column type — untuk BIGINT (64-bit).
 *
 * ## Preserved as string (not cast to int)
 *
 * Di 32-bit PHP systems, `(int)$value` untuk value > 2^31 akan overflow ke
 * float (loss precision). Di 64-bit systems, int native fit sampai 2^63 tapi
 * beberapa DB (Postgres, BIGINT UNSIGNED di MySQL) bisa exceed 2^63.
 *
 * Aman-nya: preserve sebagai string. Repository yang tahu domain-nya bisa cast
 * ke int/GMP kalau perlu.
 *
 * Doctrine DBAL punya pola sama untuk BigIntType — reason yg sama.
 */
final class BigIntType implements Type
{
    public function name(): string { return 'bigint'; }

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
