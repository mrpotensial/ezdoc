<?php

declare(strict_types=1);

/**
 * Action: verify an OTP code and issue a single-use sign token.
 *
 * Endpoint: POST actions/document/verify_ttd_otp.php
 *
 * Params:
 *   - challenge_id : string (required)
 *   - otp_code     : string (required) 6 digits
 *
 * Every failure returns the same message. Distinguishing "no such challenge"
 * from "expired" from "wrong code" would tell a caller which challenge ids
 * exist and what state they are in.
 */

use Ezdoc\Signature\Otp\OtpService;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

// Guessing a code is a privileged operation — it consumes attempts against a
// challenge and, on success, mints the token that authorises a signature.
ezdoc_require_auth();

$challengeId = trim((string) ($_POST['challenge_id'] ?? ''));
$otpCode     = trim((string) ($_POST['otp_code'] ?? ''));

if ($challengeId === '' || $otpCode === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Missing required parameters'), 400);
}

$genericFailure = t('response.otp_invalid', [], 'Incorrect or expired code.');

try {
    $result = (new OtpService())->verify($challengeId, $otpCode);
} catch (\Throwable $e) {
    @error_log('[ezdoc:otp:verify] ' . $e->getMessage());
    ezdoc_respond_error($genericFailure, 400);
}

if (!$result->isSuccess()) {
    ezdoc_audit_log('otp.verify_failed', [
        'target_type' => 'document',
        'target_id'   => $challengeId,
        'result'      => 'failure',
        'metadata'    => [
            'challenge_id'  => $challengeId,
            'attempts_left' => $result->getAttemptsLeft(),
        ],
    ]);

    ezdoc_respond_error($genericFailure, 400, [
        'attempts_left' => $result->getAttemptsLeft(),
    ]);
}

ezdoc_audit_log('otp.verified', [
    'target_type' => 'document',
    'target_id'   => $challengeId,
    'result'      => 'success',
    'metadata'    => ['challenge_id' => $challengeId],
]);

ezdoc_respond_success([
    'sign_token' => $result->getSignToken(),
    'message'    => t('response.otp_verified', [], 'Verified.'),
]);
