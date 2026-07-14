-- ezdoc_documents (SQLite dialect)
-- Parallel of migrations/2026_01_01_000002_create_ezdoc_documents.php.
CREATE TABLE IF NOT EXISTS ezdoc_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    template_id INTEGER NULL,
    template_uuid TEXT NULL,
    template_version INTEGER NULL,
    reference_number TEXT NULL,
    title TEXT NULL,
    subject_type TEXT NULL,
    subject_id TEXT NULL,
    field_values TEXT NULL,     -- JSON payload of rendered fields
    rendered_html TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    is_locked INTEGER NOT NULL DEFAULT 0,
    content_hash TEXT NULL,
    metadata TEXT NULL,
    owner_id INTEGER NULL,
    created_by INTEGER NULL,
    updated_by INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL,
    FOREIGN KEY (template_id) REFERENCES ezdoc_templates(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_template ON ezdoc_documents(template_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_status ON ezdoc_documents(status, deleted_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_subject ON ezdoc_documents(subject_type, subject_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_owner ON ezdoc_documents(owner_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_documents_ref ON ezdoc_documents(reference_number);
