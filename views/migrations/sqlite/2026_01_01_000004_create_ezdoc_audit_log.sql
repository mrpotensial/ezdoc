-- ezdoc_audit_log (SQLite dialect)
-- Parallel of migrations/2026_01_01_000004_create_ezdoc_audit_log.php.
CREATE TABLE IF NOT EXISTS ezdoc_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NULL,
    entity_uuid TEXT NULL,
    actor_id TEXT NULL,
    actor_role TEXT NULL,
    action TEXT NULL,
    result TEXT NOT NULL DEFAULT 'success',
    request_ip TEXT NULL,
    request_ua TEXT NULL,
    metadata TEXT NULL,      -- JSON
    payload_before TEXT NULL,
    payload_after TEXT NULL,
    error_code TEXT NULL,
    error_message TEXT NULL,
    correlation_id TEXT NULL,
    session_id TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_entity ON ezdoc_audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_actor ON ezdoc_audit_log(actor_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_event ON ezdoc_audit_log(event_type, created_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_correlation ON ezdoc_audit_log(correlation_id);

-- Migration bookkeeping table (mirror MySQL runner's ezdoc_migrations table).
CREATE TABLE IF NOT EXISTS ezdoc_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    batch INTEGER NOT NULL DEFAULT 1,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
