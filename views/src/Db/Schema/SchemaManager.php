<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\SchemaManager — introspection + DDL execution facade.
 *
 * Diperoleh dari `Connection::schemaManager()`. Dipakai oleh:
 *   - Migration runner (`lib/migrations.php`) — create/alter/drop table
 *   - `cli/spec-dump.php` — introspect current schema untuk regenerate spec
 *   - Consumer setup script — check schema drift terhadap Blueprint definition
 *
 * ## Responsibilities
 *
 * 1. **Introspection**: list tables, describe columns/indexes/foreign keys
 * 2. **DDL execution**: run Blueprint (via Grammar) sebagai CREATE/ALTER
 * 3. **Existence check**: hasTable/hasColumn — untuk idempotent migration
 *
 * Note: kompleksitas Comparator (diff old vs new schema → ALTER statements) DI
 * LUAR SchemaManager — itu di `Schema\Comparator` yang tugasnya emit ALTER
 * plan, SchemaManager tinggal `executeAlter()` plan itu.
 */
interface SchemaManager
{
    /** @return list<string> Table names */
    public function listTables(): array;

    public function hasTable(string $tableName): bool;

    public function hasColumn(string $tableName, string $columnName): bool;

    /**
     * Introspect existing table → Blueprint object (untuk diff dgn source Blueprint).
     *
     * MVP v0.9.9: not implemented (throw). Migration runner untuk sekarang cukup
     * `hasTable()` + `create` guard. Full introspection di v0.9.10+.
     */
    public function describeTable(string $tableName): Blueprint;

    /**
     * Execute Blueprint sebagai CREATE TABLE (via Grammar compile).
     */
    public function createTable(Blueprint $blueprint): void;

    /**
     * Drop table (idempotent kalau $ifExists=true).
     */
    public function dropTable(string $tableName, bool $ifExists = true): void;

    /**
     * Execute raw DDL statements (batch). Untuk cases yang Blueprint belum cover.
     *
     * @param list<string> $sqls
     */
    public function executeRaw(array $sqls): void;
}
