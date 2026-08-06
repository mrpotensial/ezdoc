<?php

declare(strict_types=1);

/**
 * Action: terbitkan token pindaian untuk QR di layar petugas.
 *
 * Endpoint: POST _doc_action=request_scan_code
 *
 * Params:
 *   - template_id : int    (wajib)
 *   - ttd_id      : string (wajib) slot tanda tangannya
 *   - doc_id      : int    (opsional)
 *
 * Lihat docs/PLAN-OTP-JALUR-KEDUA.md §8h.
 *
 * ## Kenapa tautannya dirakit di sini, bukan di sisi tampilan
 *
 * Token tidak boleh pernah muncul di HTML halaman kecuali sebagai bagian dari
 * tautan QR-nya. Merakitnya di JavaScript berarti tokennya lewat sebagai data
 * telanjang lebih dulu, dan ia jadi ikut tersalin ke mana pun potongan itu
 * dibaca — konsol, ekstensi peramban, tangkapan layar dukungan teknis.
 *
 * ## Yang TIDAK ada di respons ini
 *
 * Kode OTP-nya. Kode baru lahir setelah ada yang memindai, dan hanya dikirim ke
 * penanda tangan lewat kanalnya sendiri. Petugas mendapatkannya dari mulut
 * penanda tangan — itu inti seluruh rancangan.
 */

use Ezdoc\Context;
use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Otp\ScanOtpService;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

ezdoc_require_auth();

$templateId = (int) ($_POST['template_id'] ?? 0);
$ttdId      = trim((string) ($_POST['ttd_id'] ?? ''));
$docId      = isset($_POST['doc_id']) && $_POST['doc_id'] !== '' ? (int) $_POST['doc_id'] : null;

if ($templateId <= 0 || $ttdId === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Missing required parameters'), 400);
}

$urlTemplate = defined('EZDOC_SCAN_URL_TEMPLATE') ? (string) EZDOC_SCAN_URL_TEMPLATE : '';
if ($urlTemplate === '' || strpos($urlTemplate, '{token}') === false) {
    // Dinyatakan, bukan gagal diam-diam. Petugas yang menekan tombolnya berhak
    // tahu bedanya "sedang rusak" dan "belum dipasang".
    ezdoc_respond_error(
        t('response.scan_not_configured', [], 'Scan-to-sign is not configured for this installation.'),
        503
    );
}

$db = Context::default()->connection();

$template = $db->fetchOne(
    'SELECT `content`, `access_config` FROM `ezdoc_templates` WHERE `id` = ? LIMIT 1',
    [$templateId]
);
if (!$template) {
    ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'), 404);
}

ezdoc_require_on_template(
    ezdoc_parse_access_config($template['access_config'] ?? null),
    'edit'
);

// Konfigurasi slot yang berwenang, bukan permintaannya — sama seperti
// send_ttd_otp.php.
$ttdConfig = OtpService::findTtdConfig((string) ($template['content'] ?? ''), $ttdId);

if (!$ttdConfig['found']) {
    ezdoc_respond_error(t('response.otp_slot_not_found', [], 'Signature slot not found in the template.'), 404);
}
if (!$ttdConfig['require_otp']) {
    ezdoc_respond_error(t('response.otp_not_required', [], 'This signature slot does not require OTP verification.'), 400);
}
if (!ezdoc_can_sign_ttd($ttdConfig['rbac'])) {
    ezdoc_respond_error(t('response.forbidden', [], 'Not permitted'), 403);
}

try {
    $issued = (new ScanOtpService())->issue($templateId, $ttdId, $docId);
} catch (\Throwable $e) {
    @error_log('[ezdoc:scan:request] ' . $e->getMessage());
    ezdoc_respond_error(t('response.scan_request_failed', [], 'Could not create the code request.'), 500);
}

ezdoc_audit_log('otp.scan_requested', [
    'target_type' => 'document',
    'target_id'   => (string) ($docId ?? $templateId),
    'doc_id'      => $docId,
    'metadata'    => [
        'template_id'  => $templateId,
        'ttd_id'       => $ttdId,
        'challenge_id' => $issued['challenge_id'],
        'expires_at'   => $issued['expires_at'],
    ],
]);

$scanUrl = str_replace('{token}', rawurlencode($issued['scan_token']), $urlTemplate);

// Gambar QR-nya dirakit di sini, dan tautannya TIDAK ikut dikirim balik.
//
// Alternatifnya adalah mengirim tautan lalu membiarkan sisi tampilan memanggil
// generate_qr?data=... — tapi itu menaruh tokennya di query string permintaan
// kedua, yang mendarat di log akses peladen. Siapa pun yang membaca log bisa
// merakit ulang QR-nya dan memindainya sendiri selama lima menit berikutnya.
//
// Dengan dirakit di sini, token hanya pernah ada sebagai piksel.
$qr = null;
if (function_exists('generateQrForDompdf')) {
    try {
        $qr = generateQrForDompdf($scanUrl, 220, 4);
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:qr] ' . $e->getMessage());
    }
}

if ($qr === null) {
    ezdoc_respond_error(
        t('response.qr_generator_unavailable', [], 'QR generator unavailable (generateQrForDompdf missing)'),
        500
    );
}

ezdoc_respond_success([
    // Dipegang sisi tampilan untuk memverifikasi kodenya nanti lewat
    // verify_ttd_otp — jalur yang sudah ada, tidak perlu yang baru.
    'challenge_id' => $issued['challenge_id'],
    'qr'           => $qr,
    'expires_at'   => $issued['expires_at'],
    'ttl_seconds'  => ScanOtpService::SCAN_TTL_SECONDS,
]);
