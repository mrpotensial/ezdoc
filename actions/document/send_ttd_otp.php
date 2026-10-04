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
 *   - phone       : string (required) recipient contact — a phone number, or an
 *                          email address when `channel=email`. The key keeps
 *                          its old name on purpose: the `ezdoc/` tree and older
 *                          callers still post `phone`, and renaming it would
 *                          break them for no gain.
 *   - channel     : string (optional) `whatsapp` (default) or `email`
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

// ─── Channel ───
// Defaults to WhatsApp, which is what every caller before this parameter
// existed meant. An unrecognised value is refused rather than quietly folded
// into the default: sending to WhatsApp when the operator picked email leaves
// them watching an inbox that will never fill.
$channel = strtolower(trim((string) ($_POST['channel'] ?? 'whatsapp')));
if ($channel === '') {
    $channel = 'whatsapp';
}
if ($channel !== 'whatsapp' && $channel !== 'email') {
    ezdoc_respond_error(t('response.otp_channel_unknown', [], 'Unknown delivery channel.'), 400);
}

if ($templateId <= 0 || $ttdId === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Missing required parameters'), 400);
}

// ─── Recipient, validated per channel ───
// Done here, before anything is written, because OtpService records the
// challenge *before* dispatching so a failing provider still burns the
// cooldown. A recipient that was never going to work must therefore be
// refused before it can consume that 60-second window.
if ($channel === 'email') {
    if (!defined('EZDOC_OTP_EMAIL_ENABLED') || !EZDOC_OTP_EMAIL_ENABLED) {
        ezdoc_respond_error(
            t('response.otp_email_unavailable', [], 'The email channel is not configured on this server.'),
            503
        );
    }
    if ($phone === '') {
        ezdoc_respond_error(t('response.otp_email_required', [], 'A recipient email address is required.'), 400);
    }
    if (filter_var($phone, FILTER_VALIDATE_EMAIL) === false) {
        ezdoc_respond_error(t('response.otp_email_invalid', [], 'That email address is not valid.'), 400);
    }
    if (strlen($phone) > 254) {
        // RFC 5321. Refused here rather than left to the column: the server's
        // sql_mode is not strict, so the database would truncate it silently.
        ezdoc_respond_error(t('response.otp_email_too_long', [], 'That email address is too long.'), 400);
    }
} elseif ($phone === '') {
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

// ─── Recipient provenance ───
// The contact on file is the reference. Operators may send to a different one
// — a record with a stale or missing number is common — but the deviation is
// gated and recorded, so "verified by OTP" never silently means "verified
// against a contact the operator chose".
//
// Which field holds it depends on the channel. For phones the template says so
// via `data-otp-phone-source`. For email there is no such attribute on purpose:
// the modal writes the typed address back into an ordinary document field, so
// the convention below is where it will be found. That is also why the phone
// field is empty in 1.103 of 1.103 documents — nothing ever wrote it back.
$recordedContact = '';
if ($docId !== null && $docId > 0) {
    $sumber = ($channel === 'email')
        ? ['email_pasien', 'email']
        : ($ttdConfig['phone_source'] !== '' ? [$ttdConfig['phone_source']] : []);

    if (!empty($sumber)) {
        $docRow = $db->fetchOne(
            'SELECT `field_values` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
            [$docId]
        );
        if ($docRow && !empty($docRow['field_values'])) {
            $values = json_decode((string) $docRow['field_values'], true);
            if (is_array($values)) {
                foreach ($sumber as $kunci) {
                    $kandidat = trim((string) ($values[$kunci] ?? ''));
                    if ($kandidat !== '') {
                        $recordedContact = $kandidat;
                        break;
                    }
                }
            }
        }
    }
}

$normalizedSubmitted = OtpService::normalizeRecipient($phone);
$normalizedRecorded  = $recordedContact !== '' ? OtpService::normalizeRecipient($recordedContact) : '';

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
        $ttdConfig['ttl_seconds'],
        // The channel is the provider's instruction, not a hint: without it the
        // router would have to guess from the recipient's shape, and a mistyped
        // address with no "@" would go out over WhatsApp.
        //
        // `ttl_seconds` rides along so the email body can state how long the
        // code lives. The WhatsApp template has no slot for it and ignores it.
        [
            'channel'     => $channel,
            'ttl_seconds' => $ttdConfig['ttl_seconds'],
            'doc_label'   => $ttdConfig['label'],
        ]
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
        // Which channel carried the code. An audit that cannot tell a
        // WhatsApp send from an email send cannot tell how strongly the
        // signer's identity was established — the QR route proves possession
        // of a number, a typed address proves nothing.
        'channel'      => $channel,
        'masked_phone' => $result['masked_phone'],
        'expires_at'   => $result['expires_at'],
        // Only present when the operator redirected the code. Recording both
        // sides is the point: it shows the code did not go to the contact on
        // file, and to which contact it went instead.
        'overridden'   => $isOverride ? true : null,
        'on_file'      => $isOverride ? OtpService::maskRecipient($normalizedRecorded) : null,
    ], static function ($v) { return $v !== null; }),
]);

ezdoc_respond_success([
    'challenge_id' => $result['challenge_id'],
    'masked_phone' => $result['masked_phone'],
    'expires_at'   => $result['expires_at'],
    // Echoed back so the browser can word the next step for the right channel.
    // Without it the modal keeps saying "WhatsApp" after an email send.
    'channel'      => $channel,
    'message'      => t('response.otp_sent', ['phone' => $result['masked_phone']], 'A code has been sent to {phone}.'),
]);
