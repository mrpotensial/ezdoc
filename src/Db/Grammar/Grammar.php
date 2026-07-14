<?php

declare(strict_types=1);

namespace Ezdoc\Db\Grammar;

use Ezdoc\Db\Schema\Blueprint;
use Ezdoc\Db\Schema\ColumnDef;
use Ezdoc\Db\Schema\IndexDef;
use Ezdoc\Db\Schema\ForeignKeyDef;
use Ezdoc\Db\Types\Type;

/**
 * Ezdoc\Db\Grammar\Grammar — platform-specific SQL dialect emitter.
 *
 * Setiap DB target punya Grammar concrete: MysqlGrammar, MariaDbGrammar,
 * SqliteGrammar, PostgresGrammar, SqlServerGrammar. Interface ini kontrak
 * portable-nya.
 *
 * ## Responsibilities
 *
 * 1. **Identifier quoting** — `wrapIdentifier(name)` return `` `col` `` (MySQL)
 *    atau `"col"` (Postgres) atau `[col]` (SQL Server).
 * 2. **Type mapping** — `mapType(Type $t, ColumnDef $c)` return SQL type
 *    declaration (mis. `JSON`, `TEXT` + CHECK, `JSONB`, `NVARCHAR(MAX)`).
 * 3. **DDL compilation** — Blueprint → CREATE TABLE / ALTER TABLE / DROP TABLE.
 * 4. **DML compilation** — QueryBuilder → SELECT / INSERT / UPDATE / DELETE
 *    dengan platform-specific syntax (LIMIT/OFFSET vs OFFSET…FETCH NEXT).
 *
 * ## Feature flags
 *
 * Beberapa fitur tidak universal — Grammar expose via `supports*()`:
 *   - `supportsNativeJson()` — MySQL/MariaDB/Postgres = true, SQLite/SQLServer = false
 *   - `supportsNativeUuid()` — Postgres/SQLServer = true, others = false (pakai CHAR/UUID string)
 *   - `supportsNativeEnum()` — MySQL/MariaDB/Postgres = true, others = CHECK constraint
 *   - `supportsSavepoints()` — semua T2 target = true
 *
 * Caller (Blueprint/QueryBuilder) branch berdasarkan flag ini.
 *
 * @implementation-notes Split responsibility per-Grammar mirroring Doctrine DBAL
 *   AbstractPlatform + concrete platforms. Reimplemented from spec, dgn SQL
 *   formulas studied dari DBAL source (MIT). No vendored code.
 */
interface Grammar
{
    /** Canonical name — `mysql`, `mariadb`, `sqlite`, `postgres`, `sqlserver`. */
    public function name(): string;

    /**
     * Quote identifier (table/column name) untuk safe embedding di SQL.
     *
     * Handle dot-notation (`schema.table`, `table.column`) — split + quote per segment.
     */
    public function wrapIdentifier(string $identifier): string;

    /**
     * String literal quoting (untuk value yang embed langsung, bukan bind param).
     *
     * PLEASE use bind params dulu — quoting literal invite injection. Method ini
     * untuk edge case DDL where bind tidak available (DEFAULT clause, dsb).
     */
    public function quoteString(string $value): string;

    /**
     * Map canonical Type + column def ke SQL column type declaration.
     *
     * Return string mentah untuk embed di CREATE TABLE — mis. `VARCHAR(255)`,
     * `BIGINT UNSIGNED AUTO_INCREMENT`, `JSON`, `TEXT CHECK (json_valid(...))`.
     *
     * @param Type      $type
     * @param ColumnDef $col Column def dari Blueprint (untuk length, nullable, dsb)
     */
    public function mapType(Type $type, ColumnDef $col): string;

    /**
     * Compile Blueprint → CREATE TABLE statement(s).
     *
     * Return list — some grammars (SQLite) butuh multi-statement untuk table +
     * indexes (SQLite tidak inline CREATE INDEX di CREATE TABLE).
     *
     * @return list<string>
     */
    public function compileCreateTable(Blueprint $blueprint): array;

    /**
     * Compile DROP TABLE statement.
     */
    public function compileDropTable(string $tableName, bool $ifExists = true): string;

    /**
     * Compile CREATE INDEX statement (untuk index yang added post-CREATE-TABLE).
     */
    public function compileCreateIndex(string $tableName, IndexDef $index): string;

    /**
     * Compile FOREIGN KEY declaration untuk embed di CREATE TABLE.
     *
     * (Beberapa grammar butuh separate ALTER TABLE ADD CONSTRAINT — Grammar concrete
     * decide, callback via compileCreateTable().)
     */
    public function compileForeignKey(ForeignKeyDef $fk): string;

    // Feature flags
    public function supportsNativeJson(): bool;
    public function supportsNativeUuid(): bool;
    public function supportsNativeEnum(): bool;
    public function supportsSavepoints(): bool;
}
