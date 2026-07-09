<?php
/**
 * POST /page/form_pembuat_surat_cetak_v3.php  (body: _ajax=1, template_id, _norm, _nopen, _label, _doc_id, dan field-field data)
 *
 * Save/update dokumen ke `ezdoc_documents`. Kalau doc sudah ada di slot
 * (template_uuid, norm, nopen, label), update latest version. Kalau belum, insert baru
 * dengan version=1.
 *
 * Level 2/3 verify: setelah save sukses, auto-generate slug + compute data hash.
 *
 * Auth: any authenticated user (koneksi.php gate) + RBAC per-template.
 *
 * Response (backward-compat dengan existing frontend):
 *   {
 *     success: true, message: "...", doc_id, isEdit, label,
 *     verify_url, data_hash, data_hash_at
 *   }
 */

global $conn, $author_id;

$template_id = (int)($_POST['template_id'] ?? 0);
$norm  = trim($_POST['_norm']  ?? '');
$nopen = trim($_POST['_nopen'] ?? '');
$label = trim($_POST['_label'] ?? '-');
if ($label === '') $label = '-';
$doc_id = (int)($_POST['_doc_id'] ?? 0);

// ─── Load template dari ezdoc_templates ───
// Pakai column baru: name→was nama_template, scope→was doc_scope, content→was template_html,
// signature_config→was config_ttd. Semua JSON native.
$stmt = mysqli_prepare($conn, "SELECT uuid, version, content, signature_config, scope, access_config FROM ezdoc_templates WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $template_id);
mysqli_stmt_execute($stmt);
$tpl = mysqli_stmt_get_result($stmt)->fetch_assoc();

if (!$tpl) {
    ezdoc_respond_error('Template tidak ditemukan');
}

$templateUuid = (string)$tpl['uuid'];
$templateVersion = (int)($tpl['version'] ?? 1);

// ─── RBAC: template-level access check ───
$accessConfig = ezdoc_parse_access_config($tpl['access_config'] ?? null);
$rbacAction = ($doc_id > 0) ? 'edit' : 'create';

@error_log(sprintf(
    '[ezdoc:save] user=%d roles=%s template=%d action=%s access_config=%s',
    ezdoc_current_user_id(),
    implode(',', ezdoc_current_user_roles()),
    (int)$template_id,
    $rbacAction,
    $accessConfig ? json_encode($accessConfig) : 'null'
));

ezdoc_require_on_template($accessConfig, $rbacAction, "Tidak berhak {$rbacAction} dokumen dari template ini");

$tplDocScope = $tpl['scope'] ?? 'patient';
if ($tplDocScope === 'patient' && ($norm === '' || $nopen === '')) {
    ezdoc_respond_error('No RM dan No Pendaftaran wajib diisi');
}

$templateHtml = $tpl['content'] ?: '';
$configTtdRaw = json_decode($tpl['signature_config'] ?: '[]', true) ?: [];

// ─── Build configTtd (support both placeholder & legacy format) ───
$hasTtdPlaceholders = strpos($templateHtml, 'ttd-placeholder') !== false;
$configTtd = [];
if ($hasTtdPlaceholders) {
    preg_match_all('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>/s', $templateHtml, $ttdMatches, PREG_SET_ORDER);
    foreach ($ttdMatches as $m) {
        $attrs = $m[1];
        preg_match('/data-ttd="([^"]+)"/', $attrs, $tm);
        $ttdId = $tm[1] ?? '';
        if (!$ttdId) continue;
        preg_match('/data-label="([^"]+)"/', $attrs, $lm);
        preg_match('/data-nama-field="([^"]+)"/', $attrs, $nm);
        preg_match('/data-allowed-roles="([^"]*)"/', $attrs, $arm);
        preg_match('/data-allowed-users="([^"]*)"/', $attrs, $aum);
        $configTtd[] = [
            'id' => $ttdId,
            'label' => $lm[1] ?? 'Tanda Tangan',
            'nama_field' => $nm[1] ?? 'nama_' . $ttdId,
            'rbac' => ezdoc_parse_ttd_config($arm[1] ?? '', $aum[1] ?? ''),
        ];
    }
} else {
    $configTtd = array_map(fn($t) => $t + ['rbac' => ['roles' => [], 'users' => []]], $configTtdRaw);
}

// ─── Collect all fields from template ───
$allFields = [];
preg_match_all('/\{\{([^}]+)\}\}/', $templateHtml, $matches);
foreach ($matches[1] as $fn) $allFields[$fn] = true;

foreach ($configTtd as $ttd) {
    $nf = $ttd['nama_field'] ?? '';
    if ($nf) $allFields[$nf] = true;
}

preg_match_all('/data-qr="([^"]+)"/', $templateHtml, $qrMatches);
foreach ($qrMatches[1] as $qrField) $allFields[$qrField] = true;

$fieldData = [];
foreach ($allFields as $fn => $v) {
    $fieldData[$fn] = $_POST[$fn] ?? '';
}

// ─── TTD custom fields (mode + per-doc QR content) ───
foreach ($configTtd as $ttd) {
    $tid = $ttd['id'];
    $nf = $ttd['nama_field'] ?? ('nama_' . $tid);
    $keys = ['_ttd_mode_' . $tid, $nf . '_qr'];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) $fieldData[$k] = $_POST[$k];
    }
}

// ─── Materai per-document fields ───
preg_match_all('/<div[^>]*class="[^"]*materai-placeholder[^"]*"[^>]*data-materai="([^"]+)"[^>]*>/s', $templateHtml, $matMatches);
$validMateraiIds = array_unique($matMatches[1] ?? []);
foreach ($validMateraiIds as $mid) {
    $imgKey    = '_materai_' . $mid . '_image';
    $serialKey = '_materai_' . $mid . '_serial';
    $uploadKey = '_materai_' . $mid . '_uploaded_at';

    if (isset($_POST[$imgKey])) {
        $val = (string)$_POST[$imgKey];
        $fieldData[$imgKey] = ($val !== '' && preg_match('#^data:image/(png|jpe?g|gif);base64,#', $val)) ? $val : '';
    }
    if (isset($_POST[$serialKey])) {
        $serial = trim((string)$_POST[$serialKey]);
        if (mb_strlen($serial) > 30) $serial = mb_substr($serial, 0, 30);
        $fieldData[$serialKey] = $serial;
    }
    if (isset($_POST[$uploadKey])) {
        $ua = trim((string)$_POST[$uploadKey]);
        $fieldData[$uploadKey] = ($ua !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ua)) ? $ua : '';
    }
}

// ─── TTD signatures — dengan per-TTD RBAC enforcement ───
$ttdData = [];
$accessMode = ezdoc_access_mode($accessConfig);
foreach ($configTtd as $ttd) {
    $tid = $ttd['id'];
    $tv = $_POST[$tid] ?? '';
    if (!$tv || !preg_match('#^data:image/(png|jpe?g|gif);base64,#', $tv)) continue;

    $ttdRbac = $ttd['rbac'] ?? ['roles' => [], 'users' => []];
    if (!ezdoc_can_sign_ttd($ttdRbac)) {
        if ($accessMode === 'strict') {
            $ttdLabel = $ttd['label'] ?? $tid;
            ezdoc_respond_error("Tidak berhak menandatangani sebagai \"{$ttdLabel}\"", 403, [
                'ttd_id' => $tid,
                'required_roles' => $ttdRbac['roles'] ?? [],
            ]);
        } else {
            @error_log(sprintf(
                '[ezdoc] Permissive: user %d bypassed TTD "%s" (required roles: %s)',
                ezdoc_current_user_id(),
                $ttd['label'] ?? $tid,
                implode(',', $ttdRbac['roles'] ?? [])
            ));
            continue;
        }
    }

    $ttdData[$tid] = $tv;
}

$jsonFields = json_encode($fieldData, JSON_UNESCAPED_UNICODE);
$jsonTtd = json_encode($ttdData, JSON_UNESCAPED_UNICODE);
$authorId = (int)($author_id ?? 0);

// ─── Check exists & lock status ───
$isEdit = false;
$existingLocked = 0;
if ($doc_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, is_locked FROM ezdoc_documents WHERE id = ? AND deleted_at IS NULL");
    mysqli_stmt_bind_param($stmt, "i", $doc_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt)->fetch_assoc();
    if ($r) { $isEdit = true; $existingLocked = (int)$r['is_locked']; }
}

if (!$isEdit) {
    // Find latest version in slot (search by template_uuid supaya lintas template version)
    $stmt = mysqli_prepare($conn, "SELECT id, is_locked FROM ezdoc_documents WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ? AND deleted_at IS NULL ORDER BY version DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ssss", $templateUuid, $norm, $nopen, $label);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    if ($row) { $doc_id = (int)$row['id']; $isEdit = true; $existingLocked = (int)$row['is_locked']; }
}

// Reject kalau locked
if ($isEdit && $existingLocked) {
    ezdoc_respond_error('Dokumen ini locked dan tidak bisa diedit. Unlock dulu atau buat versi baru.');
}

// ─── Save (update / insert) ───
if ($isEdit) {
    // UPDATE — no need to touch uuid, template_uuid, template_version (immutable per doc)
    $stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET norm=?, nopen=?, label=?, field_values=?, signature_values=?, updated_by=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, "sssssii", $norm, $nopen, $label, $jsonFields, $jsonTtd, $authorId, $doc_id);
} else {
    // INSERT — generate UUID, populate template snapshot, set default status = 'published'
    $docUuid = ezdoc_uuid_v7();
    $status = 'published';
    $publishedAt = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "
        INSERT INTO ezdoc_documents
        (uuid, template_id, template_uuid, template_version,
         norm, nopen, label, version,
         field_values, signature_values,
         status, published_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param(
        $stmt,
        "sisisssssssi",
        $docUuid, $template_id, $templateUuid, $templateVersion,
        $norm, $nopen, $label,
        $jsonFields, $jsonTtd,
        $status, $publishedAt, $authorId
    );
}

if (!mysqli_stmt_execute($stmt)) {
    ezdoc_respond_error('Gagal: ' . mysqli_error($conn));
}

if (!$isEdit) $doc_id = mysqli_insert_id($conn);

// ─── Post-save: verify slug + data hash ───
// doc_verify_helpers.php akan diupdate untuk pakai ezdoc_documents di next commit.
$verifyUrl = '';
if (function_exists('doc_verify_ensure_slug') && function_exists('doc_verify_build_url')) {
    $verifySlug = doc_verify_ensure_slug($conn, (int)$doc_id);
    if ($verifySlug) $verifyUrl = doc_verify_build_url($verifySlug);
}

$hashInfo = null;
if (function_exists('doc_verify_compute_and_store_hash')) {
    $hashInfo = doc_verify_compute_and_store_hash($conn, (int)$doc_id);
}

// Audit log
ezdoc_audit_log($isEdit ? 'doc.updated' : 'doc.created', [
    'target_type' => 'document',
    'target_id' => (string)$doc_id,
    'template_id' => (int)$template_id,
    'doc_id' => (int)$doc_id,
    'metadata' => [
        'norm' => $norm,
        'nopen' => $nopen,
        'label' => $label,
        'ttd_count' => count($ttdData),
        'data_hash' => $hashInfo['hash'] ?? null,
        'template_uuid' => $templateUuid,
        'template_version' => $templateVersion,
    ],
    'message' => $isEdit
        ? "Update dokumen (template {$template_id}, norm {$norm}, label {$label})"
        : "Buat dokumen baru (template {$template_id}, norm {$norm}, label {$label})",
]);

ezdoc_respond_success([
    'doc_id' => $doc_id,
    'isEdit' => $isEdit,
    'label' => $label,
    'verify_url' => $verifyUrl,
    'data_hash' => $hashInfo['hash'] ?? null,
    'data_hash_at' => $hashInfo['hash_at'] ?? null,
], 'Dokumen berhasil disimpan');
