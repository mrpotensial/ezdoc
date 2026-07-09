<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=add_var, var_name, description)
 *
 * Add new default variable ke `ezdoc_default_vars`.
 * INSERT IGNORE — kalau var_name sudah ada, silent skip.
 *
 * Sanitization: var_name harus alphanumeric + underscore only.
 *
 * Auth: template manager only + audit log.
 *
 * Response:
 *   { success: true|false, message: "..." }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak menambahkan default variable');

$varName = trim($_POST['var_name'] ?? '');
$varDesc = trim($_POST['description'] ?? '');

if ($varName === '') {
    ezdoc_respond_error('Nama variabel wajib diisi');
}

// Sanitize: alphanumeric + underscore only
$varNameClean = preg_replace('/[^a-zA-Z0-9_]/', '', $varName);
if ($varNameClean === '') {
    ezdoc_respond_error('Nama variabel harus alphanumeric atau underscore');
}

$stmt = mysqli_prepare($conn, "INSERT IGNORE INTO ezdoc_default_vars (var_name, description) VALUES (?, ?)");
mysqli_stmt_bind_param($stmt, "ss", $varNameClean, $varDesc);

if (!mysqli_stmt_execute($stmt)) {
    ezdoc_respond_error('Gagal menambahkan variabel: ' . mysqli_error($conn));
}

// Audit
ezdoc_audit_log('default_var.added', [
    'target_type' => 'default_var',
    'target_id' => $varNameClean,
    'metadata' => [
        'var_name' => $varNameClean,
        'description' => $varDesc,
    ],
    'message' => "Tambah default var '{$varNameClean}'",
]);

ezdoc_respond_success([
    'var_name' => $varNameClean,
], 'Variabel ditambahkan');