<?php

declare(strict_types=1);

/**
 * Action: Send TTD OTP code to patient phone.
 *
 * Endpoint: POST actions/document/send_ttd_otp.php
 * Params:
 *   - template_id: int
 *   - ttd_id: string
 *   - phone: string (patient phone, adjustable/customizable)
 *   - nama_pasien: string (optional)
 *   - doc_id: int (optional)
 *   - ttl_seconds: int (optional, default 300)
 */

use Ezdoc\Signature\Otp\OtpService;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

$templateId = (int) ($_POST['template_id'] ?? 0);
$ttdId      = trim((string) ($_POST['ttd_id'] ?? ''));
$phone      = trim((string) ($_POST['phone'] ?? ''));
$name       = trim((string) ($_POST['nama_pasien'] ?? $_POST['name'] ?? 'Pasien'));
$docId      = isset($_POST['doc_id']) && $_POST['doc_id'] !== '' ? (int) $_POST['doc_id'] : null;
$ttlSeconds = (int) ($_POST['ttl_seconds'] ?? 300);

if ($templateId <= 0 || $ttdId === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Parameter template_id dan ttd_id wajib diisi.'), 400);
}

if ($phone === '') {
    ezdoc_respond_error(t('otp.phone_required', [], 'Nomor telepon WhatsApp pasien wajib diisi.'), 400);
}

try {
    $otpService = new OtpService();
    $result = $otpService->createChallenge(
        $templateId,
        $ttdId,
        $phone,
        $name,
        $docId,
        $ttlSeconds
    );

    ezdoc_respond_success([
        'challenge_id' => $result['challenge_id'],
        'masked_phone' => $result['masked_phone'],
        'expires_at'   => $result['expires_at'],
        'message'      => t('otp.send_success', [], 'Kode OTP berhasil dikirim ke WhatsApp pasien.'),
    ]);
} catch (\Throwable $e) {
    ezdoc_respond_error($e->getMessage(), 400);
}
