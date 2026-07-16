<?php
/**
 * POST _doc_action=list_versions
 *   body: template_id, norm, nopen, label
 *
 * List semua versi (active only) di slot dokumen — dari template family
 * via JOIN template_uuid.
 *
 * Response: { success: true, versions: [{ id, version, is_locked, updated_at }, ...] }
 *
 * Note: response backward-compat pakai key `versions` top-level (frontend
 * akses `data.versions`).
 *
 * ## v0.9.9 refactor
 *
 * JOIN query di raw SQL — QueryBuilder MVP belum handle JOIN table lookup
 * pattern ini clean, jadi tetap raw SQL via Connection::fetchAll.
 */

use Ezdoc\Context;
use Ezdoc\Db\Mysqli\MysqliConnection;

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    // Backward-compat: existing frontend expect versions=[] even on error
    ezdoc_respond_raw(['success' => false, 'versions' => []]);
}

$db = new MysqliConnection(Context::default()->db);

$rows = $db->fetchAll(
    'SELECT d.id, d.version, d.is_locked, d.updated_at
     FROM ezdoc_documents d
     INNER JOIN ezdoc_templates t ON t.uuid = d.template_uuid
     WHERE t.id = ? AND d.norm = ? AND d.nopen = ? AND d.label = ? AND d.deleted_at IS NULL
     ORDER BY d.version DESC',
    [$tid, $n, $np, $lb]
);

$versions = array_map(function ($row) {
    return [
        'id'         => (int) $row['id'],
        'version'    => (int) $row['version'],
        'is_locked'  => (int) $row['is_locked'],
        'updated_at' => $row['updated_at'],
    ];
}, $rows);

ezdoc_respond_success(['versions' => $versions]);
