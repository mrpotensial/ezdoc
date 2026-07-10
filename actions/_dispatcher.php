<?php
/**
 * ezdoc action dispatcher.
 *
 * Dipanggil dari main page (form_pembuat_surat_v2/v3, form_pembuat_surat_cetak_v2/v3, dll)
 * di TOP setelah bootstrap. Kalau match ke suatu action → require handler → exit.
 * Kalau tidak match → return biar page lanjut rendering normal.
 *
 * Assumsi: koneksi.php sudah di-require sebelum ini (untuk $conn, hasRole(), $author_id).
 * ezdoc/bootstrap.php sudah di-require (untuk helper functions).
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
