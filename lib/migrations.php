<?php

declare(strict_types=1);

/**
 * Global migration helpers — thin wrappers ke Ezdoc\Migrations\Runner.
 *
 * Backward compat untuk existing procedural code. New code sebaiknya pakai
 * Ezdoc\Migrations\Runner langsung.
 *
 * @example Existing:
 *   ezdoc_migrate($conn);
 *
 * @example New:
 *   $runner = new Ezdoc\Migrations\Runner($conn, __DIR__ . '/migrations');
 *   $result = $runner->migrate();
 */

if (defined('EZDOC_MIGRATIONS_LOADED')) return;
define('EZDOC_MIGRATIONS_LOADED', true);

/**
 * Ensure migration registry table exists.
 * @param \mysqli|null $conn
 */
function ezdoc_ensure_migrations_table($conn): void
{
    if (!$conn instanceof \mysqli) return;
    $runner = new \Ezdoc\Migrations\Runner($conn, _ezdoc_migrations_dir());
    $runner->ensureRegistryTable();
}

/**
 * Run pending migrations.
 * @param \mysqli|null $conn
 * @param bool $selfHeal Auto-detect + clear orphan registry (default true)
 * @return array{applied:array<string>, skipped:array<string>, failed:array<string,string>, healed:bool}
 */
function ezdoc_migrate($conn, bool $selfHeal = true): array
{
    if (!$conn instanceof \mysqli) {
        return ['applied' => [], 'skipped' => [], 'failed' => [], 'healed' => false];
    }
    $runner = new \Ezdoc\Migrations\Runner($conn, _ezdoc_migrations_dir());
    return $runner->migrate($selfHeal);
}

/**
 * Force reset — truncate registry sehingga next ezdoc_migrate() re-run semua.
 * Useful setelah drop tables manual.
 */
function ezdoc_migrate_reset($conn): void
{
    if (!$conn instanceof \mysqli) return;
    $runner = new \Ezdoc\Migrations\Runner($conn, _ezdoc_migrations_dir());
    $runner->reset();
}

/**
 * Force-mark migration sebagai applied (untuk existing DB).
 * @param \mysqli $conn
 */
function ezdoc_mark_migration_applied($conn, string $name): void
{
    if (!$conn instanceof \mysqli) return;
    $runner = new \Ezdoc\Migrations\Runner($conn, _ezdoc_migrations_dir());
    $runner->markApplied($name);
}

/**
 * Scan migrations folder — legacy wrapper.
 * @return array<string> full paths
 */
function ezdoc_scan_migration_files(): array
{
    $dir = _ezdoc_migrations_dir();
    if (!is_dir($dir)) return [];
    $files = @glob($dir . '/*.php');
    if (!is_array($files)) return [];
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Load 1 migration file — legacy wrapper.
 * @return array{name:string, up:callable}|null
 */
function ezdoc_load_migration_file(string $path): ?array
{
    if (!file_exists($path)) return null;
    $spec = @include $path;
    if (!is_array($spec)) return null;
    if (empty($spec['name']) || !isset($spec['up']) || !is_callable($spec['up'])) return null;
    return $spec;
}

/** Internal: resolve migrations dir. */
function _ezdoc_migrations_dir(): string
{
    return defined('EZDOC_ROOT') ? EZDOC_ROOT . '/migrations' : __DIR__ . '/../migrations';
}
