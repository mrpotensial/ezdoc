<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

use Ezdoc\Db\Exception\DbException;

/**
 * Enum column type — one-of-N string values.
 *
 * DB SQL type per platform (di Grammar):
 *   - MySQL/MariaDB : native `ENUM('a','b','c')` — stored as integer index
 *   - Postgres      : native `CREATE TYPE ... AS ENUM (...)` — first-class type
 *   - SQLite        : `TEXT` + `CHECK (col IN ('a','b','c'))` — no native enum
 *   - SQL Server    : `NVARCHAR(N)` + `CHECK` constraint — no native enum
 *
 * PHP-side: identity, tapi validate value ∈ allowed set kalau constructor kasih.
 *
 * Untuk column dgn dynamic enum (values di runtime), gunakan constructor
 * argument. Untuk static enum (per-schema), Blueprint akan `enum(name, ['a','b'])`
 * dan Grammar-nya yang emit CHECK/native enum.
 */
final class EnumType implements Type
{
    /** @var list<string>|null Allowed values (null = tidak validate). */
    private $allowed;

    /**
     * @param list<string>|null $allowed Whitelist values. Null = no validation
     *   di Type layer (validation di DB CHECK / native ENUM).
     */
    public function __construct(?array $allowed = null)
    {
        $this->allowed = $allowed;
    }

    public function name(): string { return 'enum'; }

    /** @return list<string>|null */
    public function getAllowed(): ?array
    {
        return $this->allowed;
    }

    public function toPhp($value)
    {
        if ($value === null) return null;
        $v = (string) $value;
        $this->validate($v);
        return $v;
    }

    public function toDb($value)
    {
        if ($value === null) return null;
        $v = (string) $value;
        $this->validate($v);
        return $v;
    }

    private function validate(string $v): void
    {
        if ($this->allowed === null) return;
        if (!in_array($v, $this->allowed, true)) {
            throw new DbException(
                'EnumType — value not in allowed set',
                ['value' => $v, 'allowed' => $this->allowed]
            );
        }
    }
}
