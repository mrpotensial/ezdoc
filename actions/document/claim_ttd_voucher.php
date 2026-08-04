<?php

declare(strict_types=1);

/**
 * Action: spend a patient-issued code and get a sign token.
 *
 * Endpoint: POST _doc_action=claim_ttd_voucher
 *
 * Params:
 *   - template_id : int    (required)
 *   - ttd_id      : string (required) signature slot id
 *   - code        : string (required) 6 digits, read out by the patient
 *   - doc_id      : int    (optional)
 *   - phone       : string (optional) only when the operator typed one
 *
 * ## Relationship to verify_ttd_otp.php
 *
 * Same destination, different starting point. There, the system sent a code to
 * a challenge it had already created, and the operator returns with the
 * challenge id. Here there is no challenge: the patient asked for a code before
 * anyone opened a document, and all we have is the number they used.
 *
 * Both end at a single-use sign token, minted the same way and spent by the
 * same code at save time — which is why `save_document.php` needs no knowledge
 * of either route.
 *
 * ## The phone rules are deliberately the same as sending
 *
 * `send_ttd_otp.php` refuses (or records) a code aimed at a number other than
 * the one on file, gated by EZDOC_OTP_ALLOW_PHONE_OVERRIDE and
 * EZDOC_OTP_OVERRIDE_ROLES. Those rules are repeated here on purpose. If they
 * were not, an operator barred from redirecting a code could simply switch to
 * this route and get the same result — a rule with a door next to it.
 */

use Ezdoc\Context;
use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Otp\OtpVoucherClaimResult;
use Ezdoc\Signature\Otp\OtpVoucherService;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

// Guessing a code spends attempts and, when right, mints the token that
// authorises a signature.
ezdoc_require_auth();

$templateId = (int) ($_POST['template_id'] ?? 0);
$ttdId      = trim((string) ($_POST['ttd_id'] ?? ''));
$code       = trim((string) ($_POST['code'] ?? $_POST['otp_code'] ?? ''));
$docId      = isset($_POST['doc_id']) && $_POST['doc_id'] !== '' ? (int) $_POST['doc_id'] : null;
$typedPhone = trim((string) ($_POST['phone'] ?? ''));

if ($templateId <= 0 || $ttdId === '' || $code === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Missing required parameters'), 400);
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

// ─── Which number the code belongs to ───

$recordedPhone = '';
if ($docId !== null && $docId > 0 && $ttdConfig['phone_source'] !== '') {
    $docRow = $db->fetchOne('SELECT `field_values` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1', [$docId]);
    if ($docRow && !empty($docRow['field_values'])) {
        $values = json_decode((string) $docRow['field_values'], true);
        if (is_array($values)) {
            $recordedPhone = trim((string) ($values[$ttdConfig['phone_source']] ?? ''));
        }
    }
}

$normalisedRecorded = $recordedPhone !== '' ? OtpService::normalizePhone($recordedPhone) : '';
$normalisedTyped    = $typedPhone !== '' ? OtpService::normalizePhone($typedPhone) : '';

$subject = $normalisedTyped !== '' ? $normalisedTyped : $normalisedRecorded;

if ($subject === '') {
    ezdoc_respond_error(t('response.otp_phone_required', [], 'A recipient phone number is required.'), 400);
}

$origin = ($normalisedRecorded !== '' && $subject === $normalisedRecorded) ? 'document' : 'manual';

// An override is a number that demonstrably contradicts the record. A document
// with no number yet has nothing to contradict — that is 'manual' and worth
// recording, but it is not an override.
$isOverride = $normalisedRecorded !== '' && $subject !== $normalisedRecorded;

if ($isOverride) {
    if (defined('EZDOC_OTP_ALLOW_PHONE_OVERRIDE') && !EZDOC_OTP_ALLOW_PHONE_OVERRIDE) {
        ezdoc_respond_error(
            t('response.otp_phone_override_denied', [], 'The code can only be sent to the number on file.'),
            403
        );
    }
    if (defined('EZDOC_OTP_OVERRIDE_ROLES') && EZDOC_OTP_OVERRIDE_ROLES !== '') {
        $overrideRoles = array_filter(array_map('trim', explode(',', (string) EZDOC_OTP_OVERRIDE_ROLES)));
        if (!empty($overrideRoles) && !ezdoc_has_role($overrideRoles)) {
            ezdoc_respond_error(
                t('response.otp_phone_override_denied', [], 'The code can only be sent to the number on file.'),
                403
            );
        }
    }
}

// ─── Spend it ───

try {
    $result = (new OtpVoucherService())->claim($subject, $code, [
        'template_id'  => $templateId,
        'ttd_id'       => $ttdId,
        'doc_id'       => $docId,
        'phone_origin' => $origin,
    ]);
} catch (\Throwable $e) {
    @error_log('[ezdoc:voucher:claim] ' . $e->getMessage());
    ezdoc_respond_error(t('response.otp_invalid', [], 'Incorrect or expired code.'), 400);
}

if (!$result->isSuccess()) {
    ezdoc_audit_log('otp.voucher_claim_failed', [
        'target_type' => 'document',
        'target_id'   => (string) ($docId ?? $templateId),
        'doc_id'      => $docId,
        'result'      => 'failure',
        'metadata'    => [
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'reason'        => $result->getReason(),
            'masked_phone'  => OtpService::maskPhone($subject),
            'attempts_left' => $result->getAttemptsLeft(),
        ],
    ]);

    // Distinct wording per outcome. Not a leak here: this caller already passed
    // the same access check as the status endpoint, which answers the same
    // question more directly. One generic sentence would only stop the operator
    // being told to ask for a fresh code instead of retrying a dead one.
    $wording = [
        OtpVoucherClaimResult::EXPIRED => ['response.otp_voucher_expired', 'That code has expired. Ask the patient to request a new one.'],
        OtpVoucherClaimResult::LOCKED  => ['response.otp_voucher_locked', 'Too many wrong attempts. Ask the patient to request a new code.'],
        OtpVoucherClaimResult::NONE    => ['response.otp_voucher_none', 'This number has not requested a code yet.'],
    ];
    [$key, $fallback] = $wording[$result->getReason()]
        ?? ['response.otp_invalid', 'Incorrect or expired code.'];

    ezdoc_respond_error(t($key, [], $fallback), 400, [
        'reason'        => $result->getReason(),
        'attempts_left' => $result->getAttemptsLeft(),
    ]);
}

ezdoc_audit_log($isOverride ? 'otp.voucher_claimed_override' : 'otp.voucher_claimed', [
    'target_type' => 'document',
    'target_id'   => (string) ($docId ?? $templateId),
    'doc_id'      => $docId,
    'metadata'    => array_filter([
        'template_id'  => $templateId,
        'ttd_id'       => $ttdId,
        'voucher_id'   => $result->getVoucherId(),
        'masked_phone' => OtpService::maskPhone($subject),
        // Recorded on every claim, not only overrides: "where did this number
        // come from" is the question a dispute actually asks.
        'phone_origin' => $origin,
        'on_file'      => $isOverride ? OtpService::maskPhone($normalisedRecorded) : null,
    ], static function ($v) { return $v !== null; }),
]);

ezdoc_respond_success([
    'sign_token' => $result->getSignToken(),
    'message'    => t('response.otp_verified', [], 'Verified.'),
]);
