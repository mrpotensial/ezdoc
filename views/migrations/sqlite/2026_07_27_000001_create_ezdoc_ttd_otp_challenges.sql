-- ezdoc_ttd_otp_challenges (SQLite dialect)
-- Parallel of migrations/2026_07_27_000001_create_ezdoc_ttd_otp_challenges.php.
CREATE TABLE IF NOT EXISTS ezdoc_ttd_otp_challenges (
    challenge_id TEXT NOT NULL PRIMARY KEY,
    doc_id INTEGER NULL,
    template_id INTEGER NOT NULL,
    ttd_id TEXT NOT NULL,
    phone TEXT NOT NULL,
    otp_hash TEXT NOT NULL,          -- bcrypt, never the code itself
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    verified_at TEXT NULL,
    expires_at TEXT NOT NULL,
    sign_token TEXT NULL,
    sign_token_used_at TEXT NULL,    -- non-null once spent; enforces single use
    provider_meta TEXT NULL,         -- JSON
    created_at TEXT NOT NULL
);

-- Token consumption looks up by sign_token alone.
CREATE INDEX IF NOT EXISTS idx_otp_sign_token
    ON ezdoc_ttd_otp_challenges (sign_token);

-- Send cooldown looks up the newest challenge per slot.
CREATE INDEX IF NOT EXISTS idx_otp_slot_recent
    ON ezdoc_ttd_otp_challenges (template_id, ttd_id, created_at);

CREATE INDEX IF NOT EXISTS idx_otp_ttd_phone
    ON ezdoc_ttd_otp_challenges (ttd_id, phone);
