<?php
/**
 * POST _doc_action=restore_slot
 *   body: template_id, norm, nopen, label
 *
 * Restore soft-deleted slot (semua versi di dalamnya).
 * Guard: superadmin only.
 *
 * Response: { success, affected: <count> }
 */

global $conn;

ezdoc_require_role('superadmin', 'Restore hanya bisa dilakukan superadmin.');

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error('Parameter tidak lengkap');
}

// Resolve template_id → uuid (untuk slot query lintas template version)
$stmtTpl = mysqli_prepare($conn, "SELECT uuid FROM ezdoc_templates WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmtTpl, "i", $tid);
mysqli_stmt_execute($stmtTpl);
$rowTpl = mysqli_stmt_get_result($stmtTpl)->fetch_assoc();
if (!$rowTpl) ezdoc_respond_error('Template tidak ditemukan');
$templateUuid = (string)$rowTpl['uuid'];

$stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET deleted_at = NULL, deleted_by = NULL WHERE template_uuid=? AND norm=? AND nopen=? AND label=? AND deleted_at IS NOT NULL");
mysqli_stmt_bind_param($stmt, "ssss", $templateUuid, $n, $np, $lb);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_affected_rows($conn);

if ($ok) {
    ezdoc_audit_log('doc.restored', [
        'target_type' => 'document',
        'target_id' => "slot:tpl{$tid}:{$n}:{$np}:{$lb}",
        'template_id' => $tid,
        'metadata' => [
            'scope' => 'slot',
            'norm' => $n,
            'nopen' => $np,
            'label' => $lb,
            'affected_versions' => $affected,
        ],
        'message' => "Restore slot dokumen ({$affected} versi, norm {$n}, label {$lb})",
    ]);
    ezdoc_respond_success(['affected' => $affected], "Slot berhasil di-restore ({$affected} versi)");
} else {
    ezdoc_respond_error('Gagal restore: ' . mysqli_error($conn));
}
