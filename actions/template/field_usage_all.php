<?php
/**
 * POST action=field_usage_all, template_id
 *
 * Bulk field usage: scan semua dokumen template ini, agregat berapa dokumen
 * yang punya value non-empty per field key. Dipakai untuk "orphan cleanup"
 * preview di designer.
 *
 * Auth: template manager.
 * Response: { success, totalDocs, fieldCounts: { <field>: <count>, ... } }
 *
 * ## v0.9.9 refactor — Connection.fetchAll + PHP-side aggregation
 *
 * Kita aggregate di PHP (bukan SQL) supaya portable — beda grammar (MySQL vs
 * Postgres vs SQLite) punya JSON function syntax berbeda.
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

ezdoc_require_manage_templates('Tidak berhak scan field usage');

$tid = (int) ($_POST['template_id'] ?? 0);
if ($tid <= 0) ezdoc_respond_error(t('response.invalid_template_id', [], 'Invalid template ID'));

$db = new MysqliConnection($conn);
$rows = $db->fetchAll(
    'SELECT field_values FROM ezdoc_documents WHERE template_id = ?',
    [$tid]
);

$fieldCounts = [];
$totalDocs = count($rows);
foreach ($rows as $row) {
    $fields = json_decode($row['field_values'] ?: '{}', true) ?: [];
    foreach ($fields as $k => $v) {
        if ($v !== '' && $v !== null) {
            $fieldCounts[$k] = ($fieldCounts[$k] ?? 0) + 1;
        }
    }
}

ezdoc_respond_success([
    'totalDocs'   => $totalDocs,
    'fieldCounts' => $fieldCounts,
]);
