<?php
/**
 * POST ajax=1 action=toggle_lock
 *   body: template_id, locked (0|1)
 *
 * Toggle is_locked di ezdoc_templates. Auth: template manager.
 *
 * Response: { success, locked }
 *
 * ## v0.9.9 refactor — Connection.execute (simple UPDATE)
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

ezdoc_require_manage_templates('Tidak berhak lock/unlock template');

$tid    = (int)($_POST['template_id'] ?? 0);
$locked = (int)($_POST['locked'] ?? 0);
if ($tid <= 0) ezdoc_respond_error(t('response.invalid_id', [], 'Invalid ID'));

$db = new MysqliConnection($conn);

try {
    $db->execute('UPDATE ezdoc_templates SET is_locked = ? WHERE id = ?', [$locked, $tid]);
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.update_failed', ['error' => $e->getMessage()], 'Failed to update: {error}'));
}

ezdoc_audit_log($locked ? 'template.locked' : 'template.unlocked', [
    'target_type' => 'template',
    'target_id'   => (string) $tid,
    'template_id' => $tid,
    'message'     => 'Template ' . ($locked ? 'dilock' : 'diunlock'),
]);

ezdoc_respond_raw(['success' => true, 'locked' => $locked]);
