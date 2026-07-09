<?php
/**
 * POST _doc_action=toggle_doc_lock, doc_id=<id>, locked=<0|1>
 *
 * Lock/unlock dokumen (ezdoc_documents.is_locked).
 * - Lock: any authenticated user
 * - Unlock: superadmin only (TTD tidak boleh dimodifikasi setelah lock)
 *
 * Response: { success, locked }
 */

global $conn;

$did = (int)($_POST['doc_id'] ?? 0);
$locked = (int)($_POST['locked'] ?? 0);
if ($did <= 0) ezdoc_respond_error('Doc ID invalid');

// Unlock guard — hanya superadmin. Untuk revisi, user biasa buat versi baru.
if ($locked === 0) {
    ezdoc_require_role('superadmin', 'Unlock hanya bisa dilakukan superadmin. Untuk revisi, buat versi baru.');
}

// Lock guard — cek template access_config lewat JOIN via template_id.
if ($locked === 1) {
    $stmt = mysqli_prepare($conn, "
        SELECT t.access_config
        FROM ezdoc_documents d
        LEFT JOIN ezdoc_templates t ON t.id = d.template_id
        WHERE d.id = ? LIMIT 1
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $did);
        mysqli_stmt_execute($stmt);
        $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
        $accessConfig = ezdoc_parse_access_config($row['access_config'] ?? null);
        ezdoc_require_on_template($accessConfig, 'lock', 'Tidak berhak me-lock dokumen ini');
    }
}

// Update juga status untuk konsistensi dengan is_locked
$newStatus = $locked ? 'locked' : 'published';
$stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET is_locked = ?, status = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "isi", $locked, $newStatus, $did);
$ok = mysqli_stmt_execute($stmt);

if ($ok) {
    ezdoc_audit_log($locked ? 'doc.locked' : 'doc.unlocked', [
        'target_type' => 'document',
        'target_id' => (string)$did,
        'doc_id' => $did,
        'message' => 'Dokumen ' . ($locked ? 'dilock' : 'diunlock'),
    ]);
    ezdoc_respond_success(['locked' => $locked], 'Dokumen ' . ($locked ? 'dilock' : 'diunlock'));
} else {
    ezdoc_respond_error('Gagal update lock: ' . mysqli_error($conn));
}
