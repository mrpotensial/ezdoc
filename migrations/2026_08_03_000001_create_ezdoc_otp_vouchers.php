<?php
/**
 * Create ezdoc_otp_vouchers — one-time codes that exist before they have a purpose.
 *
 * ## Kenapa tabel sendiri, bukan menumpang ezdoc_ttd_otp_challenges
 *
 * Sebuah challenge lahir sudah tahu tujuannya: dibuat saat petugas menekan
 * "kirim OTP" pada slot tertentu di dokumen tertentu, jadi `template_id` dan
 * `ttd_id` wajar berstatus NOT NULL.
 *
 * Voucher kebalikannya. Pasien memindai QR di ruang tunggu dan mendapat kode
 * sebelum siapa pun membuka dokumennya — mungkin semenit sebelumnya, mungkin
 * lima. Satu-satunya hal yang diketahui saat kode dibuat adalah nomor
 * pengirimnya.
 *
 * Melonggarkan dua kolom itu jadi NULL akan membuat satu tabel menampung dua
 * jenis baris dengan aturan berbeda, dan setiap query harus menebak sedang
 * memandang yang mana. Dipisah lebih murah.
 *
 * ## Voucher tidak menggantikan challenge — ia bermuara ke sana
 *
 * Saat kode diklaim, sistem membuat baris challenge yang statusnya sudah
 * terverifikasi, lalu `claimed_challenge_id` menunjuk ke sana. Jadi seluruh
 * mesin `sign_token` yang sudah ada — termasuk `save_document.php` — tidak
 * berubah sebaris pun. Tabel ini hanya menambah cara baru untuk sampai ke
 * titik "kode terbukti benar".
 *
 * ## Satu kode aktif per nomor
 *
 * Menerbitkan voucher baru mematikan voucher lama nomor itu yang belum
 * diklaim. Dua alasan:
 *
 *   - Pasien yang mengirim `TTD-OTP` dua kali akan menyebutkan kode dari pesan
 *     terakhir. Membiarkan kode lama tetap hidup berarti kode yang di layar
 *     petugas dan kode di kepala pasien bisa berbeda-beda tanpa ada yang salah.
 *   - Batas 3 percobaan dimaksudkan per NOMOR, bukan per baris. Kalau setiap
 *     voucher membawa jatah sendiri dan yang lama tetap hidup, jatahnya
 *     berlipat begitu saja.
 *
 * Pola "kode baru mengalahkan kode lama" ini yang dipakai Twilio Verify dan
 * Auth0.
 *
 * ## Tidak ada FK ke ezdoc_documents
 *
 * `claimed_doc_id` sengaja tanpa constraint. Voucher adalah catatan tentang apa
 * yang terjadi; menghapus dokumen tidak boleh menghapus jejak bahwa sebuah kode
 * pernah diterbitkan dan dipakai. Pola yang sama dengan `ezdoc_audit_log`.
 */

return [
    'name' => '2026_08_03_000001_create_ezdoc_otp_vouchers',
    'up' => function ($conn): void {
        $ok = $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_otp_vouchers (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,

                -- Nomor pengirim, SUDAH dinormalkan (62xxx) lewat
                -- OtpService::normalizePhone(). Bentuk mentah tidak pernah
                -- disimpan: WhatsApp mengirim 62812…, dokumen menulis 0812…,
                -- dan dua bentuk di kolom yang sama berarti pencocokan gagal
                -- diam-diam.
                phone VARCHAR(32) NOT NULL,

                -- password_hash() dari kodenya, sama seperti otp_hash pada
                -- challenge. Kode mentah tidak pernah menyentuh disk.
                code_hash VARCHAR(255) NOT NULL,

                -- Dari mana permintaannya datang. Hari ini hanya
                -- 'whatsapp_inbound'; kolomnya ada supaya kanal kedua tidak
                -- perlu migrasi.
                source VARCHAR(32) NOT NULL DEFAULT 'whatsapp_inbound',

                issued_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,

                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 3,

                -- NULL selama belum dipakai. Diisi satu kali, lewat UPDATE
                -- bersyarat, sehingga dua permintaan bersamaan tidak bisa
                -- sama-sama menang.
                claimed_at DATETIME NULL,
                claimed_doc_id BIGINT NULL,
                claimed_ttd_id VARCHAR(64) NULL,
                claimed_template_id INT NULL,

                -- Challenge terverifikasi yang lahir dari klaim ini.
                claimed_challenge_id VARCHAR(64) NULL,

                -- Asal nomor yang dipakai petugas: 'document' kalau diambil
                -- dari field dokumen, 'manual' kalau diketik. Yang 'manual'
                -- ikut tercatat di log audit — lihat docs/PLAN-OTP-JALUR-KEDUA.md §9.
                claimed_phone_origin VARCHAR(16) NULL,

                provider_meta LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                -- Jalur panas: 'voucher hidup milik nomor ini'. Dipakai
                -- penunjuk status di modal, gerbang jeda penerbitan, dan
                -- pencarian saat klaim — ketiganya menyaring nomor lalu
                -- mengurutkan waktu.
                KEY idx_voucher_phone (phone, expires_at),

                -- Pembersihan berkala.
                KEY idx_voucher_expires (expires_at),

                -- Menelusuri balik dari sebuah tanda tangan ke voucher yang
                -- membukanya.
                KEY idx_voucher_challenge (claimed_challenge_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Patient-initiated one-time codes, unbound until claimed'
        ");

        if ($ok === false) {
            throw new \RuntimeException('create ezdoc_otp_vouchers failed: ' . $conn->error);
        }
    },
    'down' => function ($conn): void {
        $conn->query('DROP TABLE IF EXISTS ezdoc_otp_vouchers');
    },
];
