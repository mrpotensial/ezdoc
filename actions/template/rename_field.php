<?php
/**
 * POST action=rename_field, template_id, old_name, new_name
 *
 * Rename field key di SEMUA dokumen milik template ini:
 *   field_values[old_name] → field_values[new_name]
 *
 * Guard: kalau new_name sudah ada value non-empty di dokumen tertentu, SKIP
 * overwrite (biar tidak data loss). Old key tetap di-unset.
 *
 * Auth: template manager. Audit log.
 * Response: { success, updated, skipped, message }
 *
 * ## v0.9.9 refactor — bulk UPDATE dalam transaction
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

ezdoc_require_manage_templates('Tidak berhak rename field');

$tid     = (int) ($_POST['template_id'] ?? 0);
$oldName = trim($_POST['old_name'] ?? '');
$newName = trim($_POST['new_name'] ?? '');

if ($tid <= 0 || $oldName === '' || $newName === '' || $oldName === $newName) {
    ezdoc_respond_error(t('response.invalid_parameters', [], 'Invalid parameters'));
}

if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $newName)) {
    ezdoc_respond_error(t('response.new_name_invalid_chars', [], 'New name must be alphanumeric, underscore, or hyphen'));
}

$db = new MysqliConnection($conn);
$rows = $db->fetchAll(
    'SELECT id, field_values FROM ezdoc_documents WHERE template_id = ?',
    [$tid]
);

$updated = 0;
$skipped = 0;

try {
    $db->transaction(function () use ($db, $rows, $oldName, $newName, &$updated, &$skipped) {
        foreach ($rows as $row) {
            $fields = json_decode($row['field_values'] ?: '{}', true) ?: [];
            if (!array_key_exists($oldName, $fields)) continue;

            $newHasValue = array_key_exists($newName, $fields)
                        && $fields[$newName] !== ''
                        && $fields[$newName] !== null;

            if (!$newHasValue) {
                $fields[$newName] = $fields[$oldName];
            } else {
                $skipped++;
            }
            unset($fields[$oldName]);

            $db->execute(
                'UPDATE ezdoc_documents SET field_values = ? WHERE id = ?',
                [json_encode($fields), $row['id']]
            );
            $updated++;
        }
    });
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.rename_failed', ['error' => $e->getMessage()], 'Rename failed (rolled back): {error}'));
}

ezdoc_audit_log('template.field_renamed', [
    'target_type' => 'template',
    'target_id'   => (string) $tid,
    'template_id' => $tid,
    'metadata'    => [
        'old_name'          => $oldName,
        'new_name'          => $newName,
        'updated_docs'      => $updated,
        'skipped_conflicts' => $skipped,
    ],
    'message' => "Rename field '{$oldName}' → '{$newName}' di template #{$tid} ({$updated} docs updated)",
]);

if ($skipped > 0) {
    $msg = t('response.field_renamed_with_skips', [
        'updated'  => $updated,
        'skipped'  => $skipped,
        'new_name' => $newName,
    ], "{updated} document(s) updated ({skipped} skipped because '{new_name}' already has a value)");
} else {
    $msg = t('response.field_renamed', ['updated' => $updated], '{updated} document(s) updated');
}

ezdoc_respond_success(['updated' => $updated, 'skipped' => $skipped], $msg);
