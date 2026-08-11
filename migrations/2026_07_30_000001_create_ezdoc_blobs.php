<?php
/**
 * Create ezdoc_blobs — binary objects addressed by their content.
 *
 * Alasan tabel ini ada: gambar tanda tangan terukur 97,3% dari data dokumen, dan
 * dengan ROW_FORMAT=DYNAMIC sebuah baris InnoDB menyimpan kolom variabel INLINE
 * selama barisnya masih muat di separuh page. Setelah gambarnya dinormalisasi
 * jadi ~3,5 KB, ia justru masuk kembali ke clustered index — jadi setiap
 * pengambilan dokumen membaca gambar itu, diminta atau tidak. Memindahkan byte-nya
 * ke tabel sendiri membuat baris dokumen kurus tanpa syarat, berapa pun ukuran
 * gambarnya nanti.
 *
 * ## Kunci = isi
 *
 * `key` adalah SHA-256 dari byte-nya. Bukan pilihan gaya:
 *
 *   - Hash tanda tangan (§5 docs/260730-catatan-tanda-tangan.md) mencakup `ttd_value`.
 *     Begitu gambarnya keluar dari baris dokumen, `ttd_value` jadi referensi.
 *     Referensi yang menyebut LOKASI bisa menunjuk byte lain besok; referensi
 *     yang menyebut ISI tidak bisa.
 *   - Syarat rencana "simpan hash gambar di DB" terpenuhi dengan sendirinya —
 *     kuncinya sudah checksum-nya.
 *   - Byte identik yang disimpan dua kali menempati satu baris.
 *
 * `checksum` tetap ada sebagai kolom terpisah walau redundan: bentuknya
 * (`sha256:<hex>`) menyebut algoritmanya, jadi kalau nanti pindah algoritma,
 * baris lama masih bisa menyebutkan miliknya sendiri.
 *
 * ## Dua backend, satu baris
 *
 * `content` NULL berarti byte-nya di disk (lihat DiskBlobStore); non-NULL berarti
 * di sini. `backend` menyebut yang mana. Bentuk ini mengikuti
 * `active_storage_blobs` Rails supaya tempat byte tinggal jadi pilihan config,
 * bukan komitmen skema.
 *
 * LONGBLOB, bukan MEDIUMBLOB: tanda tangan ~3,5 KB, tapi tabel ini bukan hanya
 * untuk tanda tangan, dan batas 16 MB adalah jenis plafon yang baru terasa saat
 * sudah terlambat.
 *
 * ## Tanpa FK ke dokumen
 *
 * Sengaja. Satu blob bisa dirujuk banyak dokumen (isi yang sama → kunci yang
 * sama), jadi tidak ada satu induk yang bisa dijadikan FK. Keterjangkauan
 * diputuskan rutinitas purge yang memindai referensi, bukan oleh constraint —
 * dan itu pola yang sama dengan `Blob.unattached` di ActiveStorage.
 */

return [
    'name' => '2026_07_30_000001_create_ezdoc_blobs',
    'up' => function ($conn): void {
        $ok = $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_blobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                `key` CHAR(64) NOT NULL COMMENT 'SHA-256 of the content, hex — the identity',
                checksum VARCHAR(80) NOT NULL COMMENT 'algo:hex, names the algorithm for future migration',
                byte_size BIGINT UNSIGNED NOT NULL,
                content_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
                backend VARCHAR(32) NOT NULL DEFAULT 'database' COMMENT 'database | disk',
                content LONGBLOB NULL COMMENT 'NULL when the bytes live on disk',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,

                UNIQUE KEY uq_blob_key (`key`),
                KEY idx_blob_backend (backend),
                KEY idx_blob_deleted (deleted_at),
                KEY idx_blob_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Content-addressed binary objects (signature images, etc)'
        ");

        if ($ok === false) {
            throw new \RuntimeException('create ezdoc_blobs failed: ' . $conn->error);
        }
    },
    'down' => function ($conn): void {
        $conn->query('DROP TABLE IF EXISTS ezdoc_blobs');
    },
];
