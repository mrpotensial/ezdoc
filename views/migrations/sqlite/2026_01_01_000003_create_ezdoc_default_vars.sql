-- ezdoc_default_vars (SQLite dialect)
CREATE TABLE IF NOT EXISTS ezdoc_default_vars (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    var_name TEXT NOT NULL UNIQUE,
    var_value TEXT NULL,
    var_type TEXT NOT NULL DEFAULT 'string',
    description TEXT NULL,
    is_locked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
