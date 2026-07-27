<?php

declare(strict_types=1);

/**
 * Action: Verify patient-entered OTP code.
 *
 * Endpoint: POST actions/document/verify_ttd_otp.php
 * Params:
 *   - challenge_id: string
 *   - otp_code: string (6 digits)
 */

use Ezdoc\Signature\Otp\OtpService;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

$challengeId = trim((string) ($_POST['challenge_id'] ?? ''));
$otpCode     = trim((string) ($_POST['otp_code'] ?? ''));

if ($challengeId === '' || $otpCode === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Parameter challenge_id dan otp_code wajib diisi.'), 400);
}

try {
    $otpService = new OtpService();
    $result = $otpService->verify($challengeId, $otpCode);

    if (!$result->isSuccess()) {
        ezdoc_respond_error($result->getMessage(), 400, [
            'attempts_left' => $result->getAttemptsLeft(),
        ]);
    }

    ezdoc_respond_success([
        'sign_token' => $result->getSignToken(),
        'message'    => t('otp.verify_success', [], 'Verifikasi Kode OTP berhasil.'),
    ]);
} catch (\Throwable $e) {
    ezdoc_respond_error($e->getMessage(), 400);
}
