-- ezdoc_signatures (SQLite dialect)
-- Parallel of migrations/2026_01_01_000005_create_ezdoc_signatures.php.
--
-- Was missing: four of the five core migrations had a SQLite counterpart and
-- this one did not, so the signature table could not exist on the SQLite path
-- at all — including in tests, which is where the gap surfaced.
--
-- Dialect differences, all forced by SQLite rather than chosen:
--   * DATETIME(3) has no equivalent. Millisecond precision lives in the TEXT
--     value ('YYYY-MM-DD HH:MM:SS.mmm') instead of in the column type, so the
--     writer keeps the precision and lexical ordering still sorts correctly.
--   * MEDIUMBLOB → BLOB (SQLite has one blob type, no length classes).
--   * ENUM → TEXT + CHECK, the same substitution SqliteGrammar makes.
--   * Indexes are separate statements; SQLite cannot declare them inline.
CREATE TABLE IF NOT EXISTS ezdoc_signatures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    document_id INTEGER NOT NULL,
    signature_id_within_doc INTEGER NOT NULL DEFAULT 0,  -- 0..N for multi-signer docs

    -- Signer identity, snapshotted at signing time
    signer_id TEXT NOT NULL,                             -- opaque, domain-agnostic
    signer_role TEXT NULL,                               -- role AS AT signing, not a reference
    signer_user_id INTEGER NULL,

    -- Signature envelope
    provider TEXT NOT NULL,                              -- hmac | local_pki | peruri | ...
    level INTEGER NOT NULL,                              -- 1 | 2 | 3 assurance level
    envelope_format TEXT NOT NULL,                       -- hmac | raw | pkcs7 | pades
    envelope BLOB NOT NULL,
    content_hash TEXT NOT NULL,                          -- hex digest of the signed payload
    content_hash_algo TEXT NOT NULL DEFAULT 'sha256',    -- names payload shape AND canonicalisation

    -- Certificate (L2/L3)
    certificate_pem TEXT NULL,
    certificate_serial TEXT NULL,
    certificate_subject TEXT NULL,
    certificate_issuer TEXT NULL,

    -- Timestamp authority (RFC 3161)
    tsa_response BLOB NULL,

    -- Timing. Millisecond precision is carried by the value, see note above.
    signed_at TEXT NOT NULL,
    verified_at TEXT NULL,

    -- Verification result
    verify_status TEXT NOT NULL DEFAULT 'pending'
        CHECK (verify_status IN ('valid','tampered','expired','revoked','untrusted','error','pending')),
    verify_reason TEXT NULL,

    metadata TEXT NULL,                                  -- JSON

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL,

    -- RESTRICT, matching MySQL: a signature outliving its document would be a
    -- record of something unverifiable.
    FOREIGN KEY (document_id) REFERENCES ezdoc_documents(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ezdoc_signatures_document ON ezdoc_signatures(document_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_signatures_signer ON ezdoc_signatures(signer_id);
CREATE INDEX IF NOT EXISTS idx_ezdoc_signatures_provider_level ON ezdoc_signatures(provider, level);
CREATE INDEX IF NOT EXISTS idx_ezdoc_signatures_signed_at ON ezdoc_signatures(signed_at);
CREATE INDEX IF NOT EXISTS idx_ezdoc_signatures_verify_status ON ezdoc_signatures(verify_status);
CREATE INDEX IF NOT EXISTS idx_ezdoc_signatures_cert_serial ON ezdoc_signatures(certificate_serial);
