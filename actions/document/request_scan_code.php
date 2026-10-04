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
 *   - doc_id      : int    (opsional untuk qr dan email, wajib untuk app)
 *   - delivery    : qr | app | email (bawaan qr)
 *   - email       : string (wajib untuk email) alamat penanda tangan
 *
 * `delivery=email` melahirkan kodenya SEKARANG dan menyerahkannya ke
 * `EZDOC_SCAN_EMAIL_DELIVERY` milik aplikasi pemakai, yang mengirim surat dan
 * boleh juga menampilkannya di aplikasinya — lihat
 * {@see ScanOtpService::issueToRecipient()}. Surat yang gagal terkirim
 * mematikan kodenya. Barisnya lahir berstatus "sudah dipindai", jadi layar
 * petugas langsung beralih ke isian kode lewat scan_code_status yang sama.
 *
 * `delivery=app` tidak menggambar QR. Ia menerbitkan token berumur
 * {@see ScanOtpService::APP_TTL_SECONDS} lalu meminta aplikasi pemakai
 * mengabari penanda tangannya lewat `EZDOC_SCAN_APP_DELIVERY` — sebuah callable
 * yang menerima keterangan dokumen dan subjeknya, TANPA token. Aplikasi pemakai
 * mengambil tokennya sendiri lewat `ezdoc_scan_pending()` sesudah memastikan
 * yang bertanya memang tertaut ke subjek itu.
 *
 * Lihat docs/260803-otp-jalur-kedua.md §8h.
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

$delivery = strtolower(trim((string) ($_POST['delivery'] ?? 'qr')));
if (!in_array($delivery, ['qr', 'app', 'email'], true)) {
    ezdoc_respond_error(t('response.scan_delivery_unknown', [], 'Unknown delivery.'), 400);
}

$emailDeliverer = defined('EZDOC_SCAN_EMAIL_DELIVERY') ? EZDOC_SCAN_EMAIL_DELIVERY : '';
$email = OtpService::normalizeRecipient((string) ($_POST['email'] ?? ''));
if ($delivery === 'email') {
    if ($emailDeliverer === '' || !is_callable($emailDeliverer)) {
        ezdoc_respond_error(
            t('response.scan_email_not_configured', [], 'Sending the code by email is not configured for this installation.'),
            503
        );
    }
    if (!OtpService::isEmail($email)) {
        ezdoc_respond_error(t('response.scan_email_invalid', [], 'Invalid email address.'), 400);
    }
}

$deliverer = defined('EZDOC_SCAN_APP_DELIVERY') ? EZDOC_SCAN_APP_DELIVERY : '';
if ($delivery === 'app') {
    if ($deliverer === '' || !is_callable($deliverer)) {
        ezdoc_respond_error(
            t('response.scan_app_not_configured', [], 'Sending to the signer app is not configured for this installation.'),
            503
        );
    }
    // Permintaan lewat aplikasi menemukan penanda tangannya dari subjek
    // dokumen. Dokumen yang belum disimpan belum punya subjek.
    if ($docId === null || $docId <= 0) {
        ezdoc_respond_error(t('response.scan_app_needs_saved', [], 'Save the document first.'), 400);
    }
}

$urlTemplate = defined('EZDOC_SCAN_URL_TEMPLATE') ? (string) EZDOC_SCAN_URL_TEMPLATE : '';
if ($delivery === 'qr' && ($urlTemplate === '' || strpos($urlTemplate, '{token}') === false)) {
    // Dinyatakan, bukan gagal diam-diam. Petugas yang menekan tombolnya berhak
    // tahu bedanya "sedang rusak" dan "belum dipasang".
    ezdoc_respond_error(
        t('response.scan_not_configured', [], 'Scan-to-sign is not configured for this installation.'),
        503
    );
}

$db = Context::default()->connection();

$template = $db->fetchOne(
    'SELECT `name`, `content`, `access_config` FROM `ezdoc_templates` WHERE `id` = ? LIMIT 1',
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

if ($delivery === 'email') {
    $repo = new \Ezdoc\Signature\Otp\OtpChallengeRepository($db);
    $service = new ScanOtpService($repo);

    try {
        $issued = $service->issueToRecipient($templateId, $ttdId, $docId, $email);
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:request:email] ' . $e->getMessage());
        ezdoc_respond_error(t('response.scan_request_failed', [], 'Could not create the code request.'), 500);
    }

    $identity = ezdoc_scan_identity([
        'template_id' => $templateId,
        'ttd_id'      => $ttdId,
        'doc_id'      => $docId,
    ]);

    // Penyusun dan pengirim suratnya milik aplikasi pemakai — kata-kata
    // persetujuan adalah keputusannya, bukan keputusan pustaka. Ia menerima
    // kodenya (untuk isi surat) dan tokennya (untuk tautan ke aplikasinya), dan
    // mengembalikan kalimat persetujuan yang BENAR-BENAR terkirim.
    $sent = ['ok' => false, 'message' => '', 'consent_message' => '', 'reached' => 0];
    try {
        $hasil = call_user_func($emailDeliverer, [
            'challenge_id'  => $issued['challenge_id'],
            'scan_token'    => $issued['scan_token'],
            'code'          => $issued['code'],
            'recipient'     => $email,
            'doc_id'        => $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'ttd_label'     => (string) ($identity['ttd_label'] ?? $ttdConfig['label'] ?? ''),
            'document_name' => (string) ($identity['document_name'] ?? $template['name'] ?? ''),
            'signer_name'   => (string) ($identity['signer_name'] ?? ''),
            'context_id'    => (string) ($identity['context_id'] ?? ''),
            'label'         => (string) ($identity['label'] ?? ''),
            'created_at'    => (string) ($identity['created_at'] ?? ''),
            'expires_at'    => $issued['expires_at'],
            'ttl_seconds'   => ScanOtpService::EMAIL_TTL_SECONDS,
        ]);
        if (is_array($hasil)) {
            $sent = [
                'ok'              => !empty($hasil['ok']),
                'message'         => (string) ($hasil['message'] ?? ''),
                'consent_message' => (string) ($hasil['consent_message'] ?? ''),
                'reached'         => (int) ($hasil['reached'] ?? 0),
            ];
        }
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:request:email:deliver] ' . $e->getMessage());
    }

    if (!$sent['ok']) {
        // Kode yang tidak pernah sampai tidak boleh bisa diverifikasi — pola
        // yang sama dengan OtpService::createChallenge().
        $repo->expire($issued['challenge_id']);
        ezdoc_respond_error(
            $sent['message'] !== ''
                ? $sent['message']
                : t('response.scan_email_failed', [], 'The email was not sent. Check the address, or use the QR code.'),
            502
        );
    }

    if ($sent['consent_message'] !== '') {
        $service->recordConsent($issued['challenge_id'], $sent['consent_message']);
    }

    // Kode lama untuk slot yang sama baru dimatikan SEKARANG, sesudah kode baru
    // terbukti terkirim: dua kode hidup untuk satu tanda tangan terbaca sebagai
    // dua permintaan, tapi alamat yang salah ketik tidak boleh mematikan kode
    // sah yang sedang dipegang pasien. Dokumen yang belum disimpan tidak punya
    // slot untuk dicari.
    $retired = $docId !== null && $docId > 0
        ? $repo->expirePendingForSlot((int) $docId, $ttdId, ScanOtpService::ROUTE_EMAIL, $issued['challenge_id'])
        : 0;

    $masked = OtpService::maskRecipient($email);

    ezdoc_audit_log('otp.email_requested', [
        'target_type' => 'document',
        'target_id'   => (string) ($docId ?? $templateId),
        'doc_id'      => $docId,
        'metadata'    => [
            'template_id'      => $templateId,
            'ttd_id'           => $ttdId,
            'challenge_id'     => $issued['challenge_id'],
            'expires_at'       => $issued['expires_at'],
            'masked_recipient' => $masked,
            'retired'          => $retired,
            'reached'          => $sent['reached'],
        ],
    ]);

    // Kodenya TIDAK ikut. Petugas mendapatkannya dari mulut penanda tangan.
    ezdoc_respond_success([
        'challenge_id'     => $issued['challenge_id'],
        'expires_at'       => $issued['expires_at'],
        'ttl_seconds'      => ScanOtpService::EMAIL_TTL_SECONDS,
        'delivery'         => 'email',
        'masked_recipient' => $masked,
        'reached'          => $sent['reached'],
        'message'          => $sent['message'],
    ]);
}

if ($delivery === 'app') {
    $doc = $db->fetchOne(
        'SELECT `subject_type`, `subject_id`, `context_id`
           FROM `ezdoc_documents` WHERE `id` = ? AND `template_id` = ? AND `deleted_at` IS NULL LIMIT 1',
        [$docId, $templateId]
    );
    if (!$doc || (string) ($doc['subject_id'] ?? '') === '') {
        ezdoc_respond_error(t('response.document_not_found', [], 'Document not found'), 404);
    }

    try {
        // Permintaan lama untuk slot yang sama dimatikan dulu: dua permintaan
        // hidup di aplikasi untuk satu tanda tangan terbaca sebagai dua hal.
        $repo = new \Ezdoc\Signature\Otp\OtpChallengeRepository($db);
        $retired = $repo->expirePendingForSlot((int) $docId, $ttdId, ScanOtpService::ROUTE_APP);
        $issued = (new ScanOtpService($repo))->issue(
            $templateId,
            $ttdId,
            $docId,
            ScanOtpService::APP_TTL_SECONDS,
            ['route' => ScanOtpService::ROUTE_APP]
        );
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:request:app] ' . $e->getMessage());
        ezdoc_respond_error(t('response.scan_request_failed', [], 'Could not create the code request.'), 500);
    }

    // Pengirimnya milik aplikasi pemakai. Gagalnya TIDAK menggagalkan
    // permintaan: ia tetap muncul saat penanda tangan membuka aplikasinya, dan
    // petugas berhak tahu bahwa kabarnya tidak sampai — bukan bahwa semuanya batal.
    $sent = ['ok' => false, 'reached' => 0, 'message' => ''];
    try {
        $hasil = call_user_func($deliverer, [
            'challenge_id'  => $issued['challenge_id'],
            'doc_id'        => (int) $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'ttd_label'     => (string) ($ttdConfig['label'] ?? ''),
            'document_name' => (string) ($template['name'] ?? ''),
            'subject_type'  => (string) ($doc['subject_type'] ?? ''),
            'subject_id'    => (string) $doc['subject_id'],
            'context_id'    => (string) ($doc['context_id'] ?? ''),
            'expires_at'    => $issued['expires_at'],
        ]);
        if (is_array($hasil)) {
            $sent = [
                'ok'      => !empty($hasil['ok']),
                'reached' => (int) ($hasil['reached'] ?? 0),
                'message' => (string) ($hasil['message'] ?? ''),
            ];
        }
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:request:app:deliver] ' . $e->getMessage());
    }

    ezdoc_audit_log('otp.app_requested', [
        'target_type' => 'document',
        'target_id'   => (string) $docId,
        'doc_id'      => $docId,
        'metadata'    => [
            'template_id'  => $templateId,
            'ttd_id'       => $ttdId,
            'challenge_id' => $issued['challenge_id'],
            'expires_at'   => $issued['expires_at'],
            'retired'      => $retired,
            'reached'      => $sent['reached'],
        ],
    ]);

    ezdoc_respond_success([
        'challenge_id' => $issued['challenge_id'],
        'expires_at'   => $issued['expires_at'],
        'ttl_seconds'  => ScanOtpService::APP_TTL_SECONDS,
        'delivery'     => 'app',
        'reached'      => $sent['reached'],
        'message'      => $sent['message'],
    ]);
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
// Satu pintu, lihat ezdoc_qr_data_uri(): kontrak QrRenderer dulu, jalur usang
// generateQrForDompdf() menyusul. Ia tidak pernah melempar.
$qr = ezdoc_qr_data_uri($scanUrl, 220, 4);

if ($qr === '') {
    ezdoc_respond_error(
        t('response.qr_generator_unavailable', [], 'No QR generator is configured'),
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
