<?php
/**
 * ezdoc action dispatcher.
 *
 * Dipanggil dari main page (consumer's dispatch entry point, mis. designer.php,
 * generate.php, list.php) di TOP setelah bootstrap. Kalau match ke suatu action
 * → require handler → exit. Kalau tidak match → return biar page lanjut rendering
 * normal.
 *
 * ## Runtime dependencies (library-standalone since v0.9.10)
 * - `Ezdoc\Context::default()` — DI container providing db (mysqli), roleProvider,
 *   pdf renderer. Auto-init via `Context::fromGlobals()` fallback yg detect
 *   consumer bootstrap globals ($conn, $author_id) atau di-inject explicit
 *   oleh consumer.
 * - `ezdoc/bootstrap.php` — helper functions (`ezdoc_respond_success`,
 *   `ezdoc_respond_error`, `ezdoc_require_*`, `resolveDefault`, dll).
 *
 * Action handlers TIDAK boleh call consumer-specific globals langsung (`hasRole`,
 * `$author_id`, `query()`). Semua akses via `Context` atau ezdoc helper wrappers
 * yang route through abstraction (RoleProvider, Connection, PdfRenderer).
 *
 * Routing map:
 *   ─ Document actions ─
 *   GET  ?action=generate_qr                → document/generate_qr.php
 *   POST _ajax=1                            → document/save_document.php
 *   POST _doc_action=toggle_doc_lock        → document/toggle_lock.php
 *   POST _doc_action=list_versions          → document/list_versions.php
 *   POST _doc_action=new_version            → document/new_version.php
 *   POST _doc_action=delete_version         → document/delete_version.php
 *   POST _doc_action=delete_slot            → document/delete_slot.php
 *   POST _doc_action=restore_slot           → document/restore_slot.php
 *
 *   ─ Template actions (extracted from designer) ─
 *   POST ajax=1 action=save                 → template/save_template.php
 *   POST ajax=1 action=toggle_lock          → template/toggle_template_lock.php
 *   POST ajax=1 action=copy_template        → template/copy_template.php
 *   POST ajax=1 action=save_contoh          → template/save_contoh_as_template.php
 *   POST ajax=1 action=save_kop             → template/save_contoh_kop.php
 *   POST ajax=1 action=analyze_query        → template/analyze_query.php
 *   POST ajax=1 action=list_categories      → template/list_categories.php
 *   POST ajax=1 action=field_usage          → template/field_usage.php
 *   POST ajax=1 action=field_usage_all      → template/field_usage_all.php
 *   POST ajax=1 action=rename_field         → template/rename_field.php
 *   POST ajax=1 action=cleanup_orphans      → template/cleanup_orphans.php
 *   POST action=delete (form submit)        → template/delete_template.php
 *
 *   ─ Default vars actions (extracted) ─
 *   POST ajax=1 action=list_vars            → default_vars/list_vars.php
 *   POST ajax=1 action=add_var              → default_vars/add_var.php
 *   POST ajax=1 action=delete_var           → default_vars/delete_var.php
 *
 *   ─ Render-path helpers (v0.6.5 extraction, NOT dispatched — required inline) ─
 *   ezdoc/lib/doc_meta_helpers.php     — ezdoc_fetch_creator_name(), ezdoc_load_whitelisted_vars()
 *   ezdoc/lib/doc_template_helpers.php — resolveDefault(), evalCondExprPHP(),
 *                                        evalSingleCondPHP(), processConditionalSections()
 */

// Safety: pastikan helper sudah di-load
if (!function_exists('ezdoc_respond_success')) {
    require_once __DIR__ . '/../bootstrap.php';
}

// Setiap handler di bawah menjawab JSON, dan pemanggilnya selalu `resp.json()`.
// Fatal error menghasilkan badan kosong — browser melaporkan "Unexpected end of
// JSON input", yang tidak menyebutkan apa pun tentang penyebabnya. Ini membuat
// fatal tetap terjawab dalam format yang sama.
ezdoc_guard_fatal_as_json();

// i18n — guarantee $translator + global t() exist regardless of entry path.
// This dispatcher can be require_once'd from an already-bootstrapped
// designer.php/generate.php (which already set $translator/$GLOBALS['translator']
// for their OWN view — e.g. 'generate'), OR entered standalone via a legacy
// consumer-side action page that never touched the Translator at all.
// Endpoint response strings below live under the RESERVED `response.*`
// section of lang/{locale}/common.php (not a per-view catalog), so they
// resolve correctly via t() no matter which view's Translator is currently
// active — see docs/I18N.md.
if (!isset($config) || !($config instanceof \Ezdoc\UI\Config)) {
    $config = new \Ezdoc\UI\Config([]);
}
if (!isset($translator) || !($translator instanceof \Ezdoc\UI\Translator)) {
    $translator = \Ezdoc\UI\Translator::forView('actions', (string) $config->get('app.locale', 'en'));
}
$GLOBALS['translator'] = $translator;
// Bahasa isi dokumen — lihat ezdoc_document_locale(). Kosong = ikut layar.
ezdoc_set_document_locale((string) $config->get('document.locale', ''));
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $default = null): string {
        $translator = $GLOBALS['translator'] ?? null;
        if (!($translator instanceof \Ezdoc\UI\Translator)) {
            // Tanpa katalog, {param} tetap disisipkan — lewat Translator yang
            // sama dengan katalog kosong, jadi teks bawaan dipakai dan
            // "{n}h ago" tidak pernah tampil mentah. Laravel __() dan Symfony
            // Translator juga menyisipkan parameter ke pesan yang tak diterjemahkan.
            $translator = new \Ezdoc\UI\Translator(new \Ezdoc\UI\Config([]));
        }
        return $translator->t($key, $params, $default);
    }
}

// ─── GET /?action=generate_qr ───
if (isset($_GET['action']) && $_GET['action'] === 'generate_qr') {
    require __DIR__ . '/document/generate_qr.php';
    exit;
}

// ─── POST _doc_action=<name> — router untuk 6 doc actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_doc_action'])) {
    // Whitelist actions supaya tidak arbitrary include
    $whitelist = [
        'toggle_doc_lock' => 'toggle_lock.php',
        'list_versions'   => 'list_versions.php',
        'new_version'     => 'new_version.php',
        'delete_version'  => 'delete_version.php',
        'delete_slot'     => 'delete_slot.php',
        'restore_slot'    => 'restore_slot.php',
        // Per VERSI, bukan per slot. restore_slot menghidupkan semua yang pernah
        // disembunyikan di slot itu — termasuk yang memang sengaja disingkirkan.
        'restore_version' => 'restore_version.php',
        'send_ttd_otp'    => 'send_ttd_otp.php',
        'verify_ttd_otp'  => 'verify_ttd_otp.php',
        // Jalur yang dimulai penanda tangan. Pemenuhannya sengaja tidak punya
        // entri di sini: penangan pesan masuk milik aplikasi pemakai memanggil
        // ezdoc_scan_fulfil() in-process, bukan menghubungi servernya sendiri
        // lewat loopback.
        //
        // Verifikasinya memakai verify_ttd_otp yang sudah ada — challenge hasil
        // pindaian adalah challenge biasa.
        'request_scan_code'  => 'request_scan_code.php',
        'scan_code_status'   => 'scan_code_status.php',
        'document_history'=> 'document_history.php',
        // PDF beku satu siklus. Siklus lama sengaja tetap bisa dibuka: ia
        // jawaban atas "apa yang dilihat penanda tangan pertama".
        'artifact'        => 'artifact.php',
    ];
    $action = (string)$_POST['_doc_action'];
    if (isset($whitelist[$action])) {
        require __DIR__ . '/document/' . $whitelist[$action];
        exit;
    }
    // Unknown action — return JSON error
    ezdoc_respond_error('Unknown action: ' . $action, 400);
}

// ─── POST _ajax=1 — default save document handler ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    require __DIR__ . '/document/save_document.php';
    exit;
}

// ─── POST ajax=1 action=<name> — router untuk template & default_vars actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && isset($_POST['action'])) {
    // Template actions → actions/template/<file>
    $templateWhitelist = [
        'save'             => 'save_template.php',
        'toggle_lock'      => 'toggle_template_lock.php',
        'copy_template'    => 'copy_template.php',
        'save_contoh'      => 'save_contoh_as_template.php',
        'save_kop'         => 'save_contoh_kop.php',
        'analyze_query'    => 'analyze_query.php',
        'list_categories'  => 'list_categories.php',
        'field_usage'      => 'field_usage.php',
        'field_usage_all'  => 'field_usage_all.php',
        'rename_field'     => 'rename_field.php',
        'cleanup_orphans'  => 'cleanup_orphans.php',
    ];

    // Default vars actions → actions/default_vars/<file>
    $defaultVarsWhitelist = [
        'list_vars'   => 'list_vars.php',
        'add_var'     => 'add_var.php',
        'delete_var'  => 'delete_var.php',
    ];

    $action = (string) $_POST['action'];

    if (isset($templateWhitelist[$action])) {
        require __DIR__ . '/template/' . $templateWhitelist[$action];
        exit;
    }

    if (isset($defaultVarsWhitelist[$action])) {
        require __DIR__ . '/default_vars/' . $defaultVarsWhitelist[$action];
        exit;
    }

    // No match — biarkan main file yang handle (kalau ada inline handler tersisa).
    // Graceful fallback: tidak return error.
}

// ─── POST action=delete (non-ajax form submit) — hard-delete template ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['delete_id'])) {
    require __DIR__ . '/template/delete_template.php';
    exit;
}

// No match — return, biar main page lanjut render normal.
