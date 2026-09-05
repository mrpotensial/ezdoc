<?php

/**
 * Dua pembantu terpendek yang dipakai hampir setiap berkas tampilan.
 *
 * Keduanya sebelumnya dideklarasikan di dalam `views/document/generate.php`,
 * dengan penjaga `function_exists`. Itu bekerja selama hanya berkas itu yang
 * membutuhkannya — dan berhenti bekerja begitu perakitan PDF dipindah keluar
 * (lihat docs/260811-beku-pdf-tiap-tanda-tangan.md).
 *
 * Dipindah ke sini apa adanya, tanpa satu huruf pun berubah. Penjaga
 * `function_exists` di tempat lamanya jadi tidak pernah menyala, dan itu memang
 * yang diinginkan: satu definisi, bukan dua yang kebetulan sama hari ini.
 *
 * PHP 7.4+ compatible.
 */

// Local helper — escape output (keeps existing h() call sites working)
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Local helper — translate a dot-notation i18n key (see lang/id/*.php).
// $default preserves the original Indonesian copy so a missing/mistyped key
// still displays correct text instead of a raw key or blank string.
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $default = null): string {
        $translator = $GLOBALS['translator'] ?? null;
        if (!($translator instanceof \Ezdoc\UI\Translator)) {
            return $default !== null ? $default : $key;
        }
        return $translator->t($key, $params, $default);
    }
}


/**
 * Gambar QR sebagai data URI, lewat satu pintu.
 *
 * ## Kenapa ada
 *
 * Sebelumnya kode view memanggil `generateQrForDompdf()` langsung — nama fungsi
 * global milik aplikasi pemakai, bukan milik library. Di `generate_qr.php` ia
 * dijaga `function_exists()`; di tiga tempat lain di view **tidak**.
 *
 * Yang membuatnya berbahaya bukan QR-nya. Perender isi PDF juga dipanggil jalur
 * pembekuan dokumen, dan aturannya: pembekuan gagal berarti tanda tangannya ikut
 * gagal. Satu fungsi host yang kebetulan tidak termuat berubah dari "QR tidak
 * tampil" jadi "tidak ada yang bisa menandatangani".
 *
 * ## Urutannya
 *
 * 1. {@see \Ezdoc\Rendering\QrRenderer} dari Context — jalur yang seharusnya
 * 2. `generateQrForDompdf()` kalau ada — jalur USANG, dipertahankan supaya
 *    pemasangan yang sudah terlanjur memakainya tidak rusak saat naik versi
 * 3. string kosong — QR-nya hilang, dokumennya tetap jadi
 *
 * Urutan dan alasannya sama persis dengan {@see ezdoc_actor_names()}.
 *
 * Tidak pernah melempar. Ini jalur tampilan: generator yang rusak boleh
 * menghilangkan QR-nya, bukan halamannya.
 */
function ezdoc_qr_data_uri(string $data, int $size = 200, int $margin = 5): string
{
    if ($data === '') {
        return '';
    }

    try {
        $ctx = \Ezdoc\Context::default();
        if ($ctx->qr !== null) {
            return $ctx->qr->render($data, $size, $margin);
        }
    } catch (\Throwable $e) {
        // Context belum disetel, atau generatornya melempar. Coba jalur usang.
    }

    if (function_exists('generateQrForDompdf')) {
        try {
            return (string) generateQrForDompdf($data, $size, $margin);
        } catch (\Throwable $e) {
            return '';
        }
    }

    return '';
}
