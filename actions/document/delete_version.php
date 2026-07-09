<?php
/**
 * POST _doc_action=delete_version
 *   body: doc_id
 *
 * Soft-delete versi dokumen tertentu (set deleted_at + deleted_by).
 * Guard:
 *   - Superadmin only
 *   - Version tidak boleh locked
 *   - Version tidak boleh yang terakhir di slot (harus ada minimal 1 active version)
 *
 * Response: { success }
 */

global $conn, $author_id;

$did = (int)($_POST['doc_id'] ?? 0);
if ($did <= 0) ezdoc_respond_error('Doc ID invalid');

// Load doc + template access_config (JOIN via template_id)
$stmt = mysqli_prepare($conn, "
    SELECT d.template_id, d.template_uuid, d.norm, d.nopen, d.label, d.is_locked, t.access_config
    FROM ezdoc_documents d
    LEFT JOIN ezdoc_templates t ON t.id = d.template_id
    WHERE d.id = ? AND d.deleted_at IS NULL
");
mysqli_stmt_bind_param($stmt, "i", $did);
mysqli_stmt_execute($stmt);
$doc = mysqli_stmt_get_result($stmt)->fetch_assoc();

if (!$doc) ezdoc_respond_error('Dokumen tidak ditemukan');

// ─── RBAC delete check ───
// Beda dengan create/edit/lock: kalau access_config.delete kosong, default = superadmin only
// (bukan allow-all). Delete = destructive, safe default lebih ketat.
$accessConfig = ezdoc_parse_access_config($doc['access_config'] ?? null);
$deleteCfg = $accessConfig['delete'] ?? null;
$hasExplicitDeleteConfig = is_array($deleteCfg) && (
    !empty($deleteCfg['roles']) || !empty($deleteCfg['users'])
);
if (!$hasExplicitDeleteConfig) {
    // Default: superadmin only
    ezdoc_require_role('superadmin', 'Hapus versi hanya bisa dilakukan superadmin. Set access_config.delete di template untuk allow role lain.');
} else {
    // Explicit config → cek via helper (superadmin tetap bypass)
    ezdoc_require_on_template($accessConfig, 'delete', 'Tidak berhak hapus dokumen dari template ini');
}

if ((int)$doc['is_locked'] === 1) {
    ezdoc_respond_error('Versi locked tidak bisa dihapus. Unlock dulu.');
}

// Cek apakah ini versi terakhir di slot (search by template_uuid supaya lintas versi template ke-count)
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM ezdoc_documents WHERE template_uuid=? AND norm=? AND nopen=? AND label=? AND deleted_at IS NULL");
mysqli_stmt_bind_param($stmt, "ssss", $doc['template_uuid'], $doc['norm'], $doc['nopen'], $doc['label']);
mysqli_stmt_execute($stmt);
$cnt = (int)(mysqli_stmt_get_result($stmt)->fetch_assoc()['c'] ?? 0);

if ($cnt <= 1) {
    ezdoc_respond_error('Ini versi terakhir di slot. Pakai "Hapus Slot" untuk hapus seluruh slot.');
}

// Soft delete
$author = (string)($author_id ?? 'system');
$stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "si", $author, $did);
$ok = mysqli_stmt_execute($stmt);

if ($ok) {
    ezdoc_audit_log('doc.deleted', [
        'target_type' => 'document',
        'target_id' => (string)$did,
        'template_id' => (int)$doc['template_id'],
        'doc_id' => $did,
        'metadata' => [
            'scope' => 'version',
            'norm' => $doc['norm'],
            'nopen' => $doc['nopen'],
            'label' => $doc['label'],
        ],
        'message' => "Soft delete versi dokumen (norm {$doc['norm']}, label {$doc['label']})",
    ]);
    ezdoc_respond_success([], 'Versi berhasil dihapus (soft delete)');
} else {
    ezdoc_respond_error('Gagal hapus: ' . mysqli_error($conn));
}
