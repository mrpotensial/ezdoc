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

    // Bahasa antarmuka: 'id' | 'en'
    'app.locale' => 'id',
];

// ── Cara 2: serahkan pemuatan bootstrap ke App ──────────────────────────────
//
// `App::run()` bisa me-require file bootstrap host sendiri dan mengangkat
// $conn / $author_id / $author_role_array ke global. Berguna kalau file
// bootstrap-nya tidak nyaman di-require dua kali.
//
// return [
//     'app.bootstrap' => __DIR__ . '/../../koneksi.php',
//     'app.locale'    => 'id',
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
