<?php
/**
 * V6 — kode OTP terikat dokumen sejak lahir.
 *
 * Lihat docs/PLAN-OTP-JALUR-KEDUA.md §8h.
 *
 * ## Kenapa ini menghapus tabel, bukan menambah
 *
 * `ezdoc_otp_vouchers` ada karena satu alasan: kode lahir sebelum ada yang tahu
 * untuk dokumen mana, jadi ia cuma bisa terikat ke nomor HP.
 *
 * Begitu QR-nya pindah ke layar petugas — di dokumen yang sedang dibuka — alasan
 * itu hilang. Kodenya terikat dokumen sejak awal, dan yang tersisa persis bentuk
 * tabel ini: sudah terikat ke (template, slot, dokumen, nomor HP). Yang kurang
 * cuma cara pasien memulainya.
 *
 * ## Yang berubah, dan kenapa aman
 *
 * `phone` dan `otp_hash` dilonggarkan jadi NULL. Keduanya belum diketahui saat
 * challenge dibuat: nomornya baru ketahuan dari siapa yang memindai, dan kodenya
 * baru dibuat setelah pindaian masuk.
 *
 * Melonggarkan NOT NULL jadi NULL itu pelebaran — tidak ada baris lama yang
 * ditolak, tidak ada data yang hilang. Jalur lama (`createChallenge()`) tetap
 * mengisi keduanya, jadi perilakunya tidak berubah sedikit pun.
 *
 * ## Kenapa unique index di sini AMAN, padahal minggu lalu justru berbahaya
 *
 * Di `subject_type` (migrasi 2026-08-04) kolom NULL di dalam kunci unik
 * menghancurkan keunikannya, karena NULL dianggap berbeda dari NULL — dua baris
 * yang seharusnya bentrok jadi sama-sama diterima.
 *
 * Di sini sifat yang sama justru yang dibutuhkan. `scan_token` NULL untuk semua
 * challenge jalur lama, dan mereka memang tidak boleh saling bentrok. Yang perlu
 * unik hanya token yang benar-benar terisi — dan itu persis yang dilakukan
 * unique index terhadap nilai non-NULL.
 *
 * Aturan yang sama, dua akibat berlawanan, tergantung apakah kolomnya pembeda
 * atau penunjuk.
 */

use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Db\Schema\ColumnIntrospector;

return [
    'name' => '2026_08_05_000001_alter_ttd_otp_challenges_add_scan_token',
    'up' => function ($conn): void {
        $db = new MysqliConnection($conn);
        $introspector = new ColumnIntrospector($db);

        $columns = [
            // Token yang dibawa QR. Acak, sekali pakai, dan tidak menyebut apa
            // pun tentang dokumennya — teks yang dikirim pasien tersimpan di
            // server penyedia chat, jadi ia tidak membawa identitas siapa pun.
            'scan_token' => "ADD COLUMN `scan_token` VARCHAR(64) NULL"
                . " COMMENT 'Token di QR. Acak; tidak memuat identitas apa pun.'"
                . " AFTER `ttd_id`",

            'scanned_at' => "ADD COLUMN `scanned_at` DATETIME NULL"
                . " COMMENT 'Saat pasien memindai. NULL = QR belum dipindai.'"
                . " AFTER `scan_token`",

            // Salinan pesan yang BENAR-BENAR dikirim ke pasien.
            //
            // Pertanyaan saat sengketa bukan "apa isi dokumennya" tapi "apa yang
            // diberitahukan ke pasien saat itu". Merangkainya ulang nanti tidak
            // menjawab itu: nama template bisa berubah, dan hasil rangkaian ulang
            // belum tentu sama dengan yang dulu dibaca pasien.
            'consent_message' => "ADD COLUMN `consent_message` LONGTEXT NULL"
                . " COMMENT 'Teks persetujuan yang dikirim ke pasien, apa adanya.'"
                . " AFTER `provider_meta`",
        ];

        foreach ($columns as $name => $clause) {
            if ($introspector->hasColumn('ezdoc_ttd_otp_challenges', $name)) {
                continue;
            }

            if ($conn->query('ALTER TABLE `ezdoc_ttd_otp_challenges` ' . $clause) === false) {
                throw new \RuntimeException(
                    "add column {$name} to ezdoc_ttd_otp_challenges failed: " . $conn->error
                );
            }

            $introspector->forget('ezdoc_ttd_otp_challenges');
        }

        // Pelebaran, bukan penyempitan: baris lama tetap sah, dan jalur lama
        // tetap mengisi keduanya.
        $widen = [
            "MODIFY COLUMN `phone` VARCHAR(30) NULL COMMENT 'NULL sampai pasien memindai'",
            "MODIFY COLUMN `otp_hash` VARCHAR(255) NULL COMMENT 'NULL sampai kode dibuat'",
        ];
        foreach ($widen as $clause) {
            if ($conn->query('ALTER TABLE `ezdoc_ttd_otp_challenges` ' . $clause) === false) {
                throw new \RuntimeException('widen ezdoc_ttd_otp_challenges failed: ' . $conn->error);
            }
        }

        // Unik hanya untuk token yang terisi — lihat catatan di atas soal kenapa
        // NULL di sini aman, padahal di subject_type justru berbahaya.
        $conn->query(
            'CREATE UNIQUE INDEX `uk_scan_token` ON `ezdoc_ttd_otp_challenges` (`scan_token`)'
        );
    },
    'down' => function ($conn): void {
        $conn->query('DROP INDEX `uk_scan_token` ON `ezdoc_ttd_otp_challenges`');
        $conn->query('ALTER TABLE `ezdoc_ttd_otp_challenges` DROP COLUMN `consent_message`');
        $conn->query('ALTER TABLE `ezdoc_ttd_otp_challenges` DROP COLUMN `scanned_at`');
        $conn->query('ALTER TABLE `ezdoc_ttd_otp_challenges` DROP COLUMN `scan_token`');
    },
];
