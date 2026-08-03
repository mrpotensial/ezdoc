<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

use Ezdoc\Db\Connection;

/**
 * Ezdoc\Db\Schema\ColumnIntrospector — introspect + cache existing columns
 * per table.
 *
 * Solve **schema drift**: consumer DB might not have all columns declared di
 * Blueprint (older migration applied, newer migration skipped karena
 * CREATE TABLE IF NOT EXISTS). Repository need to know which columns actually
 * exist untuk avoid "Unknown column" errors di SELECT.
 *
 * ## Industry precedent
 *
 * Doctrine DBAL `SchemaManager::listTableColumns()` — probe pg_catalog /
 * information_schema, return Column[] with metadata. Repositories use this
 * for adaptive queries.
 *
 * ## Query platform per grammar
 *
 * - MySQL/MariaDB: `SHOW COLUMNS FROM table` — universal
 * - Postgres: `SELECT column_name FROM information_schema.columns WHERE table_name = ?`
 * - SQLite: `PRAGMA table_info(table)`
 * - SQL Server: `SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?`
 *
 * v0.9.9 MVP: SHOW COLUMNS (MySQL/MariaDB path). Fallback ke information_schema
 * standard untuk grammar lain. Full per-Grammar impl di v0.9.10.
 *
 * ## Cache
 *
 * Per-request in-memory. Consumer boleh reuse Introspector across calls —
 * hasilnya cached per (connection, table) pair. Cache tidak persistent
 * (bootstrap ulang = re-probe).
 *
 * ## Usage
 *
 * ```php
 * $intro = new ColumnIntrospector($conn);
 * $cols = $intro->columns('ezdoc_templates');   // ['id', 'uuid', 'name', ...]
 * $has  = $intro->hasColumn('ezdoc_templates', 'content_hash'); // true/false
 *
 * // Adaptive SELECT — hanya kolom yang exist:
 * $desired = ['id', 'uuid', 'content_hash', 'metadata'];
 * $existing = array_values(array_intersect($desired, $intro->columns('ezdoc_templates')));
 * $sql = 'SELECT ' . implode(', ', $existing) . ' FROM ezdoc_templates WHERE id = ?';
 * ```
 *
 * PHP 7.4+ compatible.
 */
final class ColumnIntrospector
{
    /** @var Connection */
    private $db;

    /** @var array<string, list<string>> Cached: table → column names */
    private $cache = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Return list of column names untuk table. Cached after first call.
     *
     * @return list<string>
     */
    public function columns(string $table): array
    {
        if (isset($this->cache[$table])) return $this->cache[$table];

        $grammar = $this->db->grammar()->name();
        $cols = [];
        try {
            if ($grammar === 'mysql' || $grammar === 'mariadb') {
                // SHOW COLUMNS returns rows with 'Field' key
                $rows = $this->db->fetchAll(
                    'SHOW COLUMNS FROM ' . $this->db->grammar()->wrapIdentifier($table)
                );
                foreach ($rows as $r) $cols[] = (string) $r['Field'];
            } elseif ($grammar === 'sqlite') {
                // PRAGMA table_info returns rows with 'name' key
                $rows = $this->db->fetchAll('PRAGMA table_info(' . $this->db->grammar()->wrapIdentifier($table) . ')');
                foreach ($rows as $r) $cols[] = (string) $r['name'];
            } else {
                // Postgres / SQL Server / others — information_schema (SQL standard)
                $rows = $this->db->fetchAll(
                    'SELECT column_name FROM information_schema.columns
                     WHERE table_name = ? ORDER BY ordinal_position',
                    [$table]
                );
                foreach ($rows as $r) {
                    $cols[] = (string) ($r['column_name'] ?? $r['COLUMN_NAME'] ?? '');
                }
                $cols = array_values(array_filter($cols, function ($c) { return $c !== ''; }));
            }
        } catch (\Throwable $e) {
            @error_log('[ezdoc:introspector] failed to list columns for '
                . $table . ': ' . $e->getMessage());
            $cols = [];
        }

        return $this->cache[$table] = $cols;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    /**
     * Filter list of desired columns to only those yang exist di table.
     * Preserves desired order.
     *
     * @param list<string> $desired
     * @return list<string>
     */
    public function intersect(string $table, array $desired): array
    {
        $existing = $this->columns($table);
        return array_values(array_intersect($desired, $existing));
    }

    /**
     * Bust cache untuk table (mis. setelah ALTER TABLE via migration).
     */
    public function forget(string $table): void
    {
        unset($this->cache[$table]);
    }

    public function forgetAll(): void
    {
        $this->cache = [];
    }
}
