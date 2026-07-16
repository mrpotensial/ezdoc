<?php
/**
 * POST _doc_action=delete_version, body: doc_id
 *
 * Soft-delete versi dokumen tertentu (set deleted_at + deleted_by).
 * Guard:
 *   - Superadmin only (default kalau access_config.delete tidak set)
 *   - Version tidak boleh locked
 *   - Version tidak boleh yang terakhir di slot
 *
 * Response: { success }
 *
 * ## v0.9.9 refactor
 *
 * Persistence lewat Connection. JOIN untuk access_config lookup masih raw
 * SQL (multi-table + JSON column).
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

use Ezdoc\Context;

global $author_id;

$did = (int)($_POST['doc_id'] ?? 0);
if ($did <= 0) ezdoc_respond_error(t('response.invalid_doc_id', [], 'Invalid document ID'));

$db = new MysqliConnection(Context::default()->db);

// Load doc + template access_config (JOIN via template_id)
$doc = $db->fetchOne(
    'SELECT d.template_id, d.template_uuid, d.norm, d.nopen, d.label, d.is_locked, t.access_config
     FROM ezdoc_documents d
     LEFT JOIN ezdoc_templates t ON t.id = d.template_id
     WHERE d.id = ? AND d.deleted_at IS NULL',
    [$did]
);
if (!$doc) ezdoc_respond_error(t('response.document_not_found', [], 'Document not found'));

// RBAC delete check — beda dgn create/edit/lock: kalau kosong, default superadmin only.
$accessConfig = ezdoc_parse_access_config($doc['access_config'] ?? null);
$deleteCfg = $accessConfig['delete'] ?? null;
$hasExplicitDeleteConfig = is_array($deleteCfg) && (
    !empty($deleteCfg['roles']) || !empty($deleteCfg['users'])
);
if (!$hasExplicitDeleteConfig) {
    ezdoc_require_role('superadmin', 'Hapus versi hanya bisa dilakukan superadmin. Set access_config.delete di template untuk allow role lain.');
} else {
    ezdoc_require_on_template($accessConfig, 'delete', 'Tidak berhak hapus dokumen dari template ini');
}

if ((int) $doc['is_locked'] === 1) {
    ezdoc_respond_error(t('response.version_locked_cannot_delete', [], 'Locked version cannot be deleted. Unlock it first.'));
}

// Cek apakah ini versi terakhir di slot (search by template_uuid untuk lintas versi template)
$cnt = (int) $db->fetchScalar(
    'SELECT COUNT(*) FROM ezdoc_documents
     WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ? AND deleted_at IS NULL',
    [$doc['template_uuid'], $doc['norm'], $doc['nopen'], $doc['label']]
);
if ($cnt <= 1) {
    ezdoc_respond_error(t('response.last_version_in_slot', [], 'This is the last version in the slot. Use "Delete Slot" to remove the entire slot.'));
}

// Soft delete
$author = (string) ($author_id ?? 'system');
try {
    $db->execute(
        'UPDATE ezdoc_documents SET deleted_at = NOW(), deleted_by = ? WHERE id = ?',
        [$author, $did]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.delete_failed', ['error' => $e->getMessage()], 'Failed to delete: {error}'));
}

ezdoc_audit_log('doc.deleted', [
    'target_type' => 'document',
    'target_id'   => (string) $did,
    'template_id' => (int) $doc['template_id'],
    'doc_id'      => $did,
    'metadata'    => [
        'scope' => 'version',
        'norm'  => $doc['norm'],
        'nopen' => $doc['nopen'],
        'label' => $doc['label'],
    ],
    'message' => "Soft delete versi dokumen (norm {$doc['norm']}, label {$doc['label']})",
]);

ezdoc_respond_success([], t('response.version_deleted', [], 'Version deleted successfully (soft delete)'));
