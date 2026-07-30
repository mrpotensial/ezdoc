-- ezdoc_blobs (SQLite dialect)
-- Parallel of migrations/2026_07_30_000001_create_ezdoc_blobs.php.
--
-- Written alongside the MySQL version rather than after it. The signature table
-- taught that lesson: four of five core migrations had a SQLite counterpart and
-- one did not, and nobody noticed until a test needed the table.
--
-- Dialect differences, all forced rather than chosen:
--   * LONGBLOB → BLOB. SQLite has one blob type and no length classes.
--   * BIGINT UNSIGNED → INTEGER. SQLite has no unsigned modifier; byte_size is
--     guarded by a CHECK instead, which is what the modifier was there for.
--   * `key` quoted the same way. It is a reserved word in neither dialect but
--     reads like one, and quoting it keeps the two files literally comparable.
--   * Indexes are separate statements; SQLite cannot declare them inline.
--   * CURRENT_TIMESTAMP instead of NOW().
CREATE TABLE IF NOT EXISTS ezdoc_blobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- SHA-256 of the content, hex. This is the identity: a reference that names
    -- content cannot come to mean different bytes later, which is what the
    -- signature hash rule requires of it.
    "key" TEXT NOT NULL UNIQUE,

    -- Redundant against `key` on purpose: the algo:hex shape names the algorithm,
    -- so rows written today can still describe themselves after a change.
    checksum TEXT NOT NULL,

    byte_size INTEGER NOT NULL CHECK (byte_size >= 0),
    content_type TEXT NOT NULL DEFAULT 'application/octet-stream',

    -- database | disk. NULL content means the bytes are on disk.
    backend TEXT NOT NULL DEFAULT 'database' CHECK (backend IN ('database', 'disk')),
    content BLOB NULL,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_blob_backend ON ezdoc_blobs (backend);
CREATE INDEX IF NOT EXISTS idx_blob_deleted ON ezdoc_blobs (deleted_at);
CREATE INDEX IF NOT EXISTS idx_blob_created ON ezdoc_blobs (created_at);
