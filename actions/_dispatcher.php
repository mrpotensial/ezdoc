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
 *   POST action=delete (form submit)        → template/delete_template.php
 *
 *   ─ Belum extract (inline di designer) ─
 *   POST ajax=1 action=analyze_query, list_vars, add_var, delete_var,
 *                     list_categories, field_usage, field_usage_all,
 *                     rename_field, cleanup_orphans
 *   → di-handle inline di designer. Extract nanti kalau perlu.
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

// ─── POST ajax=1 action=<name> — router untuk template actions (dari designer) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && isset($_POST['action'])) {
    $templateWhitelist = [
        'save'           => 'save_template.php',
        'toggle_lock'    => 'toggle_template_lock.php',
        'copy_template'  => 'copy_template.php',
    ];
    $action = (string)$_POST['action'];
    if (isset($templateWhitelist[$action])) {
        require __DIR__ . '/template/' . $templateWhitelist[$action];
        exit;
    }
    // Action lain (analyze_query, list_vars, dll) — biarkan main file yang handle inline.
    // Ini graceful fallback: tidak return error, biar main file inline handler match.
}

// ─── POST action=delete (non-ajax form submit) — hard-delete template ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['delete_id'])) {
    require __DIR__ . '/template/delete_template.php';
    exit;
}

// No match — return, biar main page lanjut render normal.
