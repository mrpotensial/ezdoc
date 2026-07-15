<?php
/**
 * POST action=delete (non-ajax form submit), body: delete_id
 *
 * Hard-delete template dari ezdoc_templates. Destructive — data dokumen anak
 * TIDAK di-clean (foreign key check tergantung schema).
 *
 * Auth:
 *   - Global template management RBAC
 *   - Additional: superadmin only untuk destructive delete (hardened)
 *
 * Response: redirect ke ?action=list&msg=deleted (form submit behavior).
 *
 * ## v0.9.9 refactor — Connection + TemplateRepository
 */

use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Template\TemplateRepository;

global $conn;

// Guard 1: global template management
ezdoc_require_manage_templates('Tidak berhak modify template');

// Guard 2: destructive — superadmin only
ezdoc_require_role('superadmin', 'Hapus template hanya bisa dilakukan superadmin');

$delete_id = (int)($_POST['delete_id'] ?? 0);
if ($delete_id <= 0) {
    header('Location: ?action=list');
    exit;
}

$db = new MysqliConnection($conn);
$repo = new TemplateRepository($db);

// Fetch name untuk audit log (best-effort)
$tpl = $repo->findById($delete_id);
$templateName = $tpl ? $tpl->getName() : null;

try {
    $db->execute('DELETE FROM ezdoc_templates WHERE id = ?', [$delete_id]);
} catch (\Throwable $e) {
    ezdoc_audit_log('template.deleted', [
        'target_type' => 'template',
        'target_id'   => (string) $delete_id,
        'template_id' => $delete_id,
        'result'      => 'error',
        'message'     => "Gagal delete template #{$delete_id}: " . $e->getMessage(),
    ]);
    header('Location: ?action=list&msg=delete_failed');
    exit;
}

ezdoc_audit_log('template.deleted', [
    'target_type' => 'template',
    'target_id'   => (string) $delete_id,
    'template_id' => $delete_id,
    'metadata'    => ['name' => $templateName],
    'message'     => "Hard delete template #{$delete_id}" . ($templateName ? " ({$templateName})" : ''),
]);

header('Location: ?action=list&msg=deleted');
exit;
