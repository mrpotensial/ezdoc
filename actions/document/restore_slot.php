<?php
/**
 * POST _doc_action=restore_slot
 *   body: template_id, norm, nopen, label
 *
 * Restore soft-deleted slot (semua versi di dalamnya).
 * Guard: superadmin only.
 *
 * Response: { success, affected: <count> }
 *
 * ## v0.9.9 refactor
 *
 * Template UUID lookup via TemplateRepository. Bulk UPDATE via Connection.
 */

use Ezdoc\Context;
use Ezdoc\Template\TemplateRepository;

ezdoc_require_role('superadmin', 'Restore hanya bisa dilakukan superadmin.');

$tid = (int)($_POST['template_id'] ?? 0);
// Menerima subject_id/context_id maupun nama lamanya — lihat ezdoc_slot_identity().
[$n, $np] = ezdoc_slot_identity($_POST);
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error(t('response.incomplete_parameters', [], 'Incomplete parameters'));
}

$db = Context::default()->connection();
$tplRepo = new TemplateRepository($db);

// Resolve template_id → uuid (untuk slot query lintas template version)
$tpl = $tplRepo->findById($tid);
if ($tpl === null) ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'));
$templateUuid = $tpl->getUuid();

try {
    $affected = $db->execute(
        'UPDATE ezdoc_documents SET deleted_at = NULL, deleted_by = NULL
         WHERE template_uuid = ? AND subject_id = ? AND context_id = ? AND label = ?
           AND deleted_at IS NOT NULL',
        [$templateUuid, $n, $np, $lb]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.restore_failed', ['error' => $e->getMessage()], 'Failed to restore: {error}'));
}

ezdoc_audit_log('doc.restored', [
    'target_type' => 'document',
    'target_id'   => "slot:tpl{$tid}:{$n}:{$np}:{$lb}",
    'template_id' => $tid,
    'metadata'    => [
        'scope'             => 'slot',
        'norm'              => $n,
        'nopen'             => $np,
        'label'             => $lb,
        'affected_versions' => $affected,
    ],
    'message' => "Restore slot dokumen ({$affected} versi, norm {$n}, label {$lb})",
]);

ezdoc_respond_success(['affected' => $affected], t('response.slot_restored', ['count' => $affected], 'Slot restored successfully ({count} version(s))'));
