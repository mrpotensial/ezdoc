<?php
/**
 * POST action=delete (non-ajax form submit)
 *   body: delete_id
 *
 * Hard-delete template dari surat_template_v2. Destructive — data dokumen anak
 * TIDAK di-clean (foreign key check tergantung schema).
 *
 * Auth:
 *   - `ezdoc_require_manage_templates()` — global template management RBAC
 *   - Additional: superadmin only untuk delete (destructive), hardened di sini.
 *
 * Response: redirect ke ?action=list&msg=deleted (form submit behavior).
 *          Kalau JSON call (Accept: application/json), return JSON.
 */

global $conn;

// Guard 1: global template management
ezdoc_require_manage_templates('Tidak berhak modify template');

// Guard 2: delete destructive — superadmin only (hardcoded, tidak bisa override via config)
// Setara dengan default DELETE di document (safe-by-default untuk destructive ops)
ezdoc_require_role('superadmin', 'Hapus template hanya bisa dilakukan superadmin');

$delete_id = (int)($_POST['delete_id'] ?? 0);
if ($delete_id <= 0) {
    // Fallback backward-compat: silent return kalau id invalid (behaviour lama)
    header('Location: ?action=list');
    exit;
}

// Fetch name sebelum delete supaya bisa di-log
$stmtName = mysqli_prepare($conn, "SELECT name FROM ezdoc_templates WHERE id = ? LIMIT 1");
$templateName = null;
if ($stmtName) {
    mysqli_stmt_bind_param($stmtName, "i", $delete_id);
    mysqli_stmt_execute($stmtName);
    $rowName = mysqli_stmt_get_result($stmtName)->fetch_assoc();
    $templateName = $rowName['name'] ?? null;
}

$stmt = mysqli_prepare($conn, "DELETE FROM ezdoc_templates WHERE id=?");
mysqli_stmt_bind_param($stmt, "i", $delete_id);

if (mysqli_stmt_execute($stmt)) {
    ezdoc_audit_log('template.deleted', [
        'target_type' => 'template',
        'target_id' => (string)$delete_id,
        'template_id' => $delete_id,
        'metadata' => ['name' => $templateName],
        'message' => "Hard delete template #{$delete_id}" . ($templateName ? " ({$templateName})" : ''),
    ]);
    // Backward-compat: form submit → redirect
    header('Location: ?action=list&msg=deleted');
    exit;
}

ezdoc_audit_log('template.deleted', [
    'target_type' => 'template',
    'target_id' => (string)$delete_id,
    'template_id' => $delete_id,
    'result' => 'error',
    'message' => "Gagal delete template #{$delete_id}: " . mysqli_error($conn),
]);
// Gagal → tampilkan error (rare case)
header('Location: ?action=list&msg=delete_failed');
exit;
