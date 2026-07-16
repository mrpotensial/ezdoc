<?php
/**
 * POST _ajax=1 template_id, _norm, _nopen, _label, _doc_id, <field data>
 *
 * Save/update dokumen ke ezdoc_documents. Kalau doc sudah ada di slot
 * (template_uuid, norm, nopen, label), update latest version. Kalau belum,
 * insert baru dgn version=1.
 *
 * Level 2/3 verify: setelah save sukses, auto-generate slug + compute data hash.
 *
 * Auth: authenticated user + RBAC per-template (create/edit) + per-TTD sign.
 *
 * Response (backward-compat dgn existing frontend):
 *   { success, message, doc_id, isEdit, label, verify_url, data_hash, data_hash_at, debug }
 *
 * ## v0.9.9 refactor
 *
 * Persistence via `Ezdoc\Db\Connection` (via MysqliConnection auto-wrap).
 * Template lookup via `TemplateRepository`. Per-doc lookup via
 * `DocumentRepository::findById`. Slot latest-version lookup masih raw SQL
 * (query pattern belum ideal untuk Repository API).
 *
 * Business logic (template parsing, field collection, TTD RBAC enforcement)
 * tetap disini — di W4.1 belum di-extract ke Service (defer v0.9.10 kalau
 * ada refactor pattern jelas).
 */

use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Template\TemplateRepository;

use Ezdoc\Context;

global $author_id;

$template_id = (int)($_POST['template_id'] ?? 0);
$norm  = trim($_POST['_norm']  ?? '');
$nopen = trim($_POST['_nopen'] ?? '');
$label = trim($_POST['_label'] ?? '-');
if ($label === '') $label = '-';
$doc_id = (int)($_POST['_doc_id'] ?? 0);

$db = new MysqliConnection(Context::default()->db);
$tplRepo = new TemplateRepository($db);

// ─── Load template (include name utk auto-computed doc title) ───
$tpl = $tplRepo->findById($template_id);
if (!$tpl) ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'));

$templateUuid    = $tpl->getUuid();
$templateVersion = $tpl->getVersion();
$templateName    = $tpl->getName() ?: 'Untitled Template';
$templateHtml    = $tpl->getContent() ?: '';
$configTtdRaw    = $tpl->getSignatureConfig();
$tplDocScope     = $tpl->getScope();

// ─── RBAC: template-level access check ───
// Template::getAccessConfig() sudah return decoded array (bukan raw string),
// jadi TIDAK perlu ezdoc_parse_access_config() (yg expect string).
// null-safe: empty array = allow-all (v2 behavior, sama dgn parse hasil null).
$rawAccess = $tpl->getAccessConfig();
$accessConfig = $rawAccess === [] ? null : $rawAccess;
$rbacAction = ($doc_id > 0) ? 'edit' : 'create';

@error_log(sprintf(
    '[ezdoc:save] user=%d roles=%s template=%d action=%s access_config=%s',
    ezdoc_current_user_id(),
    implode(',', ezdoc_current_user_roles()),
    (int) $template_id,
    $rbacAction,
    $accessConfig ? json_encode($accessConfig) : 'null'
));

ezdoc_require_on_template($accessConfig, $rbacAction, "Tidak berhak {$rbacAction} dokumen dari template ini");

if ($tplDocScope === 'patient' && ($norm === '' || $nopen === '')) {
    ezdoc_respond_error(t('response.patient_identity_required', [], 'Medical record number and registration number are required'));
}

// ─── Build configTtd (support placeholder + legacy format) ───
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
            'id'         => $ttdId,
            'label'      => $lm[1] ?? 'Tanda Tangan',
            'nama_field' => $nm[1] ?? 'nama_' . $ttdId,
            'rbac'       => ezdoc_parse_ttd_config($arm[1] ?? '', $aum[1] ?? ''),
        ];
    }
} else {
    $configTtd = array_map(function ($t) { return $t + ['rbac' => ['roles' => [], 'users' => []]]; }, $configTtdRaw);
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

// Diagnostic bag (F12 Console visibility)
$__ezdocSaveDebug = [
    'allFields_keys'     => array_keys($allFields),
    'POST_keys'          => array_keys($_POST ?? []),
    'collected_nonempty' => array_keys(array_filter($fieldData, function ($v) { return $v !== ''; })),
    'field_values_count' => count(array_filter($fieldData, function ($v) { return $v !== ''; })),
];

// ─── TTD custom fields (mode + per-doc QR content) ───
foreach ($configTtd as $ttd) {
    $tid = $ttd['id'];
    $nf  = $ttd['nama_field'] ?? ('nama_' . $tid);
    foreach (['_ttd_mode_' . $tid, $nf . '_qr'] as $k) {
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
        $val = (string) $_POST[$imgKey];
        $fieldData[$imgKey] = ($val !== '' && preg_match('#^data:image/(png|jpe?g|gif);base64,#', $val)) ? $val : '';
    }
    if (isset($_POST[$serialKey])) {
        $serial = trim((string) $_POST[$serialKey]);
        if (mb_strlen($serial) > 30) $serial = mb_substr($serial, 0, 30);
        $fieldData[$serialKey] = $serial;
    }
    if (isset($_POST[$uploadKey])) {
        $ua = trim((string) $_POST[$uploadKey]);
        $fieldData[$uploadKey] = ($ua !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ua)) ? $ua : '';
    }
}

// ─── TTD signatures dgn per-TTD RBAC enforcement ───
$ttdData = [];
$accessMode = ezdoc_access_mode($accessConfig);
foreach ($configTtd as $ttd) {
    $tid = $ttd['id'];
    $tv  = $_POST[$tid] ?? '';
    if (!$tv || !preg_match('#^data:image/(png|jpe?g|gif);base64,#', $tv)) continue;

    $ttdRbac = $ttd['rbac'] ?? ['roles' => [], 'users' => []];
    if (!ezdoc_can_sign_ttd($ttdRbac)) {
        if ($accessMode === 'strict') {
            $ttdLabel = $ttd['label'] ?? $tid;
            ezdoc_respond_error(t('response.ttd_sign_forbidden', ['label' => $ttdLabel], 'Not authorized to sign as "{label}"'), 403, [
                'ttd_id'         => $tid,
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
$jsonTtd    = json_encode($ttdData, JSON_UNESCAPED_UNICODE);
$authorId   = (int) ($author_id ?? 0);

// ─── Check exists & lock status ───
$isEdit = false;
$existingLocked = 0;
if ($doc_id > 0) {
    $r = $db->fetchOne(
        'SELECT id, is_locked FROM ezdoc_documents WHERE id = ? AND deleted_at IS NULL',
        [$doc_id]
    );
    if ($r) { $isEdit = true; $existingLocked = (int) $r['is_locked']; }
}

if (!$isEdit) {
    // Find latest version in slot — search by template_uuid untuk lintas template version
    $row = $db->fetchOne(
        'SELECT id, is_locked FROM ezdoc_documents
         WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ? AND deleted_at IS NULL
         ORDER BY version DESC LIMIT 1',
        [$templateUuid, $norm, $nopen, $label]
    );
    if ($row) { $doc_id = (int) $row['id']; $isEdit = true; $existingLocked = (int) $row['is_locked']; }
}

if ($isEdit && $existingLocked) {
    ezdoc_respond_error(t('response.document_locked_cannot_edit', [], 'This document is locked and cannot be edited. Unlock it first or create a new version.'));
}

// Auto-compute document title (Filament/Nova pattern)
$computedTitle = trim((string) ($_POST['title'] ?? ''));
if ($computedTitle === '') {
    if ($tplDocScope === 'patient' && ($norm !== '' || $nopen !== '')) {
        $computedTitle = $templateName . ' — ' . trim($norm . '/' . $nopen, '/');
    } elseif ($label !== '' && $label !== '-') {
        $computedTitle = $templateName . ' (' . $label . ')';
    } else {
        $computedTitle = $templateName;
    }
    if (mb_strlen($computedTitle) > 255) $computedTitle = mb_substr($computedTitle, 0, 255);
}

// ─── Save (update / insert) via Connection ───
try {
    if ($isEdit) {
        // UPDATE — uuid, template_uuid, template_version immutable per doc
        $db->execute(
            'UPDATE ezdoc_documents SET title = ?, norm = ?, nopen = ?, label = ?,
                field_values = ?, signature_values = ?, updated_by = ?
             WHERE id = ?',
            [$computedTitle, $norm, $nopen, $label, $jsonFields, $jsonTtd, $authorId, $doc_id]
        );
    } else {
        $docUuid = ezdoc_uuid_v7();
        $publishedAt = date('Y-m-d H:i:s');
        $db->execute(
            'INSERT INTO ezdoc_documents
             (uuid, template_id, template_uuid, template_version,
              title, norm, nopen, label, version,
              field_values, signature_values,
              status, published_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
            [
                $docUuid, $template_id, $templateUuid, $templateVersion,
                $computedTitle, $norm, $nopen, $label,
                $jsonFields, $jsonTtd,
                'published', $publishedAt, $authorId,
            ]
        );
        $doc_id = (int) $db->lastInsertId();
    }
} catch (\Throwable $e) {
    @error_log(sprintf(
        '[ezdoc:save] FAILED template_id=%d norm=%s nopen=%s label=%s isEdit=%s err=%s',
        $template_id, $norm, $nopen, $label, $isEdit ? 'yes' : 'no', $e->getMessage()
    ));
    ezdoc_respond_error(t('response.save_failed', ['error' => $e->getMessage()], 'Failed: {error}'), 500);
}

@error_log(sprintf(
    '[ezdoc:save] SAVED doc_id=%d template_id=%d template_uuid=%s norm=[%s] nopen=[%s] label=[%s] isEdit=%s',
    $doc_id, $template_id, $templateUuid, $norm, $nopen, $label, $isEdit ? 'yes' : 'no'
));

// ─── Post-save: verify slug + data hash ───
$verifyUrl = '';
if (function_exists('doc_verify_ensure_slug') && function_exists('doc_verify_build_url')) {
    $verifySlug = doc_verify_ensure_slug($conn, (int) $doc_id);
    if ($verifySlug) $verifyUrl = doc_verify_build_url($verifySlug);
}

$hashInfo = null;
if (function_exists('doc_verify_compute_and_store_hash')) {
    $hashInfo = doc_verify_compute_and_store_hash($conn, (int) $doc_id);
}

ezdoc_audit_log($isEdit ? 'doc.updated' : 'doc.created', [
    'target_type' => 'document',
    'target_id'   => (string) $doc_id,
    'template_id' => (int) $template_id,
    'doc_id'      => (int) $doc_id,
    'metadata'    => [
        'norm'             => $norm,
        'nopen'            => $nopen,
        'label'            => $label,
        'ttd_count'        => count($ttdData),
        'data_hash'        => $hashInfo['hash'] ?? null,
        'template_uuid'    => $templateUuid,
        'template_version' => $templateVersion,
    ],
    'message' => $isEdit
        ? "Update dokumen (template {$template_id}, norm {$norm}, label {$label})"
        : "Buat dokumen baru (template {$template_id}, norm {$norm}, label {$label})",
]);

ezdoc_respond_success([
    'doc_id'       => $doc_id,
    'isEdit'       => $isEdit,
    'label'        => $label,
    'verify_url'   => $verifyUrl,
    'data_hash'    => $hashInfo['hash'] ?? null,
    'data_hash_at' => $hashInfo['hash_at'] ?? null,
    'debug'        => $__ezdocSaveDebug,
], t('response.document_saved', [], 'Document saved successfully'));
