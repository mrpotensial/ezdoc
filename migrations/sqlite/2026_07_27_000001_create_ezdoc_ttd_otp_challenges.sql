-- ezdoc_ttd_otp_challenges (SQLite dialect)
-- Parallel of migrations/2026_07_27_000001_create_ezdoc_ttd_otp_challenges.php.
CREATE TABLE IF NOT EXISTS ezdoc_ttd_otp_challenges (
    challenge_id TEXT NOT NULL PRIMARY KEY,
    doc_id INTEGER NULL,
    template_id INTEGER NOT NULL,
    ttd_id TEXT NOT NULL,

    -- V6 — kode terikat dokumen sejak lahir. Lihat
    -- docs/PLAN-OTP-JALUR-KEDUA.md §8h dan migrasi MySQL
    -- 2026_08_05_000001_alter_ttd_otp_challenges_add_scan_token.php.
    --
    -- Di MySQL kolom ini datang lewat ALTER, karena di sana ada data hidup.
    -- Di sini langsung di CREATE: berkas SQLite dipakai tes, yang selalu
    -- membangun dari nol.
    scan_token TEXT NULL,            -- token di QR; acak, tanpa identitas apa pun
    scanned_at TEXT NULL,            -- NULL = QR belum dipindai

    -- phone dan otp_hash boleh NULL: keduanya belum diketahui saat challenge
    -- dibuat. Nomornya ketahuan dari siapa yang memindai, kodenya dibuat setelah
    -- pindaian masuk. Jalur lama tetap mengisi keduanya.
    phone TEXT NULL,
    otp_hash TEXT NULL,              -- bcrypt, never the code itself
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    verified_at TEXT NULL,
    expires_at TEXT NOT NULL,
    sign_token TEXT NULL,
    sign_token_used_at TEXT NULL,    -- non-null once spent; enforces single use
    provider_meta TEXT NULL,         -- JSON

    -- Salinan pesan persetujuan yang BENAR-BENAR dikirim ke pasien.
    -- Pertanyaan saat sengketa bukan "apa isi dokumennya" tapi "apa yang
    -- diberitahukan ke pasien saat itu" — dan itu hanya bisa dijawab kalau
    -- teksnya disimpan, bukan dirangkai ulang nanti.
    consent_message TEXT NULL,

    created_at TEXT NOT NULL
);

-- Unik hanya untuk token yang terisi. NULL dianggap berbeda dari NULL di indeks
-- unik — sifat yang di subject_type justru menghancurkan keunikan, tapi di sini
-- persis yang dibutuhkan: challenge jalur lama semuanya ber-scan_token NULL dan
-- memang tidak boleh saling bentrok.
CREATE UNIQUE INDEX IF NOT EXISTS uk_otp_scan_token
    ON ezdoc_ttd_otp_challenges (scan_token);

-- Token consumption looks up by sign_token alone.
CREATE INDEX IF NOT EXISTS idx_otp_sign_token
    ON ezdoc_ttd_otp_challenges (sign_token);

-- Send cooldown looks up the newest challenge per slot.
CREATE INDEX IF NOT EXISTS idx_otp_slot_recent
    ON ezdoc_ttd_otp_challenges (template_id, ttd_id, created_at);

CREATE INDEX IF NOT EXISTS idx_otp_ttd_phone
    ON ezdoc_ttd_otp_challenges (ttd_id, phone);
