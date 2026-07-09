<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=rename_field,
 *                                       template_id, old_name, new_name)
 *
 * Rename field key di SEMUA dokumen milik template ini:
 *   field_values[old_name] → field_values[new_name]
 *
 * Guard: kalau new_name sudah ada value non-empty di dokumen tertentu,
 * SKIP overwrite (biar tidak data loss). Old key tetap di-unset.
 *
 * Auth: template manager. Audit log.
 *
 * Response:
 *   { success: true, updated: <int>, message: "..." }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak rename field');

$tid = (int) ($_POST['template_id'] ?? 0);
$oldName = trim($_POST['old_name'] ?? '');
$newName = trim($_POST['new_name'] ?? '');

if ($tid <= 0 || $oldName === '' || $newName === '' || $oldName === $newName) {
    ezdoc_respond_error('Parameter tidak valid');
}

// Sanitize new name (alphanumeric + underscore + hyphen)
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $newName)) {
    ezdoc_respond_error('Nama baru harus alphanumeric, underscore, atau hyphen');
}

$stmt = mysqli_prepare($conn, "SELECT id, field_values FROM ezdoc_documents WHERE template_id = ?");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$updated = 0;
$skipped = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $fields = json_decode($row['field_values'] ?: '{}', true) ?: [];
    if (!array_key_exists($oldName, $fields)) continue;

    // Only overwrite new if it's empty — else keep new's existing value, don't clobber
    $newHasValue = array_key_exists($newName, $fields)
                && $fields[$newName] !== ''
                && $fields[$newName] !== null;

    if (!$newHasValue) {
        $fields[$newName] = $fields[$oldName];
    } else {
        $skipped++;
    }
    unset($fields[$oldName]);

    $newJson = json_encode($fields);
    $upd = mysqli_prepare($conn, "UPDATE ezdoc_documents SET field_values = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $newJson, $row['id']);
    if (mysqli_stmt_execute($upd)) $updated++;
}

ezdoc_audit_log('template.field_renamed', [
    'target_type' => 'template',
    'target_id' => (string) $tid,
    'template_id' => $tid,
    'metadata' => [
        'old_name' => $oldName,
        'new_name' => $newName,
        'updated_docs' => $updated,
        'skipped_conflicts' => $skipped,
    ],
    'message' => "Rename field '{$oldName}' → '{$newName}' di template #{$tid} ({$updated} docs updated)",
]);

$msg = "{$updated} dokumen di-update";
if ($skipped > 0) {
    $msg .= " ({$skipped} skip karena '{$newName}' sudah ada value)";
}

ezdoc_respond_success([
    'updated' => $updated,
    'skipped' => $skipped,
], $msg);
