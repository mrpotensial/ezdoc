<?php

/**
 * Mount config untuk front controller `public/index.php`.
 *
 * `public/index.php` adalah pintu yang berdiri sendiri — ia tidak memuat
 * bootstrap aplikasi host. Tanpa file ini, tidak ada sesi, tidak ada koneksi,
 * dan tidak ada identitas pengguna di balik pintu itu.
 *
 * koneksi.php menyediakan semuanya sekaligus:
 *   - session_start()                        (baris 19)
 *   - $conn                                  (baris 77)
 *   - $author_id dari $_SESSION              (baris 3590)
 *   - $author_role_array dari $_SESSION      (baris 3733)
 *   - EZDOC_BOOTSTRAP_HOOK → ezdoc_otp_setup.php, yang mewiring provider OTP
 *                                            (baris 66-67)
 *
 * Pola yang sama sudah lama dipakai `page/ezdoc_action.php`.
 */

require_once __DIR__ . '/../../koneksi.php';

// Gambar tanda tangan ke berkas JUGA, bukan HANYA — MirrorBlobStore.
//
// Ditulis di sini, bukan di berkas consumer bersama, karena pemasangan lama yang
// memakai basis data yang sama tidak mengenal nilai `mirror` dan masih membaca
// kolom `ezdoc_blobs.content`. Mode ini tetap menulis kolom itu persis seperti
// sebelumnya, jadi pemasangan lama tidak melihat perbedaan apa pun.
//
// Foldernya sama dengan arsip PDF beku (EZDOC_ARTIFACT_STORAGE_PATH): satu
// ruang nama objek, satu folder, satu yang dicadangkan.
if (!defined('EZDOC_STORAGE_BACKEND')) {
    define('EZDOC_STORAGE_BACKEND', 'mirror');
}
if (!defined('EZDOC_STORAGE_PATH')) {
    define('EZDOC_STORAGE_PATH', dirname(__DIR__, 2) . '/storage/ezdoc');
}

return [
    'app.db' => $conn ?? null,

    // Identitas dan role dikirim berpasangan. Membawa satu tanpa yang lain
    // menghasilkan request setengah terpasang: role check lolos dan bypass
    // superadmin jalan, tapi setiap endpoint yang bertanya SIAPA penggunanya
    // menolak dengan 401 — dan setiap entri audit mencatat pelaku 0.
    'app.author_id'    => $author_id ?? null,
    'app.author_roles' => $author_role_array ?? [],

    // ── Kop surat ──
    // Dibaca contoh template (?ezdoc_page=example). Berkas contohnya sendiri
    // netral — kop karangan — dan nilai di bawah inilah yang menggantikannya,
    // di layar maupun di template yang disimpan dari contoh itu. Jadi contoh
    // langsung terlihat seperti dokumen tempat ini, tanpa satu nama pun
    // tertanam di berkas contohnya.
    //
    // Logonya ikut tersimpan di tiap template yang dibuat dari contoh, jadi
    // yang kelewat lebar dikecilkan otomatis saat dibaca.
    'letterhead.name'    => 'RUMAH SAKIT IBU DAN ANAK ANUGRAH',
    'letterhead.address' => [
        'Jalan Sungai Raya Dalam No. 43, Sungai Raya 78391 · Telp. (0561) 721176 / 723368',
        'WA 0821-4866-3619 · rsia_anugrah@yahoo.com · Kubu Raya, Kalimantan Barat',
    ],
    'letterhead.city'    => 'Kubu Raya',
    'letterhead.logo'    => dirname(__DIR__, 2) . '/uploads/LOGO RSIA ANUGRAH.png',

    // Versi baru di sini selalu revisi dari yang sebelumnya, jadi pilihan
    // "mulai dari kosong" ditutup: dialognya tinggal konfirmasi versi mana
    // yang disalin. Versi PERTAMA di sebuah slot tetap lahir kosong — tidak
    // ada yang bisa disalin.
    'version.allow_blank' => false,

    'app.locale' => 'id',
    // Ditulis terpisah, bukan dibiarkan ikut layar: kalau suatu hari layarnya
    // dipindah ke bahasa lain, isi dokumen tetap berbahasa Indonesia — tanggal
    // "22 Agustus 2026", angka "1.500" — di dokumen, di cetakan, dan di arsip
    // PDF bekunya, yang tidak bisa diperbarui lagi setelah ditandatangani.
    'document.locale' => 'id',
];
