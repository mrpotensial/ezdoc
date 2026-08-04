-- ezdoc_audit_log (SQLite dialect)
-- Parallel of migrations/2026_01_01_000004_create_ezdoc_audit_log.php.
--
-- ## Rewritten 2026-08-04, and why
--
-- The previous version of this file described a different table entirely:
-- entity_type/entity_id/actor_role/request_ip/created_at, where the MySQL
-- migration has target_type/target_id/actor_roles/ip_address/occurred_at.
--
-- Nothing failed loudly. Ezdoc\Audit\Logger names its columns explicitly, so
-- the INSERT raised "no such column", and the Logger swallows every error on
-- purpose — auditing must never take down the operation it is recording. The
-- result was an audit log that quietly recorded nothing on every SQLite host,
-- and document_history.php selecting occurred_at/ip_address/message from a
-- table that had none of them.
--
-- A missing dialect file fails on the first query. A dialect file that lies
-- keeps working right up until somebody needs the data it was meant to keep.
--
-- Existing SQLite databases keep the old table — CREATE TABLE IF NOT EXISTS
-- will not reshape one that already exists. Those installs have not been
-- recording anything either way, so dropping the table and re-running loses
-- nothing.
--
-- Dialect differences, all forced rather than chosen:
--   * ENUM → TEXT with a CHECK. SQLite has no enum type; the CHECK says what
--     the enum said.
--   * JSON → TEXT. SQLite stores JSON as text and has no separate type.
--   * BIGINT/CHAR/VARCHAR → INTEGER/TEXT, following SQLite's type affinities.
--   * DATETIME(3) → TEXT. Millisecond precision survives because the value is
--     written by PHP as 'Y-m-d H:i:s.v', and that format sorts lexically in the
--     same order it sorts chronologically.
--   * Indexes are separate statements, and their names are prefixed: index
--     names are database-global in SQLite, not scoped per table, so a bare
--     idx_time would collide with the next table that wants one.
CREATE TABLE IF NOT EXISTS ezdoc_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Event identification
    event_type TEXT NOT NULL,
    event_uuid TEXT NULL,

    -- Actor context
    actor_id INTEGER NULL,
    actor_roles TEXT NULL,
    actor_type TEXT NOT NULL DEFAULT 'user'
        CHECK (actor_type IN ('user', 'system', 'public', 'api')),

    -- Target entity
    target_type TEXT NULL,
    target_id TEXT NULL,

    -- Denormalised refs for fast filtering
    template_id INTEGER NULL,
    doc_id INTEGER NULL,

    -- Request context
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    request_id TEXT NULL,
    session_id TEXT NULL,
    trace_id TEXT NULL,

    -- Change tracking
    previous_value TEXT NULL,
    new_value TEXT NULL,

    -- Extensibility
    metadata TEXT NULL,

    -- Result
    result TEXT NOT NULL DEFAULT 'success'
        CHECK (result IN ('success', 'denied', 'error', 'warning')),
    message TEXT NULL,

    occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_event_time ON ezdoc_audit_log (event_type, occurred_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_actor_time ON ezdoc_audit_log (actor_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_target ON ezdoc_audit_log (target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_template_time ON ezdoc_audit_log (template_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_doc_time ON ezdoc_audit_log (doc_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_time ON ezdoc_audit_log (occurred_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_result_time ON ezdoc_audit_log (result, occurred_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_request ON ezdoc_audit_log (request_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_session ON ezdoc_audit_log (session_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_audit_trace ON ezdoc_audit_log (trace_id);

-- Migration bookkeeping table (mirror MySQL runner's ezdoc_migrations table).
CREATE TABLE IF NOT EXISTS ezdoc_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    batch INTEGER NOT NULL DEFAULT 1,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
