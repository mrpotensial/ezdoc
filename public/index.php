<?php

declare(strict_types=1);

/**
 * ezdoc demo — front-controller entry.
 *
 * This file is the single mount point for `php -S ... -t ezdoc/public/`.
 * It resolves autoload, then either:
 *   - runs `Ezdoc\App::run(require config.php)` if a sibling config.php exists, or
 *   - falls back to `Ezdoc\App::demo()` for zero-config SQLite mode.
 *
 * A consumer app never needs to touch this file — it's a canonical example
 * of the 1-line mount pattern for the built-in dev server.
 */

// ─── Kegagalan tidak boleh diam ─────────────────────────────────────────
//
// Sebelum ini, apa pun yang melempar di balik pintu ini menghasilkan halaman
// 500 kosong: tidak di layar, dan belum tentu di log. Yang mendiagnosisnya
// terpaksa menebak — dan menebak di sistem orang lain menghabiskan waktu jauh
// lebih banyak daripada memasang penangkap ini.
//
// Dua perilaku, sengaja dipisah:
//
//   1. SELALU dicatat ke log error PHP. Tidak ada alasan menyembunyikan
//      kegagalan dari operator servernya sendiri.
//   2. Ditampilkan di layar HANYA kalau berkas penanda ada.
//      Berkas, bukan konstanta atau variabel lingkungan, karena kegagalannya
//      sering terjadi SEBELUM config sempat dibaca — dan penanda yang baru
//      berlaku setelah config tidak akan pernah menolong di kasus itu.
//
// Dua nama diterima, dan itu bukan kelonggaran tanpa sebab: banyak klien FTP
// menyembunyikan atau menolak berkas berawalan titik, sehingga penanda yang
// hanya bernama `.debug` tidak bisa dipasang justru oleh orang yang paling
// membutuhkannya. `debug.txt` bisa diunggah alat mana pun.
//
// Cara memakainya saat ada masalah: buat berkas kosong bernama
// `ezdoc/public/debug.txt`, muat ulang halamannya, baca pesannya, lalu HAPUS
// berkas itu lagi.
$ezdocShowErrors = is_file(__DIR__ . '/.debug') || is_file(__DIR__ . '/debug.txt');

if ($ezdocShowErrors) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

$ezdocReport = static function (string $jenis, string $pesan, string $berkas, int $baris) use ($ezdocShowErrors): void {
    @error_log(sprintf('[ezdoc] %s: %s in %s:%d', $jenis, $pesan, $berkas, $baris));

    if (!$ezdocShowErrors || headers_sent()) {
        return;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ezdoc gagal memuat halaman.\n\n";
    echo $jenis . ': ' . $pesan . "\n";
    echo 'di ' . $berkas . ':' . $baris . "\n\n";
    echo "Hapus penandanya (ezdoc/public/debug.txt atau .debug) setelah selesai —\n";
    echo "pesan seperti ini tidak boleh terlihat pengunjung.\n";
};

set_exception_handler(static function (\Throwable $e) use ($ezdocReport): void {
    $ezdocReport(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(static function () use ($ezdocReport): void {
    $err = error_get_last();
    // Hanya kesalahan yang benar-benar mematikan proses. Notice dan warning
    // sudah punya jalurnya sendiri dan tidak menghasilkan 500.
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $ezdocReport('Fatal error', $err['message'], $err['file'], (int) $err['line']);
});

// ─── Autoload resolution ──────────────────────────────────────────────
// ALWAYS load library's own PSR-4 loader first — supaya \Ezdoc\* classes
// available bahkan kalau consumer punya Composer autoload sendiri yang tidak
// include ezdoc (mis. ezdoc di-drop sebagai submodule/subtree, bukan composer
// require). ADDITIONALLY: load Composer kalau ada — untuk consumer's other
// deps (tanpa override ezdoc PSR-4).
$libAutoload = __DIR__ . '/../autoload.php';
if (!is_file($libAutoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ezdoc: library autoload missing at {$libAutoload}\n";
    exit;
}
require_once $libAutoload;

// Optional Composer autoload — extends dengan consumer deps kalau ada.
$composerAutoloads = [
    __DIR__ . '/../vendor/autoload.php',       // library dev install (composer require)
    __DIR__ . '/../../vendor/autoload.php',    // consumer app-level Composer
];
foreach ($composerAutoloads as $path) {
    if (!is_file($path)) {
        continue;
    }

    // Alasan yang sama dengan di autoload.php: satu folder vendor pengembangan
    // yang ikut tersalin ke server ber-PHP lama membuat SELURUH halaman mati,
    // dan kegagalannya menunjuk berkas PHPUnit — jauh dari sebabnya. Dilewati
    // dan dicatat lebih berguna daripada meledak.
    try {
        require_once $path;
    } catch (\Throwable $e) {
        @error_log(
            '[ezdoc] Composer autoload dilewati (' . $path . '): '
            . get_class($e) . ': ' . $e->getMessage()
            . ' di ' . $e->getFile() . ':' . $e->getLine()
        );
    }
}

// Final sanity check
if (!class_exists(\Ezdoc\App::class)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ezdoc: \\Ezdoc\\App class not found after autoload. Library install seems incomplete.\n";
    exit;
}

// ─── Config discovery ────────────────────────────────────────────────
// If someone drops a `public/config.php` next to this file, prefer it.
// Otherwise: zero-config demo mode.
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    /** @psalm-suppress UnresolvableInclude */
    $config = require $configFile;
    if (!is_array($config)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ezdoc: config.php must return an array, got " . gettype($config) . "\n";
        exit;
    }
    \Ezdoc\App::run($config);
} else {
    \Ezdoc\App::demo();
}
