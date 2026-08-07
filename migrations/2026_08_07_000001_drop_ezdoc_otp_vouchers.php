<?php
/**
 * Buang `ezdoc_otp_vouchers` — jalur voucher sudah tidak ada kodenya.
 *
 * Lihat docs/PLAN-OTP-JALUR-KEDUA.md §8h. Voucher lahir karena kode dibuat
 * sebelum ada yang tahu untuk dokumen mana, jadi ia hanya bisa terikat ke nomor
 * HP. Sejak QR pindah ke layar petugas, kodenya terikat dokumen sejak lahir dan
 * alasan tabel ini hilang seluruhnya.
 *
 * ## Kenapa migrasi pembuatnya ikut dibuang, bukan disimpan
 *
 * Biasanya migrasi adalah buku besar yang hanya bertambah: yang salah diperbaiki
 * migrasi berikutnya, bukan dengan menyunting yang lama. Aturan itu ada untuk
 * satu tujuan — setiap basis data yang pernah menjalankan migrasi lama harus
 * bisa dibawa ke keadaan terbaru. Migrasi ini yang menjaminnya, jadi tujuannya
 * tetap terpenuhi.
 *
 * Yang tersisa cuma pilihan: menyimpan pasangan CREATE lalu DROP yang dijalankan
 * setiap pemasangan baru selamanya, atau membuang CREATE-nya karena paketnya
 * memang belum pernah dirilis. Dipilih yang kedua.
 *
 * Baris di `ezdoc_migrations` yang menyebut migrasi pembuat itu ikut dibuang di
 * sini. Ia tidak berbahaya — Runner memindai berkas lalu melewati yang sudah
 * tercatat, jadi catatan tanpa berkas tidak pernah dicocokkan — tapi ia
 * membingungkan: nama migrasi yang tidak bisa dicari berkasnya. Memangkas
 * catatan pendahulunya memang bagian dari menyatukan migrasi; `migrate:squash`
 * Laravel melakukan hal yang sama.
 *
 * ## Catatan audit TIDAK ikut dibuang
 *
 * `otp.voucher_issued` dan `otp.voucher_claimed` tetap ada di
 * `ezdoc_audit_log`, dan labelnya tetap ada di berkas bahasa. Baris audit adalah
 * catatan bahwa sesuatu benar-benar terjadi; ia tidak berhenti benar hanya
 * karena jalurnya dipensiunkan. Menghapusnya akan membuat riwayat dokumen
 * berlubang persis di bagian yang menerangkan bagaimana sebuah tanda tangan
 * dulu diverifikasi.
 */

return [
    'name' => '2026_08_07_000001_drop_ezdoc_otp_vouchers',
    'up' => function ($conn): void {
        // IF EXISTS, karena pemasangan baru tidak pernah punya tabel ini —
        // migrasi pembuatnya sudah tidak dikirim.
        if ($conn->query('DROP TABLE IF EXISTS `ezdoc_otp_vouchers`') === false) {
            throw new \RuntimeException('drop ezdoc_otp_vouchers failed: ' . $conn->error);
        }

        // Catatan pendahulunya ikut dipangkas — berkasnya sudah tidak dikirim,
        // jadi yang tersisa hanya nama yang tidak bisa ditelusuri. Kalau
        // gagal, dibiarkan: tabelnya sudah hilang, dan itu yang menentukan.
        @$conn->query(
            "DELETE FROM `ezdoc_migrations`"
            . " WHERE `migration` = '2026_08_03_000001_create_ezdoc_otp_vouchers'"
        );
    },
    'down' => function ($conn): void {
        // Sengaja tidak membuat ulang. Tidak ada satu pun kode yang membaca atau
        // menulis tabel ini lagi, jadi yang dihasilkan hanya tabel kosong yang
        // tak terjangkau — bukan pemulihan, cuma puing.
        //
        // Tidak melempar juga: `down` kadang dijalankan berurutan untuk memutar
        // balik beberapa migrasi sekaligus, dan yang melempar di sini akan
        // menghentikan pemutaran migrasi lain yang justru bisa dibalik.
    },
];
