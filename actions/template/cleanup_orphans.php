<?php
/**
 * POST action=cleanup_orphans, template_id, valid_fields (CSV)
 *
 * Hapus key dari field_values yang bukan lagi bagian dari template's fieldNames.
 * "Orphan" = key di dokumen tapi field-nya sudah di-remove dari template design.
 *
 * PRESERVE: key yang dimulai dengan `_ttd_` — signature slot state, jangan touch.
 *
 * Auth: template manager. Audit log (destructive op).
 * Response: { success, updated: <int>, removedKeys: [<key>, ...] }
 *
 * ## v0.9.9 refactor — Connection.fetchAll + execute in loop
 *
 * Bulk update loop wrapped in transaction untuk atomicity — kalau ada 1
 * UPDATE gagal, rollback semua (bukan partial state).
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

ezdoc_require_manage_templates('Tidak berhak cleanup field orphans');

$tid = (int) ($_POST['template_id'] ?? 0);
$validFieldsCsv = trim($_POST['valid_fields'] ?? '');

if ($tid <= 0) ezdoc_respond_error('ID template tidak valid');

$validFields = array_filter(array_map('trim', explode(',', $validFieldsCsv)));
$validSet = array_flip($validFields);

$db = new MysqliConnection($conn);
$rows = $db->fetchAll(
    'SELECT id, field_values FROM ezdoc_documents WHERE template_id = ?',
    [$tid]
);

$updated = 0;
$removedKeys = [];

try {
    $db->transaction(function () use ($db, $rows, $validSet, &$updated, &$removedKeys) {
        foreach ($rows as $row) {
            $fields = json_decode($row['field_values'] ?: '{}', true) ?: [];
            $changed = false;
            foreach (array_keys($fields) as $k) {
                if (strpos($k, '_ttd_') === 0) continue; // preserve TTD state
                if (isset($validSet[$k])) continue;      // valid field, keep
                unset($fields[$k]);
                $removedKeys[$k] = true;
                $changed = true;
            }
            if ($changed) {
                $db->execute(
                    'UPDATE ezdoc_documents SET field_values = ? WHERE id = ?',
                    [json_encode($fields), $row['id']]
                );
                $updated++;
            }
        }
    });
} catch (\Throwable $e) {
    ezdoc_respond_error('Cleanup gagal (rollback): ' . $e->getMessage());
}

$removedList = array_keys($removedKeys);

ezdoc_audit_log('template.orphans_cleaned', [
    'target_type' => 'template',
    'target_id'   => (string) $tid,
    'template_id' => $tid,
    'metadata'    => [
        'valid_fields' => $validFields,
        'removed_keys' => $removedList,
        'updated_docs' => $updated,
    ],
    'message' => "Cleanup " . count($removedList) . " orphan field(s) di template #{$tid} ({$updated} docs updated)",
]);

ezdoc_respond_success([
    'updated'     => $updated,
    'removedKeys' => $removedList,
], "{$updated} dokumen dibersihkan, " . count($removedList) . " field orphan dihapus");
