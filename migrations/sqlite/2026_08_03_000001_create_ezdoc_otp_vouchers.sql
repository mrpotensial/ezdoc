-- ezdoc_otp_vouchers (SQLite dialect)
-- Parallel of migrations/2026_08_03_000001_create_ezdoc_otp_vouchers.php.
--
-- Written at the same time as the MySQL version, not after it. Four of five
-- core migrations once had a SQLite counterpart and one did not; nobody noticed
-- until a test needed the table.
--
-- That matters more here than usual: every test for this feature turns on
-- expiry boundaries, and those tests run on SQLite.
--
-- Dialect differences, all forced rather than chosen:
--   * INT UNSIGNED → INTEGER with a CHECK. SQLite has no unsigned modifier;
--     the CHECK is what the modifier was there to say.
--   * DATETIME → TEXT. Stored as 'Y-m-d H:i:s' either way, so lexical order
--     still matches chronological order and BETWEEN/`>` behave the same.
--   * Indexes are separate statements.
--   * No COMMENT clauses; the comments live here instead.
CREATE TABLE IF NOT EXISTS ezdoc_otp_vouchers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Already normalised to 62xxx before it gets here. Storing both 62812… and
    -- 0812… in one column means lookups miss for reasons nobody can see.
    phone TEXT NOT NULL,

    -- password_hash() of the code. The code itself never reaches storage.
    code_hash TEXT NOT NULL,

    source TEXT NOT NULL DEFAULT 'whatsapp_inbound',

    issued_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,

    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 3 CHECK (max_attempts > 0),

    -- NULL until used. Written once through a conditional UPDATE so two
    -- concurrent claims cannot both succeed.
    claimed_at TEXT NULL,
    claimed_doc_id INTEGER NULL,
    claimed_ttd_id TEXT NULL,
    claimed_template_id INTEGER NULL,

    -- The already-verified challenge this claim produced.
    claimed_challenge_id TEXT NULL,

    -- 'document' when the number came from the document, 'manual' when an
    -- operator typed it. See docs/PLAN-OTP-JALUR-KEDUA.md §9.
    claimed_phone_origin TEXT NULL CHECK (
        claimed_phone_origin IS NULL OR claimed_phone_origin IN ('document', 'manual')
    ),

    provider_meta TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_voucher_phone ON ezdoc_otp_vouchers (phone, expires_at);
CREATE INDEX IF NOT EXISTS idx_voucher_expires ON ezdoc_otp_vouchers (expires_at);
CREATE INDEX IF NOT EXISTS idx_voucher_challenge ON ezdoc_otp_vouchers (claimed_challenge_id);
