<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=cleanup_orphans,
 *                                       template_id, valid_fields)
 *
 * Hapus key dari field_values yang bukan lagi bagian dari template's fieldNames.
 * "Orphan" = key di dokumen tapi field-nya sudah di-remove dari template design.
 *
 * PRESERVE: key yang dimulai dengan `_ttd_` — signature slot state, jangan touch.
 *
 * Input:
 *   - template_id: int
 *   - valid_fields: CSV of current field names (dari template designer)
 *
 * Auth: template manager. Audit log (destructive op).
 *
 * Response:
 *   { success: true, updated: <int>, removedKeys: [<key>, ...] }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak cleanup field orphans');

$tid = (int) ($_POST['template_id'] ?? 0);
$validFieldsCsv = trim($_POST['valid_fields'] ?? '');

if ($tid <= 0) {
    ezdoc_respond_error('ID template tidak valid');
}

$validFields = array_filter(array_map('trim', explode(',', $validFieldsCsv)));
$validSet = array_flip($validFields);

$stmt = mysqli_prepare($conn, "SELECT id, field_values FROM ezdoc_documents WHERE template_id = ?");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$updated = 0;
$removedKeys = [];
while ($row = mysqli_fetch_assoc($res)) {
    $fields = json_decode($row['field_values'] ?: '{}', true) ?: [];
    $changed = false;
    foreach (array_keys($fields) as $k) {
        // Preserve TTD signature slot state
        if (strpos($k, '_ttd_') === 0) continue;
        // Preserve valid template fields
        if (isset($validSet[$k])) continue;
        // Orphan — remove
        unset($fields[$k]);
        $removedKeys[$k] = true;
        $changed = true;
    }
    if ($changed) {
        $newJson = json_encode($fields);
        $upd = mysqli_prepare($conn, "UPDATE ezdoc_documents SET field_values = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "si", $newJson, $row['id']);
        if (mysqli_stmt_execute($upd)) $updated++;
    }
}

$removedList = array_keys($removedKeys);

ezdoc_audit_log('template.orphans_cleaned', [
    'target_type' => 'template',
    'target_id' => (string) $tid,
    'template_id' => $tid,
    'metadata' => [
        'valid_fields' => $validFields,
        'removed_keys' => $removedList,
        'updated_docs' => $updated,
    ],
    'message' => "Cleanup " . count($removedList) . " orphan field(s) di template #{$tid} ({$updated} docs updated)",
]);

ezdoc_respond_success([
    'updated' => $updated,
    'removedKeys' => $removedList,
], "{$updated} dokumen dibersihkan, " . count($removedList) . " field orphan dihapus");
