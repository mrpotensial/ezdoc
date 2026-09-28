<?php

/**
 * Contoh mount config untuk front controller `public/index.php`.
 *
 * Salin jadi `public/config.php` dan sesuaikan. `index.php` memakainya kalau
 * ada; kalau tidak ada, ia jatuh ke `App::demo()` (SQLite, tanpa identitas).
 *
 * ── Kenapa file ini penting ────────────────────────────────────────────────
 *
 * `public/index.php` adalah pintu yang berdiri sendiri. Ia TIDAK memuat
 * bootstrap aplikasi host, jadi sesi, koneksi, dan identitas pengguna tidak
 * ada di sana kecuali file ini yang membawanya.
 *
 * Gejala kalau identitasnya tidak dibawa: role check tetap lolos, bypass
 * superadmin tetap jalan, tapi setiap endpoint yang bertanya *siapa*
 * penggunanya menolak dengan 401 — dan setiap entri audit mencatat pelaku 0.
 * Terlihat seperti masalah otorisasi, padahal request-nya cuma anonim.
 *
 * Dua cara membawanya. Pilih satu.
 */

// ── Cara 1: muat bootstrap host, lalu teruskan globalnya ────────────────────
//
// Paling sederhana kalau host punya satu file bootstrap yang menyiapkan
// koneksi + sesi (di SIMpel: koneksi.php). Sesuaikan pathnya.

require_once __DIR__ . '/../../koneksi.php';

return [
    'app.db' => $conn ?? null,

    // Identitas dan role berjalan berpasangan. Membawa satu tanpa yang lain
    // menghasilkan request setengah terpasang — lihat catatan di atas.
    'app.author_id'    => $author_id ?? null,
    'app.author_roles' => $author_role_array ?? [],

    // Bahasa antarmuka: 'en' (bawaan) | 'id'
    'app.locale' => 'en',

    // Bahasa ISI dokumen — tanggal ("22 Agustus 2026") dan angka ("1.500")
    // di dokumen, cetakan, dan arsip PDF bekunya. Kosong = ikut app.locale.
    // 'document.locale' => 'id',

    // ── Kop surat organisasi ──
    //
    // Dibaca contoh template (?ezdoc_page=contoh). Berkas contoh di folder
    // `contoh/` sengaja memakai kop karangan supaya pustaka ini tidak memihak
    // satu organisasi pun; nilai di bawah inilah yang menggantikannya — di
    // layar, di cetakan, dan di template yang disimpan dari contoh itu.
    //
    // Tanpa ini contohnya tetap terbuka, cuma kopnya karangan. Nilai di sini
    // adalah titik awal: halaman contoh punya panel "Kop surat" yang bisa
    // menimpanya tanpa menyentuh berkas ini (tersimpan di folder penyimpanan
    // sebagai kop-contoh.json).
    //
    // 'letterhead.name'    => 'NAMA ORGANISASI ANDA',
    // 'letterhead.address' => 'Alamat satu baris',   // larik = beberapa baris
    // 'letterhead.city'    => 'Kota',                // dipakai di atas tanda tangan
    // 'letterhead.logo'    => __DIR__ . '/logo.png', // atau data URI
];

// ── Cara 2: serahkan pemuatan bootstrap ke App ──────────────────────────────
//
// `App::run()` bisa me-require file bootstrap host sendiri dan mengangkat
// $conn / $author_id / $author_role_array ke global. Berguna kalau file
// bootstrap-nya tidak nyaman di-require dua kali.
//
// return [
//     'app.bootstrap' => __DIR__ . '/../../koneksi.php',
//     'app.locale'    => 'en',
// ];

// ── Catatan ────────────────────────────────────────────────────────────────
//
// - `app.db` menerima mysqli maupun PDO. Untuk PDO, Grammar-nya dipilih dari
//   driver (MySQL/MariaDB/SQLite/Postgres/SQL Server).
// - Kalau host memakai auth framework (Laravel, Symfony, Filament), jangan
//   pakai global sama sekali: implement `Ezdoc\Auth\RoleProvider` lalu inject
//   lewat `Context::withRoleProvider()` atau `ezdoc_set_role_provider()`.
// - Kalau mount ini memang untuk pemakaian anonim (demo, kios publik),
//   definisikan `EZDOC_SILENCE_AUTH_WARNING` supaya peringatan "no
//   authenticated user" tidak memenuhi log.
