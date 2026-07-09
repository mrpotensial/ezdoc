<?php
/**
 * POST ajax=1 action=toggle_lock
 *   body: template_id, locked (0|1)
 *
 * Toggle is_locked di ezdoc_templates (template lock, bukan document lock).
 *
 * Auth: `ezdoc_require_manage_templates()` — template management global RBAC.
 *
 * Response: { success, locked }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak lock/unlock template');

$tid = (int)($_POST['template_id'] ?? 0);
$locked = (int)($_POST['locked'] ?? 0);
if ($tid <= 0) ezdoc_respond_error('ID tidak valid');

$stmt = mysqli_prepare($conn, "UPDATE ezdoc_templates SET is_locked = ? WHERE id = ?");
mysqli_stmt_bind_param($stmt, "ii", $locked, $tid);
$ok = mysqli_stmt_execute($stmt);

if ($ok) {
    ezdoc_audit_log($locked ? 'template.locked' : 'template.unlocked', [
        'target_type' => 'template',
        'target_id' => (string)$tid,
        'template_id' => $tid,
        'message' => 'Template ' . ($locked ? 'dilock' : 'diunlock'),
    ]);
    ezdoc_respond_raw(['success' => true, 'locked' => $locked]);
} else {
    ezdoc_respond_error('Gagal update: ' . mysqli_error($conn));
}
