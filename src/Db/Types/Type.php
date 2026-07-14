<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

/**
 * Ezdoc\Db\Types\Type — PHP <-> DB value conversion contract.
 *
 * Setiap column punya Type yang tahu:
 *   1. Canonical name (`json`, `uuid`, `string`, `bigint`, dll) — dipakai
 *      Grammar untuk resolve ke SQL type per platform.
 *   2. Value conversion: PHP → DB (untuk bind), DB → PHP (untuk fetch).
 *
 * ## Mapping ke SQL type ada di Grammar, bukan Type
 *
 * Type object TIDAK tahu "di MySQL saya jadi JSON, di SQLite jadi TEXT". Itu
 * tanggung jawab `Grammar::mapType(Type $type, ColumnDef $col)`. Alasan:
 *   - Type-per-platform explosion (5 grammar × 12 type = 60 combinations)
 *   - Grammar is the natural place for platform SQL knowledge
 *   - Type stays pure — just PHP ↔ DB conversion logic
 *
 * ## Contoh: JsonType
 *
 * PHP array → JSON string (via json_encode) untuk INSERT/UPDATE.
 * JSON string → PHP array (via json_decode) untuk SELECT fetch.
 *
 * Grammar map:
 *   MysqlGrammar::mapType(JsonType) → 'JSON'
 *   SqliteGrammar::mapType(JsonType) → 'TEXT' + CHECK json_valid(col)
 *   PostgresGrammar::mapType(JsonType) → 'JSONB'
 *
 * @implementation-notes Split responsibility (Type = PHP-side, Grammar = SQL-side)
 *   mirrored dari Doctrine DBAL Types + Platforms design. Reimplemented from spec.
 */
interface Type
{
    /**
     * Canonical name — stable identifier across grammars.
     *
     * Convention: kebab-case, lowercase.
     * Examples: `string`, `integer`, `bigint`, `boolean`, `json`, `uuid`,
     *   `datetime`, `date`, `time`, `text`, `blob`, `decimal`, `float`, `enum`.
     */
    public function name(): string;

    /**
     * Convert dari DB-side representation ke PHP-side.
     *
     * Called by Connection setelah fetch row — sebelum row diserahkan ke caller.
     * Default identity (untuk StringType/IntegerType).
     *
     * @param mixed $value Nilai raw dari driver.
     * @return mixed Nilai PHP-friendly.
     */
    public function toPhp($value);

    /**
     * Convert dari PHP-side ke DB-side untuk bind param.
     *
     * Called by Connection saat prepare/execute bind param.
     *
     * @param mixed $value Nilai PHP dari caller.
     * @return mixed Nilai yang cocok di-bind ke driver (typically string/int/null).
     */
    public function toDb($value);
}
