<?php

declare(strict_types=1);

/**
 * Action: does this number currently hold a usable code?
 *
 * Endpoint: POST _doc_action=otp_voucher_status
 *
 * Params:
 *   - template_id : int    (required)
 *   - ttd_id      : string (required) signature slot id
 *   - doc_id      : int    (optional) needed to read the number on file
 *   - phone       : string (optional) only when the operator typed one
 *
 * ## Why this exists
 *
 * On the patient-initiated route the operator types a code the patient read out
 * loud. When it keeps failing there are two possible causes and the screen
 * shows neither: the code is wrong, or the number being checked is not the one
 * the patient messaged from. Without this hint every mistyped digit looks like
 * the patient's fault.
 *
 * ## Why it is also a small oracle, and what holds it
 *
 * "Does this number have a pending code" is, in bulk, a way to learn which
 * numbers are mid-signature. Three things bound it:
 *
 *   1. The same access check as signing the slot — not merely being logged in.
 *   2. The number normally comes from the document, so there is nothing to
 *      probe with. Only an explicitly supplied number can be chosen freely.
 *   3. Those supplied lookups are counted per operator per minute, and every
 *      one is recorded. An operator sweeping numbers is visible afterwards and
 *      slow while doing it.
 *
 * The response never contains the code, nor any part of it.
 */

use Ezdoc\Context;
use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Otp\OtpVoucherService;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * Free-typed lookups allowed per operator per minute.
 *
 * Far above what reading one number off a screen needs, far below what makes
 * sweeping a number range worthwhile. The audit trail, not this ceiling, is
 * what makes the behaviour accountable.
 */
const EZDOC_VOUCHER_LOOKUP_PER_MINUTE = 10;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

ezdoc_require_auth();

$templateId = (int) ($_POST['template_id'] ?? 0);
$ttdId      = trim((string) ($_POST['ttd_id'] ?? ''));
$docId      = isset($_POST['doc_id']) && $_POST['doc_id'] !== '' ? (int) $_POST['doc_id'] : null;
$typedPhone = trim((string) ($_POST['phone'] ?? ''));

if ($templateId <= 0 || $ttdId === '') {
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

// Asking about a slot this user could never sign has no legitimate purpose, and
// is exactly the shape a sweep would take.
if (!ezdoc_can_sign_ttd($ttdConfig['rbac'])) {
    ezdoc_respond_error(t('response.forbidden', [], 'Not permitted'), 403);
}

// ─── Which number are we asking about ───

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
    // Nothing on file and nothing supplied. Not an error — the dialog opens
    // before a number is known, and it needs an answer it can render.
    ezdoc_respond_success([
        'state'         => 'none',
        'seconds_left'  => 0,
        'attempts_left' => 0,
        'phone_origin'  => 'none',
        'masked_phone'  => '',
    ]);
}

// 'document' only when the number demonstrably came from the record. A typed
// number that happens to match still counts as coming from the document —
// nothing was overridden, so there is nothing to hold anyone to.
$origin = ($normalisedRecorded !== '' && $subject === $normalisedRecorded) ? 'document' : 'manual';

// ─── Gate on free-typed lookups ───

if ($origin === 'manual') {
    $since = date('Y-m-d H:i:s', time() - 60);
    $recent = (int) $db->fetchScalar(
        'SELECT COUNT(*) FROM `ezdoc_audit_log`
          WHERE `event_type` = ? AND `actor_id` = ? AND `occurred_at` > ?',
        ['otp.voucher_lookup_manual', ezdoc_current_user_id(), $since]
    );

    if ($recent >= EZDOC_VOUCHER_LOOKUP_PER_MINUTE) {
        ezdoc_audit_denied('otp.voucher_lookup_manual', 'rate_limited', [
            'target_type' => 'document',
            'target_id'   => (string) ($docId ?? $templateId),
            'doc_id'      => $docId,
        ]);

        ezdoc_respond_error(
            t('response.otp_lookup_rate_limited', [], 'Too many lookups. Please wait a moment.'),
            429
        );
    }

    // Recorded before the answer is given, so a sweep is visible even if the
    // response never reaches the client.
    ezdoc_audit_log('otp.voucher_lookup_manual', [
        'target_type' => 'document',
        'target_id'   => (string) ($docId ?? $templateId),
        'doc_id'      => $docId,
        'metadata'    => array_filter([
            'template_id'  => $templateId,
            'ttd_id'       => $ttdId,
            'masked_phone' => OtpService::maskPhone($subject),
            'on_file'      => $normalisedRecorded !== '' ? OtpService::maskPhone($normalisedRecorded) : null,
        ], static function ($v) { return $v !== null; }),
    ]);
}

try {
    $status = (new OtpVoucherService())->status($subject);
} catch (\Throwable $e) {
    @error_log('[ezdoc:voucher:status] ' . $e->getMessage());
    ezdoc_respond_error(t('response.otp_lookup_failed', [], 'Could not check the code.'), 500);
}

ezdoc_respond_success($status->toArray() + [
    'phone_origin' => $origin,
    'masked_phone' => OtpService::maskPhone($subject),
]);
