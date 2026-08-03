<?php

declare(strict_types=1);

namespace Ezdoc\Migrations;

use mysqli;

/**
 * Ezdoc\Migrations\Runner — versioned schema migration runner.
 *
 * Scan folder migrations/ untuk `.php` files, run yang belum applied,
 * record hasil di table `ezdoc_migrations`.
 *
 * File format:
 * ```php
 * return [
 *   'name' => '2026_01_01_000001_create_ezdoc_templates',
 *   'up' => function ($conn): void {
 *     $conn->query("CREATE TABLE IF NOT EXISTS ...");
 *   },
 * ];
 * ```
 *
 * File naming: prefix timestamp untuk chronological ordering (sort by filename).
 */
final class Runner
{
    /** @var mysqli */
    private $db;

    /** @var string */
    private $migrationsDir;

    public function __construct(mysqli $db, string $migrationsDir)
    {
        $this->db = $db;
        $this->migrationsDir = $migrationsDir;
    }

    /**
     * Ensure ezdoc_migrations registry table exists.
     * Silent — kalau permission tidak cukup, migration akan gagal saat run.
     */
    public function ensureRegistryTable(): void
    {
        @$this->db->query("
            CREATE TABLE IF NOT EXISTS ezdoc_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                execution_time_ms INT UNSIGNED NULL,
                INDEX idx_executed (executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Get list of applied migration names dari registry.
     * @return array<string,true> map<name → true> untuk O(1) lookup
     */
    public function getApplied(): array
    {
        $applied = [];
        $rs = @$this->db->query("SELECT migration FROM ezdoc_migrations");
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $applied[$row['migration']] = true;
            }
        }
        return $applied;
    }

    /**
     * Scan migrations folder → sorted list of full paths.
     * @return array<string>
     */
    public function scanFiles(): array
    {
        if (!is_dir($this->migrationsDir)) return [];
        $files = @glob($this->migrationsDir . '/*.php');
        if (!is_array($files)) return [];
        sort($files, SORT_STRING); // ASCII sort = chronological karena timestamp prefix
        return $files;
    }

    /**
     * Load & validate 1 migration file.
     * @return array{name:string, up:callable}|null
     */
    public function loadFile(string $path): ?array
    {
        if (!file_exists($path)) return null;
        $spec = @include $path;
        if (!is_array($spec)) return null;
        if (empty($spec['name']) || !isset($spec['up']) || !is_callable($spec['up'])) return null;
        return $spec;
    }

    /**
     * Record migration sebagai applied.
     */
    public function recordApplied(string $name, ?int $execMs = null): void
    {
        $stmt = @mysqli_prepare($this->db, "INSERT IGNORE INTO ezdoc_migrations (migration, execution_time_ms) VALUES (?, ?)");
        if (!$stmt) return;
        @mysqli_stmt_bind_param($stmt, 'si', $name, $execMs);
        @mysqli_stmt_execute($stmt);
    }

    /**
     * Force-mark migration sebagai applied tanpa run (untuk existing DB
     * yang schemanya sudah ada tapi belum di ezdoc_migrations table).
     */
    public function markApplied(string $name): void
    {
        $this->ensureRegistryTable();
        $this->recordApplied($name, 0);
    }

    /**
     * Detect orphan registry — registry punya records tapi core tables tidak ada.
     * Terjadi saat:
     *   1. User manual DROP tables tanpa TRUNCATE registry (fresh install desync)
     *   2. Migration silently gagal (mis. FK constraint error) — table not created
     *      tapi Runner record as applied karena mysqli_query return false tanpa throw
     *      (bugfix v0.7.1 — sekarang migration file wajib throw on SQL error)
     *
     * Kalau detected, auto-clear registry supaya fresh migrate bisa jalan.
     *
     * @param array<string> $coreTables Tables yang wajib ada
     * @return bool true kalau ada auto-heal, false kalau tidak perlu
     */
    public function selfHealOrphanRegistry(array $coreTables = ['ezdoc_templates', 'ezdoc_documents', 'ezdoc_signatures']): bool
    {
        // Kalau registry table sendiri tidak ada, tidak ada orphan
        $rs = @$this->db->query("SHOW TABLES LIKE 'ezdoc_migrations'");
        if (!$rs || $rs->num_rows === 0) return false;

        // Kalau registry kosong, tidak ada orphan
        $rsCount = @$this->db->query("SELECT COUNT(*) AS c FROM ezdoc_migrations");
        if (!$rsCount) return false;
        $count = (int) ($rsCount->fetch_assoc()['c'] ?? 0);
        if ($count === 0) return false;

        // Cek core tables — kalau ada yang missing, orphan detected
        $missing = [];
        foreach ($coreTables as $table) {
            $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
            $rs = @$this->db->query("SHOW TABLES LIKE '{$safeTable}'");
            if (!$rs || $rs->num_rows === 0) $missing[] = $table;
        }

        if (empty($missing)) return false;

        // Orphan detected — clear registry
        @$this->db->query("TRUNCATE ezdoc_migrations");
        @error_log(sprintf(
            '[ezdoc:migrate] Detected orphan registry (missing tables: %s). Cleared registry untuk fresh migrate.',
            implode(',', $missing)
        ));
        return true;
    }

    /**
     * Truncate registry — force full re-migrate pada next call.
     */
    public function reset(): void
    {
        @$this->db->query("TRUNCATE ezdoc_migrations");
    }

    /**
     * Run pending migrations.
     * Idempotent — safe call multiple times.
     *
     * @param bool $selfHeal Auto-detect + clear orphan registry (default true)
     * @return array{applied:array<string>, skipped:array<string>, failed:array<string,string>, healed:bool}
     */
    public function migrate(bool $selfHeal = true): array
    {
        $result = ['applied' => [], 'skipped' => [], 'failed' => [], 'healed' => false];

        $this->ensureRegistryTable();

        // Self-heal: kalau tables di-drop manual tanpa clear registry, auto-clear
        if ($selfHeal && $this->selfHealOrphanRegistry()) {
            $result['healed'] = true;
        }

        $applied = $this->getApplied();

        foreach ($this->scanFiles() as $filePath) {
            $spec = $this->loadFile($filePath);
            if (!$spec) continue;

            $name = $spec['name'];
            if (isset($applied[$name])) {
                $result['skipped'][] = $name;
                continue;
            }

            $start = microtime(true);

            // Fail-loudly mode: enable mysqli exceptions selama migration execute.
            // Legacy migrations pakai $conn->query() yang return false on error
            // (no throw) — jadi Runner tidak tau failure dan mark as applied wrong.
            // Dengan MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, SQL errors jadi
            // mysqli_sql_exception yang catch di block di bawah.
            $prevReportMode = null;
            if (class_exists('mysqli_driver', false)) {
                $prevReportMode = (new \mysqli_driver())->report_mode;
                @mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            }

            try {
                call_user_func($spec['up'], $this->db);
                $execMs = (int) ((microtime(true) - $start) * 1000);
                $this->recordApplied($name, $execMs);
                $result['applied'][] = $name;
            } catch (\Throwable $e) {
                $result['failed'][$name] = $e->getMessage();
                @error_log("[ezdoc:migrate] FAILED {$name}: " . $e->getMessage());
                // Continue — don't stop batch on 1 failure
            } finally {
                // Restore previous mysqli report mode
                if ($prevReportMode !== null) {
                    @mysqli_report($prevReportMode);
                }
            }
        }

        return $result;
    }
}
