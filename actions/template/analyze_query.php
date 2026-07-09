<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=analyze_query, query)
 *
 * Analisa SELECT query untuk dynamic table builder di designer:
 *   - Validasi hanya SELECT/WITH allowed (block DDL/DML)
 *   - Replace {param} placeholder → '' agar syntax valid
 *   - Execute dengan LIMIT 0 (dapat metadata kolom tanpa fetch data)
 *   - Return list kolom (name+type) + list param yang dipakai
 *
 * Auth: template manager (designer-only tool).
 *
 * Response:
 *   { success: true, columns: [{name, type}, ...], params: [<name>, ...] }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak menganalisa query template');

$query = trim($_POST['query'] ?? '');
if ($query === '') {
    ezdoc_respond_error('Query kosong');
}

// Block non-SELECT statements — first-token check
$normalized = preg_replace('/\s+/', ' ', strtoupper(ltrim($query)));
$blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE'];
foreach ($blocked as $kw) {
    if (strpos($normalized, $kw) === 0) {
        ezdoc_respond_error("Hanya query SELECT yang diizinkan (terdeteksi: {$kw})");
    }
}
if (strpos($normalized, 'SELECT') !== 0 && strpos($normalized, 'WITH') !== 0) {
    ezdoc_respond_error('Query harus diawali SELECT atau WITH');
}

// Replace {param} placeholders with empty string so syntax is valid at LIMIT-0 probe
$safeQuery = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', "''", $query);
$safeQuery = rtrim($safeQuery, "; \t\n\r") . ' LIMIT 0';

$result = @mysqli_query($conn, $safeQuery);
if (!$result) {
    ezdoc_respond_error('Query error: ' . mysqli_error($conn));
}

$columns = [];
$fieldCount = mysqli_num_fields($result);
for ($i = 0; $i < $fieldCount; $i++) {
    $info = mysqli_fetch_field_direct($result, $i);
    $columns[] = ['name' => $info->name, 'type' => $info->type];
}
mysqli_free_result($result);

// Detect params referenced in original query
preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $query, $paramMatches);
$params = array_values(array_unique($paramMatches[1]));

ezdoc_respond_success([
    'columns' => $columns,
    'params' => $params,
]);
