<?php
/**
 * POST _doc_action=delete_slot
 *   body: template_id, norm, nopen, label
 *
 * Soft-delete SEMUA versi di slot (template_uuid, norm, nopen, label).
 * Guard: superadmin (kecuali access_config.delete di-set), refuse kalau ada
 * versi locked (harus unlock dulu manual).
 *
 * Response: { success, affected: <count> }
 *
 * ## v0.9.9 refactor
 *
 * Persistence lewat Connection. Template lookup pakai fetchOne.
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

use Ezdoc\Context;

global $author_id;

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error(t('response.incomplete_parameters', [], 'Incomplete parameters'));
}

$db = new MysqliConnection(Context::default()->db);

// Resolve template_id → uuid + access_config
$tpl = $db->fetchOne(
    'SELECT uuid, access_config FROM ezdoc_templates WHERE id = ? LIMIT 1',
    [$tid]
);
if (!$tpl) ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'));
$templateUuid = (string) $tpl['uuid'];
$accessConfig = ezdoc_parse_access_config($tpl['access_config'] ?? null);

// RBAC delete check
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
$lockedCnt = (int) $db->fetchScalar(
    'SELECT COUNT(*) FROM ezdoc_documents
     WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?
       AND is_locked = 1 AND deleted_at IS NULL',
    [$templateUuid, $n, $np, $lb]
);
if ($lockedCnt > 0) {
    ezdoc_respond_error(t('response.slot_has_locked_versions', ['count' => $lockedCnt], 'Slot has {count} locked version(s). Unlock them first before deleting.'));
}

// Soft delete semua active version di slot
$author = (string) ($author_id ?? 'system');
try {
    $affected = $db->execute(
        'UPDATE ezdoc_documents SET deleted_at = NOW(), deleted_by = ?
         WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?
           AND deleted_at IS NULL',
        [$author, $templateUuid, $n, $np, $lb]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.delete_slot_failed', ['error' => $e->getMessage()], 'Failed to delete slot: {error}'));
}

ezdoc_audit_log('doc.deleted', [
    'target_type' => 'document',
    'target_id'   => "slot:tpl{$tid}:{$n}:{$np}:{$lb}",
    'template_id' => $tid,
    'metadata'    => [
        'scope'             => 'slot',
        'norm'              => $n,
        'nopen'             => $np,
        'label'             => $lb,
        'affected_versions' => $affected,
        'template_uuid'     => $templateUuid,
    ],
    'message' => "Soft delete slot dokumen ({$affected} versi, norm {$n}, label {$lb})",
]);

ezdoc_respond_success(['affected' => $affected], t('response.slot_deleted', ['count' => $affected], 'Slot deleted successfully ({count} version(s))'));
