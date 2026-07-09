<?php
/**
 * POST _doc_action=delete_slot
 *   body: template_id, norm, nopen, label
 *
 * Soft-delete SEMUA versi di slot (template_uuid, norm, nopen, label).
 * Guard:
 *   - Superadmin only (kecuali template access_config.delete di-set)
 *   - Refuse kalau ada versi locked (harus unlock dulu manual)
 *
 * Response: { success, affected: <count> }
 */

global $conn, $author_id;

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error('Parameter tidak lengkap');
}

// Resolve template_id → uuid (untuk slot query lintas template version)
$stmt = mysqli_prepare($conn, "SELECT uuid, access_config FROM ezdoc_templates WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$tpl = mysqli_stmt_get_result($stmt)->fetch_assoc();
if (!$tpl) ezdoc_respond_error('Template tidak ditemukan');
$templateUuid = (string)$tpl['uuid'];
$accessConfig = ezdoc_parse_access_config($tpl['access_config'] ?? null);

// ─── RBAC delete check ───
$deleteCfg = $accessConfig['delete'] ?? null;
$hasExplicitDeleteConfig = is_array($deleteCfg) && (
    !empty($deleteCfg['roles']) || !empty($deleteCfg['users'])
);
if (!$hasExplicitDeleteConfig) {
    ezdoc_require_role('superadmin', 'Hapus slot hanya bisa dilakukan superadmin. Set access_config.delete di template untuk allow role lain.');
} else {
    ezdoc_require_on_template($accessConfig, 'delete', 'Tidak berhak hapus slot dari template ini');
}

// Refuse kalau ada versi locked
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM ezdoc_documents WHERE template_uuid=? AND norm=? AND nopen=? AND label=? AND is_locked = 1 AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "ssss", $templateUuid, $n, $np, $lb);
mysqli_stmt_execute($stmt);
$lockedCnt = (int)(mysqli_stmt_get_result($stmt)->fetch_assoc()['c'] ?? 0);

if ($lockedCnt > 0) {
    ezdoc_respond_error("Slot punya {$lockedCnt} versi locked. Unlock dulu sebelum hapus.");
}

// Soft delete semua active version di slot
$author = (string)($author_id ?? 'system');
$stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET deleted_at = NOW(), deleted_by = ? WHERE template_uuid=? AND norm=? AND nopen=? AND label=? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "sssss", $author, $templateUuid, $n, $np, $lb);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_affected_rows($conn);

if ($ok) {
    ezdoc_audit_log('doc.deleted', [
        'target_type' => 'document',
        'target_id' => "slot:tpl{$tid}:{$n}:{$np}:{$lb}",
        'template_id' => $tid,
        'metadata' => [
            'scope' => 'slot',
            'norm' => $n,
            'nopen' => $np,
            'label' => $lb,
            'affected_versions' => $affected,
            'template_uuid' => $templateUuid,
        ],
        'message' => "Soft delete slot dokumen ({$affected} versi, norm {$n}, label {$lb})",
    ]);
    ezdoc_respond_success(['affected' => $affected], "Slot berhasil dihapus ({$affected} versi)");
} else {
    ezdoc_respond_error('Gagal hapus slot: ' . mysqli_error($conn));
}
