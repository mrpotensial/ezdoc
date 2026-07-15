<?php
/**
 * POST action=field_usage, template_id, field_name
 *
 * Hitung berapa dokumen dari template ini yang punya field_values[field_name]
 * dengan value non-empty. Dipakai untuk warning saat rename/hapus field yang
 * masih dipakai dokumen live.
 *
 * Auth: template manager. Response: { success, count, field }
 *
 * ## v0.9.9 refactor — Connection.fetchScalar
 *
 * NOTE: JSON_EXTRACT adalah MySQL-specific. Cross-DB portability tidak
 * di-scope untuk endpoint ini (advanced query — di grammar lain butuh
 * fungsi berbeda: `->>` di Postgres, `json_extract()` di SQLite).
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

ezdoc_require_manage_templates('Tidak berhak inspect field usage');

$tid       = (int) ($_POST['template_id'] ?? 0);
$fieldName = trim($_POST['field_name'] ?? '');

if ($tid <= 0 || $fieldName === '') {
    ezdoc_respond_error('Parameter tidak lengkap');
}

$db = new MysqliConnection($conn);
$count = (int) $db->fetchScalar(
    "SELECT COUNT(*) FROM ezdoc_documents
     WHERE template_id = ?
       AND JSON_EXTRACT(field_values, CONCAT('\$.', ?)) IS NOT NULL
       AND JSON_EXTRACT(field_values, CONCAT('\$.', ?)) != ''",
    [$tid, $fieldName, $fieldName]
);

ezdoc_respond_success(['count' => $count, 'field' => $fieldName]);
