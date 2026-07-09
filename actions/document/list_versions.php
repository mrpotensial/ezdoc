<?php
/**
 * POST _doc_action=list_versions
 *   body: template_id, norm, nopen, label
 *
 * List semua versi (active only) di slot dokumen.
 *
 * Response: { success: true, versions: [{ id, version, is_locked, updated_at }, ...] }
 *
 * Note: response backward-compat pakai key `versions` top-level (bukan `data.versions`).
 * Existing frontend akses `data.versions` di response.
 */

global $conn;

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    // Backward-compat: existing frontend expect versions=[] even on error
    ezdoc_respond_raw(['success' => false, 'versions' => []]);
}

// Query by template_uuid (family) supaya lintas template version tetap ke-detect.
// Fallback resolve template_id → template_uuid via JOIN.
$stmt = mysqli_prepare($conn, "
    SELECT d.id, d.version, d.is_locked, d.updated_at
    FROM ezdoc_documents d
    INNER JOIN ezdoc_templates t ON t.uuid = d.template_uuid
    WHERE t.id = ? AND d.norm = ? AND d.nopen = ? AND d.label = ? AND d.deleted_at IS NULL
    ORDER BY d.version DESC
");
mysqli_stmt_bind_param($stmt, "isss", $tid, $n, $np, $lb);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$versions = [];
while ($row = mysqli_fetch_assoc($res)) {
    $versions[] = [
        'id' => (int)$row['id'],
        'version' => (int)$row['version'],
        'is_locked' => (int)$row['is_locked'],
        'updated_at' => $row['updated_at'],
    ];
}

ezdoc_respond_success(['versions' => $versions]);
