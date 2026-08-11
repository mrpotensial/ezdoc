<?php
/**
 * Create ezdoc_document_artifacts — rendering yang dibekukan jadi byte tetap.
 *
 * Lihat docs/260805-psre-meterai.md §7b (A2). Sampai sekarang dokumen dirender
 * ulang dari template setiap kali dibuka. Tanda tangan kita tetap terverifikasi
 * kalau templatenya berubah — ia mencakup data, bukan tampilan — tapi yang
 * DIBACA orang jadi berbeda dari yang dulu mereka setujui. Tabel ini yang
 * menutup selisih itu.
 *
 * ## Tidak ada kolom hash, dan itu disengaja
 *
 * `blob_key` adalah SHA-256 isinya — `ezdoc_blobs` memang content-addressed, dan
 * `BlobStore::get()` sudah wajib menolak byte yang tidak cocok dengan kuncinya.
 * Menambah kolom `pdf_hash` berarti menyimpan kebenaran yang sama dua kali, dan
 * dua salinan bisa berselisih. Rencana lama menyimpannya terpisah karena
 * berkasnya di disk tanpa penjaga; kita tidak punya masalah itu.
 *
 * ## Tidak ada kolom status di ezdoc_documents
 *
 * Godaannya menambah `doc_state` + `active_pdf_path` + `active_pdf_hash` +
 * `active_sign_cycle` seperti rencana lama. Empat kolom yang harus dijaga tetap
 * sejalan dengan tabel ini — dan yang bisa berselisih akan berselisih.
 *
 * Statusnya **diturunkan**: dokumen beku kalau ia punya artefak yang belum
 * digantikan. Satu sumber kebenaran, dan pertanyaannya dijawab satu indeks.
 * Bentuk yang sama dipakai `has_one :current_*` di Rails.
 *
 * ## Baris tidak pernah dihapus
 *
 * Membekukan ulang tidak menimpa: baris lama ditandai `superseded_at`, baris
 * baru lahir dengan `cycle` berikutnya. Artefak yang pernah dipegang orang di
 * luar tidak boleh menghilang dari catatan hanya karena ada versi yang lebih
 * baru — pertanyaan saat sengketa justru "yang mana yang dulu saya terima".
 *
 * ## `cycle`, bukan `version`
 *
 * `ezdoc_documents.version` sudah berarti hal lain (versi slot). Memakai kata
 * yang sama untuk dua hitungan berbeda di satu skema adalah cara yang rapi untuk
 * membuat orang berikutnya salah membaca query.
 *
 * ## Tanpa FK ke ezdoc_blobs
 *
 * Alasannya sama dengan `ezdoc_blobs` yang tidak punya FK ke dokumen: kunci
 * adalah isi, jadi dua artefak dengan byte identik menunjuk satu baris blob.
 * Tidak ada satu induk yang bisa dijadikan constraint. Keterjangkauan diputuskan
 * rutinitas purge yang memindai rujukan — dan tabel ini salah satu perujuknya.
 */

return [
    'name' => '2026_08_08_000001_create_ezdoc_document_artifacts',
    'up' => function ($conn): void {
        $ok = $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_document_artifacts (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL COMMENT 'Rujukan luar yang stabil — dipakai tautan verifikasi',
                document_id BIGINT NOT NULL COMMENT 'FK ke ezdoc_documents.id',
                cycle INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Naik tiap bekukan-cabut-bekukan',

                blob_key CHAR(64) NOT NULL COMMENT 'SHA-256 isinya. Ini SEKALIGUS hash artefaknya',

                -- Daftar tanda tangan data yang ikut dibekukan artefak ini,
                -- beserta content_hash masing-masing. Inilah yang mengikat dua
                -- pertanyaan jadi satu pemeriksaan: 'apakah dokumen ini utuh'
                -- dan 'siapa menyatakan apa di dalamnya'.
                manifest JSON NULL,

                frozen_at DATETIME(3) NOT NULL COMMENT 'Presisi milidetik — dua pembekuan bisa jatuh di detik yang sama',
                frozen_by VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Identitas buram, sama bentuknya dengan ezdoc_signatures.signer_id',

                superseded_at DATETIME(3) NULL COMMENT 'NULL = artefak aktif. Terisi = sudah digantikan siklus berikutnya',
                superseded_by VARCHAR(128) NULL,
                superseded_reason TEXT NULL COMMENT 'Kenapa pembekuannya dicabut. Wajib diisi pemanggil',

                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_artifact_uuid (uuid),
                UNIQUE KEY uq_artifact_cycle (document_id, cycle),
                KEY idx_artifact_document (document_id),
                KEY idx_artifact_blob (blob_key),
                KEY idx_artifact_frozen (frozen_at),
                -- Jalur panas: 'artefak aktif milik dokumen ini'. Dipakai tiap
                -- kali dokumen disimpan maupun dibuka, jadi ia layak indeks
                -- sendiri alih-alih menyaring hasil idx_artifact_document.
                KEY idx_artifact_active (document_id, superseded_at),

                CONSTRAINT fk_ezdoc_artifacts_document
                    FOREIGN KEY (document_id) REFERENCES ezdoc_documents(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Rendering dokumen yang dibekukan — byte tetap, dialamatkan isinya'
        ");

        if ($ok === false) {
            throw new \RuntimeException(
                'create ezdoc_document_artifacts failed: ' . $conn->error
            );
        }
    },
    'down' => function ($conn): void {
        $conn->query('DROP TABLE IF EXISTS ezdoc_document_artifacts');
    },
];
