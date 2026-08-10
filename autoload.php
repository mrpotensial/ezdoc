<?php

/**
 * ezdoc autoloader — PSR-4 dengan lapisan polyfill.
 *
 * Urutan muat:
 *   1. Polyfill untuk fungsi PHP 8 (str_starts_with, dll)
 *   2. Loader PSR-4 bawaan untuk namespace Ezdoc\ — SELALU didaftarkan
 *   3. Composer kalau ada, dan kalau ia bisa dimuat
 *
 * Target: PHP 7.4+ (maju-kompatibel dengan PHP 8.x).
 *
 * ## Kenapa loader bawaan didaftarkan lebih dulu, bukan sebagai cadangan
 *
 * Versi sebelumnya berhenti begitu `vendor/autoload.php` ditemukan, dengan
 * anggapan Composer pasti bisa menyelesaikan `Ezdoc\*`. Anggapan itu runtuh di
 * pemasangan nyata, dan runtuhnya total: seluruh aplikasi mati.
 *
 * `require` ezdoc tidak memuat satu pun paket — hanya `php >= 7.4` dan dua
 * ekstensi. Jadi `vendor/` di sini **selalu** folder pengembangan, isinya
 * PHPUnit dan PHPStan. Keduanya menuntut PHP 8, dan Composer me-`require`
 * berkas mereka lewat daftar `files` saat autoload dimuat.
 *
 * Akibatnya, satu folder `vendor/` yang ikut tersalin ke server ber-PHP 7
 * menghasilkan ParseError pada `?` dan `|` di berkas PHPUnit — bukan di kode
 * ezdoc — dan setiap halaman menjadi 500 kosong. Kegagalan yang jauh dari
 * sebabnya adalah kegagalan yang mahal dicari.
 *
 * Karena itu sekarang: loader sendiri dulu, Composer belakangan dan boleh
 * gagal. Yang hilang saat Composer gagal cuma paket milik aplikasi pemakai —
 * ezdoc sendiri tetap jalan, dan sebabnya tercatat di log alih-alih meledak.
 */

if (defined('EZDOC_AUTOLOAD_LOADED')) {
    return;
}
define('EZDOC_AUTOLOAD_LOADED', true);

// Langkah 1: polyfill lebih dulu dari apa pun. Loader di bawah memakai
// str_starts_with.
require_once __DIR__ . '/lib/polyfill.php';

// Langkah 2: loader PSR-4 sendiri, tanpa syarat.
//
// Didaftarkan walau Composer ada. Keduanya menunjuk berkas yang sama, jadi
// tidak ada yang bertabrakan — yang didapat cuma jaminan bahwa `Ezdoc\*` selalu
// bisa ditemukan, apa pun keadaan vendor.
spl_autoload_register(static function ($class) {
    if (!str_starts_with($class, 'Ezdoc\\')) {
        return;
    }

    $relative = substr($class, strlen('Ezdoc\\'));
    $path = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

// Langkah 3: Composer, kalau ada — untuk paket milik aplikasi pemakai.
//
// Kegagalannya ditangkap. ParseError dan Error memang bisa ditangkap sejak PHP
// 7, dan di sini itu bukan kelicikan: pilihannya antara aplikasi yang jalan
// tanpa paket tambahan, atau aplikasi yang mati seluruhnya. Yang pertama masih
// bisa didiagnosis; yang kedua tidak menyisakan petunjuk apa pun.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    try {
        require_once $composerAutoload;
    } catch (\Throwable $e) {
        @error_log(
            '[ezdoc] vendor/autoload.php gagal dimuat dan dilewati: '
            . get_class($e) . ': ' . $e->getMessage()
            . ' di ' . $e->getFile() . ':' . $e->getLine()
            . '. Folder vendor/ milik ezdoc hanya untuk pengembangan — di server '
            . 'ia semestinya tidak ada sama sekali.'
        );
    }
}
