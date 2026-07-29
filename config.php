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

// ─── Debug ───
// Kalau sebuah action fatal, ezdoc_guard_fatal_as_json() tetap menjawab JSON
// (tanpa itu badannya kosong dan pemanggil melaporkan "Unexpected end of JSON
// input", yang tidak menyebutkan apa pun tentang penyebabnya). Pesan fatalnya
// selalu masuk error_log dengan awalan [ezdoc:fatal].
//
// Nyalakan ini untuk mengikutkan pesan itu ke dalam respons juga — berguna saat
// menelusuri, tapi pesan fatal bisa menyebut path file dan internal, jadi
// MATIKAN LAGI setelah selesai.
if (!defined('EZDOC_DEBUG')) define('EZDOC_DEBUG', false);

// ─── Feature Flags ───
// Set ke true untuk enforce RBAC di semua action. false = permissive (audit-log only, no reject).
// Bertahap: set false dulu, kumpulkan log, baru switch ke true.
if (!defined('EZDOC_ENFORCE_RBAC')) define('EZDOC_ENFORCE_RBAC', true);

// ─── Default akses untuk template tanpa access_config ───
//
// Menentukan jawaban ketika sebuah template tidak punya access_config sama
// sekali, atau punya tapi tidak menyebut action yang sedang diminta.
//
//   'permissive' — izinkan (perilaku yang berjalan sejak awal)
//   'strict'     — tolak, kecuali pemegang bypass role
//
// Kenapa ini ada: sebelumnya jawabannya tersirat di dua tempat yang berbeda
// pendapat. AccessConfig::empty() menolak, sementara ezdoc_can_on_template()
// mengizinkan. Semua file actions/ lewat yang kedua, jadi perilaku live adalah
// izinkan — tapi DocumentService memakai yang pertama, sehingga memakainya
// akan membalik jawabannya tanpa ada yang meminta. Sekarang keduanya membaca
// konstanta ini.
//
// Arah v1.0 adalah 'strict' (fail-safe defaults; OWASP ASVS V4.1.1, NIST
// AC-3). Default di sini tetap 'permissive' supaya instalasi yang sudah
// berjalan tidak berubah diam-diam. Sebelum membalik: nyalakan
// EZDOC_LOG_ACCESS_DEFAULT di bawah, kumpulkan datanya, lalu tuliskan
// access_config eksplisit ke template yang bergantung pada default ini.
if (!defined('EZDOC_ACCESS_DEFAULT')) define('EZDOC_ACCESS_DEFAULT', 'permissive');

// Catat setiap kali keputusan akses jatuh ke default di atas — yaitu setiap
// kali sebuah template akan terpengaruh kalau defaultnya dibalik ke 'strict'.
// Nyalakan untuk beberapa waktu sebelum pembalikan; entri masuk error_log
// dengan awalan [ezdoc:access-default].
//
// Mencatat saja — tidak satu pun keputusan otorisasi berubah karenanya. Di
// ezdoc_access_default_allows() jawabannya dihitung sebelum cabang logging dan
// dikembalikan sesudahnya; AccessControl::logDefaultDecision() sama. Dan hanya
// menyala saat tidak ada aturan eksplisit yang cocok, bukan tiap request —
// jadi log yang sepi berarti tidak ada yang bergantung pada default permisif.
//
// Dinyalakan 2026-07-29 untuk mengumpulkan data sebelum pembalikan ke 'strict'
// di v1.0. Matikan lagi setelah datanya cukup.
if (!defined('EZDOC_LOG_ACCESS_DEFAULT')) define('EZDOC_LOG_ACCESS_DEFAULT', true);

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
