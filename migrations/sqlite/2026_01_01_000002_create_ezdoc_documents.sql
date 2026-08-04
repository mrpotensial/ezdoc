-- ezdoc_documents (SQLite dialect)
-- Parallel of migrations/2026_01_01_000002_create_ezdoc_documents.php.
--
-- ## Rewritten 2026-08-04, and why
--
-- The previous version described an earlier design that the MySQL migration
-- has long since moved past. Missing were norm, nopen, label and version — the
-- four columns that identify a slot and its revisions — along with
-- signature_values, the public_slug family, the content-hash stamps,
-- published_at/expires_at, revision, and deleted_by/deleted_reason.
--
-- It also carried columns MySQL never had (reference_number, subject_type,
-- subject_id, rendered_html, owner_id). Those are kept: dropping a column can
-- only break an install that has data in it, and their presence costs nothing.
-- They are not written by any current code path.
--
-- The consequence of the gap was not a loud failure but a quiet one. Saving a
-- document names its columns explicitly, so a SQLite host got "no such column"
-- on the very first save — but the slot query
-- (WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?) and the
-- uk_slot_version guarantee had no way to exist at all, which means versioning
-- and duplicate protection were simply absent rather than broken.
--
-- Existing SQLite databases keep the old table: CREATE TABLE IF NOT EXISTS will
-- not reshape one that is already there. Drop it and re-run.
--
-- Dialect differences, all forced rather than chosen:
--   * ENUM → TEXT with a CHECK.
--   * JSON → TEXT; SQLite stores JSON as text.
--   * TINYINT(1) → INTEGER with a 0/1 CHECK.
--   * INT UNSIGNED → INTEGER with a >= 0 CHECK.
--   * DATETIME → TEXT, written as 'Y-m-d H:i:s', which sorts lexically in the
--     same order it sorts chronologically.
--   * ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent. updated_at is set
--     by the application on write, which is what the MySQL path does too.
--   * Indexes and the UNIQUE over the slot are separate statements.
CREATE TABLE IF NOT EXISTS ezdoc_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Identifier (UUID v7, time-ordered)
    uuid TEXT NOT NULL UNIQUE,

    -- Template reference (snapshot at creation time)
    template_id INTEGER NULL,
    template_uuid TEXT NULL,
    template_version INTEGER NULL DEFAULT 1,

    -- Identity
    title TEXT NULL,
    norm TEXT NULL,
    nopen TEXT NULL,
    label TEXT NOT NULL DEFAULT '-',

    -- Versioning within a slot
    version INTEGER NOT NULL DEFAULT 1 CHECK (version >= 0),

    -- Data
    field_values TEXT NULL,
    signature_values TEXT NULL,
    metadata TEXT NULL,

    -- Lifecycle state
    status TEXT NOT NULL DEFAULT 'published'
        CHECK (status IN ('draft', 'published', 'locked', 'archived')),
    is_locked INTEGER NOT NULL DEFAULT 0 CHECK (is_locked IN (0, 1)),

    -- Content integrity
    content_hash TEXT NULL,
    content_hash_at TEXT NULL,
    content_hash_version INTEGER NULL CHECK (content_hash_version IS NULL OR content_hash_version >= 0),

    -- Public verify
    public_slug TEXT NULL,
    public_slug_active INTEGER NOT NULL DEFAULT 1 CHECK (public_slug_active IN (0, 1)),
    public_slug_scan_count INTEGER NOT NULL DEFAULT 0 CHECK (public_slug_scan_count >= 0),
    public_slug_last_scan TEXT NULL,

    -- Lifecycle timestamps
    published_at TEXT NULL,
    expires_at TEXT NULL,

    -- Optimistic locking
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision >= 0),

    -- Soft delete
    deleted_at TEXT NULL,
    deleted_by TEXT NULL,
    deleted_reason TEXT NULL,

    -- Actor tracking
    created_by INTEGER NULL,
    updated_by INTEGER NULL,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Carried over from the earlier shape of this file. Nothing writes them.
    reference_number TEXT NULL,
    subject_type TEXT NULL,
    subject_id TEXT NULL,
    rendered_html TEXT NULL,
    owner_id INTEGER NULL,

    FOREIGN KEY (template_id) REFERENCES ezdoc_templates(id) ON DELETE SET NULL
);

-- The slot guarantee, and the reason version exists. Without it, saving twice
-- into the same slot silently produces two rows both claiming to be version 1.
-- NULL norm/nopen are distinct in a SQLite unique index just as in MySQL, which
-- is the behaviour the general-scope (norm IS NULL) documents rely on.
CREATE UNIQUE INDEX IF NOT EXISTS uk_ezdoc_documents_slot_version
    ON ezdoc_documents (template_uuid, norm, nopen, label, version);
CREATE UNIQUE INDEX IF NOT EXISTS uk_ezdoc_documents_public_slug
    ON ezdoc_documents (public_slug);

CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_uuid ON ezdoc_documents (uuid);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_template ON ezdoc_documents (template_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_template_uuid ON ezdoc_documents (template_uuid);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_norm_nopen ON ezdoc_documents (norm, nopen);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_status ON ezdoc_documents (status, deleted_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_slot
    ON ezdoc_documents (template_uuid, norm, nopen, label, deleted_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_deleted ON ezdoc_documents (deleted_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_expires ON ezdoc_documents (expires_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_created_by ON ezdoc_documents (created_by, created_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_updated ON ezdoc_documents (updated_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_subject ON ezdoc_documents (subject_type, subject_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_owner ON ezdoc_documents (owner_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_ref ON ezdoc_documents (reference_number);
