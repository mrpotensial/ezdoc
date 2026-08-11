<?php
/**
 * Buang `ezdoc_otp_vouchers` — jalur voucher sudah tidak ada kodenya.
 *
 * Lihat docs/260803-otp-jalur-kedua.md §8h. Voucher lahir karena kode dibuat
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
 * ## Catatan pendahulunya DIBIARKAN — dan ini pelajaran yang dibayar mahal
 *
 * Versi pertama migrasi ini ikut menghapus baris `ezdoc_migrations` milik
 * migrasi pembuat, dengan alasan yang terdengar rapi: berkasnya sudah tidak
 * dikirim, jadi catatannya cuma nama yang tidak bisa ditelusuri. `migrate:squash`
 * Laravel melakukan hal serupa.
 *
 * Alasan itu berdiri di atas satu anggapan: **berkas pembuatnya benar-benar
 * hilang dari server.** Anggapan itu salah di lapangan. Banyak alat sinkron
 * hanya menambah dan memperbarui, tidak pernah menghapus — jadi berkas yang
 * dihapus dari repositori tetap duduk di server.
 *
 * Akibatnya berantai dan terjadi sungguhan (2026-08-10): tabel dibuang, catatan
 * pembuatnya dihapus, lalu permintaan berikutnya melihat berkas migrasi yang
 * belum tercatat — dan membuat tabelnya lagi.
 *
 * Aturan "migrasi hanya bertambah" ada persis untuk ini. Baris yatim tidak
 * berbahaya: Runner memindai berkas lalu melewati yang sudah tercatat, dan
 * catatan tanpa berkas tidak pernah dicocokkan. Yang berbahaya adalah menghapus
 * catatan sesuatu yang mungkin masih ada.
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

        // Catatan migrasi pembuatnya sengaja TIDAK disentuh — lihat alasannya
        // di atas. Menghapusnya membuat berkas pembuat yang masih tertinggal di
        // server dianggap belum pernah jalan, dan tabelnya lahir kembali.
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
