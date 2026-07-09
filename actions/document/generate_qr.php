<?php
/**
 * GET ?action=generate_qr&data=<url-encoded>
 * Return JSON { success: true, qr: 'data:image/png;base64,...' } untuk client
 * render QR di HTML preview.
 *
 * Auth: any authenticated user (default koneksi.php sudah gate).
 *
 * Dependencies (dari main page scope):
 *   - $conn (mysqli)
 *   - fungsi generateQrForDompdf($data, $size, $margin) — defined di main page/lib
 */

$data = trim((string)($_GET['data'] ?? ''));
if ($data === '') {
    ezdoc_respond_error('Data QR kosong');
}

if (!function_exists('generateQrForDompdf')) {
    ezdoc_respond_error('QR generator tidak tersedia (generateQrForDompdf missing)', 500);
}

try {
    $qrSrc = generateQrForDompdf($data, 200, 5);
    ezdoc_respond_success(['qr' => $qrSrc]);
} catch (Exception $e) {
    ezdoc_respond_error('Gagal generate QR: ' . $e->getMessage(), 500);
}
