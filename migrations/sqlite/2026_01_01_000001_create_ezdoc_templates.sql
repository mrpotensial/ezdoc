-- ezdoc_templates (SQLite dialect)
-- Parallel of migrations/2026_01_01_000001_create_ezdoc_templates.php.
-- Kept intentionally schema-compatible: same column NAMES so views + action
-- files that read rows via associative array continue to work. SQLite-specific
-- types replace MySQL-specific ones (JSON → TEXT, ENUM → TEXT + CHECK, etc.).
CREATE TABLE IF NOT EXISTS ezdoc_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL,
    slug TEXT NOT NULL DEFAULT '',
    version INTEGER NOT NULL DEFAULT 1,
    is_current INTEGER NOT NULL DEFAULT 1,
    parent_version_id INTEGER NULL,
    name TEXT NOT NULL,
    nama_template TEXT NOT NULL DEFAULT '',  -- legacy alias populated via trigger below
    category TEXT NOT NULL DEFAULT '',
    scope TEXT NOT NULL DEFAULT 'patient',
    content TEXT,
    content_hash TEXT NULL,
    signature_config TEXT NULL,
    layout_config TEXT NULL,
    verify_config TEXT NULL,
    access_config TEXT NULL,
    metadata TEXT NULL,
    owner_id INTEGER NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_locked INTEGER NOT NULL DEFAULT 0,
    revision INTEGER NOT NULL DEFAULT 1,
    deleted_at TEXT NULL DEFAULT NULL,
    deleted_by TEXT NULL DEFAULT NULL,
    deleted_reason TEXT NULL,
    created_by INTEGER NULL,
    updated_by INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (uuid, version),
    FOREIGN KEY (parent_version_id) REFERENCES ezdoc_templates(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_uuid ON ezdoc_templates(uuid);
CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_slug ON ezdoc_templates(slug);
CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_current_active ON ezdoc_templates(is_current, is_active, deleted_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_category ON ezdoc_templates(category);
CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_owner ON ezdoc_templates(owner_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_parent ON ezdoc_templates(parent_version_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_templates_updated ON ezdoc_templates(updated_at);

-- Keep nama_template in sync with name (legacy designer.php read pattern).
CREATE TRIGGER IF NOT EXISTS trg_ezdoc_templates_ins_nama
AFTER INSERT ON ezdoc_templates
FOR EACH ROW WHEN NEW.nama_template = '' AND NEW.name != ''
BEGIN
    UPDATE ezdoc_templates SET nama_template = NEW.name WHERE id = NEW.id;
END;
