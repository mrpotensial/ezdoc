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

return [
    'app.db' => $conn ?? null,

    // Identitas dan role dikirim berpasangan. Membawa satu tanpa yang lain
    // menghasilkan request setengah terpasang: role check lolos dan bypass
    // superadmin jalan, tapi setiap endpoint yang bertanya SIAPA penggunanya
    // menolak dengan 401 — dan setiap entri audit mencatat pelaku 0.
    'app.author_id'    => $author_id ?? null,
    'app.author_roles' => $author_role_array ?? [],

    'app.locale' => 'en',
    // Isi dokumen tetap berbahasa Indonesia walau layar diuji dalam bahasa
    // Inggris: tanggal "22 Agustus 2026", angka "1.500" — di dokumen, di
    // cetakan, dan di arsip PDF bekunya.
    'document.locale' => 'id',
];
