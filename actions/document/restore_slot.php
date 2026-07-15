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

use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Template\TemplateRepository;

global $conn;

ezdoc_require_role('superadmin', 'Restore hanya bisa dilakukan superadmin.');

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error('Parameter tidak lengkap');
}

$db = new MysqliConnection($conn);
$tplRepo = new TemplateRepository($db);

// Resolve template_id → uuid (untuk slot query lintas template version)
$tpl = $tplRepo->findById($tid);
if ($tpl === null) ezdoc_respond_error('Template tidak ditemukan');
$templateUuid = $tpl->getUuid();

try {
    $affected = $db->execute(
        'UPDATE ezdoc_documents SET deleted_at = NULL, deleted_by = NULL
         WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?
           AND deleted_at IS NOT NULL',
        [$templateUuid, $n, $np, $lb]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error('Gagal restore: ' . $e->getMessage());
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

ezdoc_respond_success(['affected' => $affected], "Slot berhasil di-restore ({$affected} versi)");
