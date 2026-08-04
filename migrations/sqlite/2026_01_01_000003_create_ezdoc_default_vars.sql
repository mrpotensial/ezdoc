-- ezdoc_default_vars (SQLite dialect)
-- Parallel of migrations/2026_01_01_000003_create_ezdoc_default_vars.php.
--
-- ## Updated 2026-08-04
--
-- Was missing is_enabled and metadata, which the MySQL version has had for a
-- while. is_enabled is the soft-disable flag every listing filters on, so on
-- SQLite the filter had no column to read.
--
-- The extra columns here (var_value, var_type, is_locked) predate the MySQL
-- shape and are kept: dropping a column can only break an install holding data
-- in it, and their presence costs nothing.
--
-- Dialect differences:
--   * TINYINT(1) → INTEGER with a 0/1 CHECK.
--   * JSON → TEXT.
--   * ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent; the application
--     sets updated_at on write, as it does on the MySQL path.
CREATE TABLE IF NOT EXISTS ezdoc_default_vars (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    var_name TEXT NOT NULL UNIQUE,
    var_value TEXT NULL,
    var_type TEXT NOT NULL DEFAULT 'string',
    description TEXT NULL,

    -- Soft-disable without deleting.
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    metadata TEXT NULL,

    is_locked INTEGER NOT NULL DEFAULT 0 CHECK (is_locked IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ezdoc_default_vars_enabled ON ezdoc_default_vars (is_enabled);
