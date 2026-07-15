<?php
/**
 * POST _doc_action=toggle_doc_lock, doc_id=<id>, locked=<0|1>
 *
 * Lock/unlock dokumen (ezdoc_documents.is_locked). Update status untuk
 * konsistensi ('locked' | 'published').
 *
 * - Lock:   any authenticated user (subject to template access_config)
 * - Unlock: superadmin only (TTD tidak boleh dimodifikasi setelah lock)
 *
 * Response: { success, locked }
 *
 * ## v0.9.9 refactor
 *
 * Persistence lewat `Ezdoc\Db\Connection`. JOIN access_config lookup masih
 * raw SQL (QueryBuilder MVP belum ideal untuk JOIN + JSON column projection).
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

$did    = (int)($_POST['doc_id'] ?? 0);
$locked = (int)($_POST['locked'] ?? 0);
if ($did <= 0) ezdoc_respond_error(t('response.invalid_doc_id', [], 'Invalid document ID'));

// Unlock guard — hanya superadmin. Untuk revisi, user biasa buat versi baru.
if ($locked === 0) {
    ezdoc_require_role('superadmin', 'Unlock hanya bisa dilakukan superadmin. Untuk revisi, buat versi baru.');
}

$db = new MysqliConnection($conn);

// Lock guard — cek template access_config lewat JOIN via template_id.
if ($locked === 1) {
    $row = $db->fetchOne(
        'SELECT t.access_config
         FROM ezdoc_documents d
         LEFT JOIN ezdoc_templates t ON t.id = d.template_id
         WHERE d.id = ? LIMIT 1',
        [$did]
    );
    $accessConfig = ezdoc_parse_access_config($row['access_config'] ?? null);
    ezdoc_require_on_template($accessConfig, 'lock', 'Tidak berhak me-lock dokumen ini');
}

$newStatus = $locked ? 'locked' : 'published';

try {
    $db->execute(
        'UPDATE ezdoc_documents SET is_locked = ?, status = ? WHERE id = ?',
        [$locked, $newStatus, $did]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.update_lock_failed', ['error' => $e->getMessage()], 'Failed to update lock: {error}'));
}

ezdoc_audit_log($locked ? 'doc.locked' : 'doc.unlocked', [
    'target_type' => 'document',
    'target_id'   => (string) $did,
    'doc_id'      => $did,
    'message'     => 'Dokumen ' . ($locked ? 'dilock' : 'diunlock'),
]);

$lockMessage = $locked
    ? t('response.document_locked', [], 'Document locked')
    : t('response.document_unlocked', [], 'Document unlocked');

ezdoc_respond_success(['locked' => $locked], $lockMessage);
