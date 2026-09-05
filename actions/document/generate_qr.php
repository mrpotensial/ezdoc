<?php
/**
 * GET ?action=generate_qr&data=<url-encoded>
 * Return JSON { success: true, qr: 'data:image/png;base64,...' } untuk client
 * render QR di HTML preview.
 *
 * Auth: any authenticated user (default: consumer's bootstrap sudah gate).
 *
 * Dependencies (dari main page scope):
 *   - $conn (mysqli)
 *   - QR generator, lewat ezdoc_qr_data_uri(): Ezdoc\Rendering\QrRenderer dari
 *     Context, atau jalur usang generateQrForDompdf() milik aplikasi pemakai
 */

$data = trim((string)($_GET['data'] ?? ''));
if ($data === '') {
    ezdoc_respond_error(t('response.qr_data_empty', [], 'QR data is empty'));
}

// Satu pintu: kontraknya dulu, jalur usang menyusul, lihat ezdoc_qr_data_uri().
// Ia tidak pernah melempar — string kosong berarti tidak ada generator, dan DI
// SINI itu memang galat: pemanggilnya minta QR, bukan halaman yang tetap jadi
// tanpa QR.
$qrSrc = ezdoc_qr_data_uri($data, 200, 5);

if ($qrSrc === '') {
    ezdoc_respond_error(
        t('response.qr_generator_unavailable', [], 'No QR generator is configured'),
        500
    );
}

ezdoc_respond_success(['qr' => $qrSrc]);
