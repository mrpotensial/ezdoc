<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=delete_var, var_id)
 *
 * Delete default variable dari `ezdoc_default_vars` by ID.
 *
 * Auth: template manager only + audit log.
 *
 * Response:
 *   { success: true }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak menghapus default variable');

$varId = (int)($_POST['var_id'] ?? 0);
if ($varId <= 0) {
    ezdoc_respond_error('ID tidak valid');
}

// Fetch nama untuk audit log (best-effort)
$varName = null;
$stmtSel = mysqli_prepare($conn, "SELECT var_name FROM ezdoc_default_vars WHERE id = ?");
if ($stmtSel) {
    mysqli_stmt_bind_param($stmtSel, "i", $varId);
    mysqli_stmt_execute($stmtSel);
    $row = mysqli_stmt_get_result($stmtSel)->fetch_assoc();
    if ($row) $varName = $row['var_name'];
}

$stmt = mysqli_prepare($conn, "DELETE FROM ezdoc_default_vars WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $varId);
mysqli_stmt_execute($stmt);

if ($varName) {
    ezdoc_audit_log('default_var.deleted', [
        'target_type' => 'default_var',
        'target_id' => $varName,
        'metadata' => ['var_id' => $varId, 'var_name' => $varName],
        'message' => "Hapus default var '{$varName}'",
    ]);
}

ezdoc_respond_success([], 'Variabel dihapus');