-- ezdoc_document_artifacts (dialek SQLite)
-- Kembaran migrations/2026_08_08_000001_create_ezdoc_document_artifacts.php.
--
-- Ditulis bersamaan dengan versi MySQL-nya, bukan menyusul. Empat migrasi yang
-- berbohong sepanjang Agustus 2026 semuanya lahir dari kebiasaan menunda ini.
--
-- Beda dialek, semuanya terpaksa dan bukan pilihan:
--   * JSON → TEXT. SQLite menyimpan JSON sebagai teks; fungsi json_* tetap jalan.
--   * DATETIME(3) → TEXT. SQLite tidak punya tipe waktu; presisi milidetik
--     dipertahankan di bentuk stringnya, dan urutannya tetap benar karena ISO-8601
--     terurut secara leksikografis.
--   * INT UNSIGNED → INTEGER + CHECK. SQLite tidak punya modifier unsigned, dan
--     CHECK itulah yang sebenarnya dimaksud modifier tadi.
--   * Indeks jadi pernyataan terpisah; SQLite tidak bisa mendeklarasikannya inline.
CREATE TABLE IF NOT EXISTS ezdoc_document_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    uuid TEXT NOT NULL UNIQUE,
    document_id INTEGER NOT NULL,
    cycle INTEGER NOT NULL DEFAULT 1 CHECK (cycle >= 1),

    -- SHA-256 isinya. Ini SEKALIGUS hash artefaknya — tidak ada kolom hash
    -- terpisah, karena dua salinan dari satu kebenaran bisa berselisih.
    blob_key TEXT NOT NULL,

    -- Daftar tanda tangan data yang ikut dibekukan, beserta content_hash-nya.
    manifest TEXT NULL,

    frozen_at TEXT NOT NULL,
    frozen_by TEXT NOT NULL DEFAULT '',

    -- NULL = artefak aktif.
    superseded_at TEXT NULL,
    superseded_by TEXT NULL,
    superseded_reason TEXT NULL,

    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE (document_id, cycle),
    FOREIGN KEY (document_id) REFERENCES ezdoc_documents(id)
);

CREATE INDEX IF NOT EXISTS idx_artifact_document ON ezdoc_document_artifacts (document_id);
CREATE INDEX IF NOT EXISTS idx_artifact_blob ON ezdoc_document_artifacts (blob_key);
CREATE INDEX IF NOT EXISTS idx_artifact_frozen ON ezdoc_document_artifacts (frozen_at);
CREATE INDEX IF NOT EXISTS idx_artifact_active ON ezdoc_document_artifacts (document_id, superseded_at);
