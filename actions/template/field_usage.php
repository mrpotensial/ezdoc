<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=field_usage, template_id, field_name)
 *
 * Hitung berapa dokumen dari template ini yang punya field_values[field_name]
 * dengan value non-empty. Dipakai untuk warning saat rename/hapus field
 * yang masih dipakai dokumen live.
 *
 * Read-only.
 *
 * Auth: template manager.
 *
 * Response:
 *   { success: true, count: <int>, field: <name> }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak inspect field usage');

$tid = (int) ($_POST['template_id'] ?? 0);
$fieldName = trim($_POST['field_name'] ?? '');

if ($tid <= 0 || $fieldName === '') {
    ezdoc_respond_error('Parameter tidak lengkap');
}

$sql = "
    SELECT COUNT(*) AS c
    FROM ezdoc_documents
    WHERE template_id = ?
      AND JSON_EXTRACT(field_values, CONCAT('\$.', ?)) IS NOT NULL
      AND JSON_EXTRACT(field_values, CONCAT('\$.', ?)) != ''
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iss", $tid, $fieldName, $fieldName);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();

ezdoc_respond_success([
    'count' => (int) ($row['c'] ?? 0),
    'field' => $fieldName,
]);
