<?php
/**
 * Jalankan `chart_render` lewat dispatcher, di prosesnya sendiri.
 *
 *   php run_chart_render.php <spec-json> [values-json]
 *
 * Tanpa basis data sama sekali — itu memang sifat jalur ini: spesifikasi dan
 * angkanya datang dari permintaan, digambar, dikembalikan. Designer
 * memakainya untuk template yang belum pernah disimpan.
 *
 * Dipakai tests/Chart/ChartRenderActionTest.php.
 */

$root = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'POST';

require $root . '/bootstrap.php';

/**
 * Argumen boleh ber-base64 dengan awalan `b64:`.
 *
 * JSON penuh tanda kutip, dan `escapeshellarg()` di Windows tidak
 * menyelamatkannya — tanda kutipnya hilang dan yang sampai ke sini bukan JSON
 * lagi. Base64 membuat isinya tidak tergantung cara shell mengutip.
 */
$ambil = static function (string $arg): string {
    if (strncmp($arg, 'b64:', 4) === 0) {
        $isi = base64_decode(substr($arg, 4), true);

        return $isi === false ? '' : $isi;
    }

    return $arg;
};

$_POST = [
    '_doc_action' => 'chart_render',
    'spec' => $ambil((string) ($argv[1] ?? '')),
    'values' => $ambil((string) ($argv[2] ?? '')),
];

require $root . '/actions/_dispatcher.php';
