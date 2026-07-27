<?php
/**
 * ezdoc module config — constants & feature flags.
 * Load-once via bootstrap.php.
 */

// Prevent double-load
if (defined('EZDOC_CONFIG_LOADED')) return;
define('EZDOC_CONFIG_LOADED', true);

// ─── Version ───
if (!defined('EZDOC_VERSION')) define('EZDOC_VERSION', '1.0.0-phaseA');

// ─── Feature Flags ───
// Set ke true untuk enforce RBAC di semua action. false = permissive (audit-log only, no reject).
// Bertahap: set false dulu, kumpulkan log, baru switch ke true.
if (!defined('EZDOC_ENFORCE_RBAC')) define('EZDOC_ENFORCE_RBAC', true);

// ─── Template Management RBAC ───
// Roles yang boleh SAVE/EDIT/DELETE/LOCK/COPY template (design-time actions).
// Bukan per-template access_config — ini global role check.
// Set empty ([]) untuk backward compat (allow all — v2 behavior sebelum extract).
// Set ke ['superadmin'] untuk lock ke superadmin only (recommended production).
if (!defined('EZDOC_TEMPLATE_MANAGER_ROLES')) {
    define('EZDOC_TEMPLATE_MANAGER_ROLES', json_encode([]));
    // Contoh production: define('EZDOC_TEMPLATE_MANAGER_ROLES', json_encode(['superadmin', 'template_designer']));
}

// ─── OTP Provider ───
// ─── OTP Provider (Universal Configuration) ───
// Ezdoc menggunakan arsitektur berbasis konfigurasi (Config-Driven Architecture).
// Consumer aplikasi WAJIB mendefinisikan konstanta konfigurasi di aplikasi mereka 
// SEBELUM memuat `ezdoc/bootstrap.php`.
//
// Contoh Konfigurasi untuk WebhookOtpProvider:
// define('EZDOC_OTP_PROVIDER_CLASS', \Ezdoc\Signature\Otp\Providers\WebhookOtpProvider::class);
// define('EZDOC_OTP_WEBHOOK_URL', 'https://api.vendor.com/send');
// define('EZDOC_OTP_WEBHOOK_PAYLOAD', json_encode(['to' => '{phone}', 'msg' => '{code}']));
// define('EZDOC_OTP_WEBHOOK_METHOD', 'POST');
// define('EZDOC_OTP_WEBHOOK_HEADERS', json_encode(['Content-Type: application/json']));

if (!defined('EZDOC_OTP_PROVIDER_CLASS')) {
    define('EZDOC_OTP_PROVIDER_CLASS', '');
}

if (!defined('EZDOC_OTP_WEBHOOK_URL')) {
    define('EZDOC_OTP_WEBHOOK_URL', '');
}

if (!defined('EZDOC_OTP_WEBHOOK_PAYLOAD')) {
    define('EZDOC_OTP_WEBHOOK_PAYLOAD', '{}');
}

if (!defined('EZDOC_OTP_WEBHOOK_METHOD')) {
    define('EZDOC_OTP_WEBHOOK_METHOD', 'POST');
}

if (!defined('EZDOC_OTP_WEBHOOK_HEADERS')) {
    define('EZDOC_OTP_WEBHOOK_HEADERS', '[]');
}

// ─── OTP delivery policy ───

// Country code prepended to national-format numbers (E.164, no "+").
// Numbers already in international form are left alone. Set to '' to accept
// only E.164 input and disable national-form handling entirely.
if (!defined('EZDOC_OTP_COUNTRY_CODE')) {
    define('EZDOC_OTP_COUNTRY_CODE', '62');
}

// May an operator send the code to a number other than the one recorded on
// the document? A stale or missing number on file is common, so this defaults
// to allowed — every deviation is written to the audit log as
// `otp.sent_override`, carrying both the number on file and the one used.
//
// Set to false to require the code to go to the recorded number.
if (!defined('EZDOC_OTP_ALLOW_PHONE_OVERRIDE')) {
    define('EZDOC_OTP_ALLOW_PHONE_OVERRIDE', true);
}

// Optional narrower gate on that override: comma-separated role names.
// Empty means any user who can already edit the document may redirect a code.
if (!defined('EZDOC_OTP_OVERRIDE_ROLES')) {
    define('EZDOC_OTP_OVERRIDE_ROLES', '');
}

// ─── Path constants ───
if (!defined('EZDOC_ROOT')) define('EZDOC_ROOT', __DIR__);
if (!defined('EZDOC_LIB'))  define('EZDOC_LIB', __DIR__ . '/lib');
if (!defined('EZDOC_ACTIONS')) define('EZDOC_ACTIONS', __DIR__ . '/actions');
