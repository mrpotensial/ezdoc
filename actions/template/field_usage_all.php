<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=field_usage_all, template_id)
 *
 * Bulk field usage: scan semua dokumen template ini, agregat berapa
 * dokumen yang punya value non-empty per field key. Dipakai untuk
 * "orphan cleanup" preview di designer.
 *
 * Read-only.
 *
 * Auth: template manager.
 *
 * Response:
 *   { success: true, totalDocs: <int>, fieldCounts: { <field>: <count>, ... } }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak scan field usage');

$tid = (int) ($_POST['template_id'] ?? 0);
if ($tid <= 0) {
    ezdoc_respond_error('ID template tidak valid');
}

$stmt = mysqli_prepare($conn, "SELECT field_values FROM ezdoc_documents WHERE template_id = ?");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$fieldCounts = [];
$totalDocs = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $totalDocs++;
    $fields = json_decode($row['field_values'] ?: '{}', true) ?: [];
    foreach ($fields as $k => $v) {
        if ($v !== '' && $v !== null) {
            $fieldCounts[$k] = ($fieldCounts[$k] ?? 0) + 1;
        }
    }
}

ezdoc_respond_success([
    'totalDocs' => $totalDocs,
    'fieldCounts' => $fieldCounts,
]);
