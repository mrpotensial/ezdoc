<?php

declare(strict_types=1);

/**
 * Action: send an OTP code to the signer's phone.
 *
 * Endpoint: POST actions/document/send_ttd_otp.php
 *
 * Params:
 *   - template_id : int    (required)
 *   - ttd_id      : string (required) signature slot id
 *   - phone       : string (required) recipient number
 *   - name        : string (optional) recipient display name
 *   - doc_id      : int    (optional)
 *
 * Lifetime is NOT accepted from the request — it is read from the template's
 * own `data-otp-ttl` and clamped. A client-supplied TTL would let a caller
 * mint a code that never expires.
 */

use Ezdoc\Context;
use Ezdoc\Signature\Otp\OtpService;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

// ─── Guards ───
// This endpoint spends real money (each call sends a message) and mints the
// token that authorises a signature, so it needs the same protection as any
// other mutating action.
ezdoc_require_auth();

$templateId = (int) ($_POST['template_id'] ?? 0);
$ttdId      = trim((string) ($_POST['ttd_id'] ?? ''));
$phone      = trim((string) ($_POST['phone'] ?? ''));
$name       = trim((string) ($_POST['name'] ?? $_POST['nama_pasien'] ?? ''));
$docId      = isset($_POST['doc_id']) && $_POST['doc_id'] !== '' ? (int) $_POST['doc_id'] : null;

if ($templateId <= 0 || $ttdId === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Missing required parameters'), 400);
}
if ($phone === '') {
    ezdoc_respond_error(t('response.otp_phone_required', [], 'A recipient phone number is required.'), 400);
}

$db = Context::default()->connection();

$template = $db->fetchOne(
    'SELECT `content`, `access_config` FROM `ezdoc_templates` WHERE `id` = ? LIMIT 1',
    [$templateId]
);
if (!$template) {
    ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'), 404);
}

// Whoever may edit documents from this template may also request a code.
ezdoc_require_on_template(
    ezdoc_parse_access_config($template['access_config'] ?? null),
    'edit'
);

// Slot config is the authority on whether OTP applies and how long a code
// lives — never the request.
$ttdConfig = OtpService::findTtdConfig((string) ($template['content'] ?? ''), $ttdId);

if (!$ttdConfig['found']) {
    ezdoc_respond_error(t('response.otp_slot_not_found', [], 'Signature slot not found in the template.'), 404);
}
if (!$ttdConfig['require_otp']) {
    ezdoc_respond_error(t('response.otp_not_required', [], 'This signature slot does not require OTP verification.'), 400);
}

// Per-slot signing rights: no point issuing a token to someone who cannot
// sign this slot anyway.
if (!ezdoc_can_sign_ttd($ttdConfig['rbac'])) {
    ezdoc_respond_error(t('response.forbidden', [], 'Not permitted'), 403);
}

// ─── Phone provenance ───
// The number on file is the reference. Operators may send to a different one
// — a record with a stale or missing number is common — but the deviation is
// gated and recorded, so "verified by OTP" never silently means "verified
// against a number the operator chose".
$recordedPhone = '';
if ($docId !== null && $docId > 0 && $ttdConfig['phone_source'] !== '') {
    $docRow = $db->fetchOne(
        'SELECT `field_values` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
        [$docId]
    );
    if ($docRow && !empty($docRow['field_values'])) {
        $values = json_decode((string) $docRow['field_values'], true);
        if (is_array($values)) {
            $recordedPhone = trim((string) ($values[$ttdConfig['phone_source']] ?? ''));
        }
    }
}

$normalizedSubmitted = OtpService::normalizePhone($phone);
$normalizedRecorded  = $recordedPhone !== '' ? OtpService::normalizePhone($recordedPhone) : '';

// An override is a send to a number that demonstrably differs from the one on
// file. When there is nothing on file yet — an unsaved document, or a slot
// with no phone_source — there is nothing to contradict, so it is not one.
$isOverride = $normalizedRecorded !== '' && $normalizedRecorded !== $normalizedSubmitted;

if ($isOverride) {
    if (defined('EZDOC_OTP_ALLOW_PHONE_OVERRIDE') && !EZDOC_OTP_ALLOW_PHONE_OVERRIDE) {
        ezdoc_respond_error(
            t('response.otp_phone_override_denied', [], 'The code can only be sent to the number on file.'),
            403
        );
    }
    // Optional narrower gate: set EZDOC_OTP_OVERRIDE_ROLES to restrict who may
    // redirect a code. Left unset, any user who can already edit the document
    // may do so — the audit entry below is what makes that accountable.
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

try {
    $result = (new OtpService())->createChallenge(
        $templateId,
        $ttdId,
        $phone,
        $name,
        $docId,
        $ttdConfig['ttl_seconds']
    );
} catch (\Throwable $e) {
    // Cooldown and validation messages are safe to surface; anything else is
    // logged and reported generically.
    @error_log('[ezdoc:otp:send] ' . $e->getMessage());
    ezdoc_respond_error(
        $e instanceof \Ezdoc\Exceptions\EzdocException
            ? $e->getMessage()
            : t('response.otp_send_failed', [], 'Could not send the code. Please try again.'),
        400
    );
}

ezdoc_audit_log($isOverride ? 'otp.sent_override' : 'otp.sent', [
    'target_type' => 'document',
    'target_id'   => (string) ($docId ?? $templateId),
    'doc_id'      => $docId,
    'metadata'    => array_filter([
        'template_id'  => $templateId,
        'ttd_id'       => $ttdId,
        'masked_phone' => $result['masked_phone'],
        'expires_at'   => $result['expires_at'],
        // Only present when the operator redirected the code. Recording both
        // sides is the point: it shows the code did not go to the number on
        // file, and to which number it went instead.
        'overridden'   => $isOverride ? true : null,
        'on_file'      => $isOverride ? OtpService::maskPhone($normalizedRecorded) : null,
    ], static function ($v) { return $v !== null; }),
]);

ezdoc_respond_success([
    'challenge_id' => $result['challenge_id'],
    'masked_phone' => $result['masked_phone'],
    'expires_at'   => $result['expires_at'],
    'message'      => t('response.otp_sent', ['phone' => $result['masked_phone']], 'A code has been sent to {phone}.'),
]);
