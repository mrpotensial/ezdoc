<?php
/**
 * POST _doc_action=new_version
 *   body: template_id, norm, nopen, label, source_version (0 = blank)
 *
 * Buat versi baru dokumen di slot (template_uuid, norm, nopen, label).
 * - source_version=0: blank (field_values & signature_values = {})
 * - source_version>0: copy dari versi tersebut
 *
 * Response: { success, doc_id, version }
 */

global $conn, $author_id;

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');
$sourceVersion = isset($_POST['source_version']) ? (int)$_POST['source_version'] : 0;

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error('Parameter tidak lengkap');
}

// Resolve template_id → uuid + version (snapshot template state saat versi baru dibuat)
$stmt = mysqli_prepare($conn, "SELECT uuid, version FROM ezdoc_templates WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$tpl = mysqli_stmt_get_result($stmt)->fetch_assoc();
if (!$tpl) ezdoc_respond_error('Template tidak ditemukan');
$templateUuid = (string)$tpl['uuid'];
$templateVersion = (int)$tpl['version'];

// Find next version (include soft-deleted supaya unique key tidak collision).
// Search by template_uuid (family) — supaya lintas template version tetap ke-detect.
$stmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(version), 0) + 1 AS nextv FROM ezdoc_documents WHERE template_uuid=? AND norm=? AND nopen=? AND label=?");
mysqli_stmt_bind_param($stmt, "ssss", $templateUuid, $n, $np, $lb);
mysqli_stmt_execute($stmt);
$nextRow = mysqli_stmt_get_result($stmt)->fetch_assoc();
$nextVersion = (int)($nextRow['nextv'] ?? 1);

// Copy dari source atau blank
$newFields = '{}';
$newTtd = '{}';
if ($sourceVersion > 0) {
    $stmt = mysqli_prepare($conn, "SELECT field_values, signature_values FROM ezdoc_documents WHERE template_uuid=? AND norm=? AND nopen=? AND label=? AND version=? AND deleted_at IS NULL");
    mysqli_stmt_bind_param($stmt, "ssssi", $templateUuid, $n, $np, $lb, $sourceVersion);
    mysqli_stmt_execute($stmt);
    $src = mysqli_stmt_get_result($stmt)->fetch_assoc();
    if ($src) {
        $newFields = $src['field_values'] ?: '{}';
        $newTtd = $src['signature_values'] ?: '{}';
    }
}

$authorId = (int)($author_id ?? 0);
$docUuid = ezdoc_uuid_v7();
$status = 'published';
$publishedAt = date('Y-m-d H:i:s');

$stmt = mysqli_prepare($conn, "
    INSERT INTO ezdoc_documents
    (uuid, template_id, template_uuid, template_version,
     norm, nopen, label, version,
     field_values, signature_values,
     status, published_at, is_locked, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
");
mysqli_stmt_bind_param(
    $stmt,
    "sisisssissssi",
    $docUuid, $tid, $templateUuid, $templateVersion,
    $n, $np, $lb, $nextVersion,
    $newFields, $newTtd,
    $status, $publishedAt, $authorId
);

if (mysqli_stmt_execute($stmt)) {
    $newDocId = mysqli_insert_id($conn);
    ezdoc_audit_log('doc.version_created', [
        'target_type' => 'document',
        'target_id' => (string)$newDocId,
        'template_id' => $tid,
        'doc_id' => $newDocId,
        'metadata' => [
            'version' => $nextVersion,
            'source_version' => $sourceVersion,
            'norm' => $n,
            'nopen' => $np,
            'label' => $lb,
            'template_uuid' => $templateUuid,
        ],
        'message' => "Versi baru v{$nextVersion} " . ($sourceVersion > 0 ? "(copy dari v{$sourceVersion})" : '(blank)'),
    ]);
    ezdoc_respond_success([
        'doc_id' => $newDocId,
        'version' => $nextVersion,
    ], "Versi baru v{$nextVersion} berhasil dibuat");
} else {
    ezdoc_respond_error(mysqli_error($conn));
}
