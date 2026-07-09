<?php
/**
 * POST ajax=1 action=save
 *   body (v3 frontend still sends legacy names untuk backward compat):
 *     template_id, nama_template → mapped to name
 *     doc_scope → mapped to scope
 *     template_html → mapped to content
 *     config_ttd → mapped to signature_config
 *     config_header → mapped to layout_config
 *     verify_config, access_config → same
 *
 * Insert atau update template di ezdoc_templates.
 * template_id > 0 = update, template_id = 0 = insert baru (generate uuid + slug).
 *
 * Auth: `ezdoc_require_manage_templates()`.
 *
 * Response backward-compat:
 *   { success: true, message, id }
 */

global $conn, $author_id;

ezdoc_require_manage_templates('Tidak berhak simpan template');

// Frontend backward compat: masih pakai key nama_template/doc_scope/template_html/config_ttd/config_header
$name = trim($_POST['nama_template'] ?? '');
$category = trim($_POST['category'] ?? '');
if (mb_strlen($category) > 100) $category = mb_substr($category, 0, 100);

$scope = trim($_POST['doc_scope'] ?? 'patient');
if (!in_array($scope, ['patient', 'general'], true)) $scope = 'patient';

$content = $_POST['template_html'] ?? '';
$signatureConfig = $_POST['config_ttd'] ?? '[]';
$layoutConfig = $_POST['config_header'] ?? '{}';

// Verify config: validate as JSON; null-safe
$verifyConfigRaw = trim($_POST['verify_config'] ?? '');
$verifyConfig = null;
if ($verifyConfigRaw !== '' && $verifyConfigRaw !== 'null') {
    $decoded = json_decode($verifyConfigRaw, true);
    if (is_array($decoded)) $verifyConfig = json_encode($decoded, JSON_UNESCAPED_UNICODE);
}

// Access config: RBAC per-template.
$hasAccessConfigInPost = array_key_exists('access_config', $_POST);
$accessConfig = null;
if ($hasAccessConfigInPost) {
    $accessConfigRaw = trim((string)$_POST['access_config']);
    if ($accessConfigRaw !== '' && $accessConfigRaw !== 'null') {
        $decoded = json_decode($accessConfigRaw, true);
        if (is_array($decoded)) $accessConfig = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
}

$template_id = (int)($_POST['template_id'] ?? 0);
$ownerId = (int)($author_id ?? 0) ?: null;

if (empty($name)) {
    ezdoc_respond_error('Nama template wajib diisi');
}

if ($template_id > 0) {
    if ($hasAccessConfigInPost) {
        // Update INCLUDE access_config
        $stmt = mysqli_prepare($conn, "UPDATE ezdoc_templates SET name=?, category=?, scope=?, content=?, signature_config=?, layout_config=?, verify_config=?, access_config=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssssssssi", $name, $category, $scope, $content, $signatureConfig, $layoutConfig, $verifyConfig, $accessConfig, $template_id);
    } else {
        // Update TIDAK sentuh access_config (preserve existing)
        $stmt = mysqli_prepare($conn, "UPDATE ezdoc_templates SET name=?, category=?, scope=?, content=?, signature_config=?, layout_config=?, verify_config=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "sssssssi", $name, $category, $scope, $content, $signatureConfig, $layoutConfig, $verifyConfig, $template_id);
    }
} else {
    // Insert baru — generate UUID + slug (derived dari name + suffix random)
    $newUuid = ezdoc_uuid_v7();
    $slugBase = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($name))) ?: 'template';
    $newSlug = trim($slugBase, '_') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    if (strlen($newSlug) > 120) $newSlug = substr($newSlug, 0, 120);

    $stmt = mysqli_prepare($conn, "
        INSERT INTO ezdoc_templates
        (uuid, slug, version, is_current, name, category, scope, content,
         signature_config, layout_config, verify_config, access_config,
         owner_id, is_active, is_locked)
        VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)
    ");
    mysqli_stmt_bind_param(
        $stmt,
        "sssssssssssi",
        $newUuid, $newSlug,
        $name, $category, $scope, $content,
        $signatureConfig, $layoutConfig, $verifyConfig, $accessConfig,
        $ownerId
    );
}

if (mysqli_stmt_execute($stmt)) {
    $newId = $template_id > 0 ? $template_id : mysqli_insert_id($conn);
    $isUpdate = ($template_id > 0);
    ezdoc_audit_log($isUpdate ? 'template.updated' : 'template.created', [
        'target_type' => 'template',
        'target_id' => (string)$newId,
        'template_id' => (int)$newId,
        'metadata' => [
            'name' => $name,
            'category' => $category,
            'scope' => $scope,
            'has_access_config' => ($accessConfig !== null),
        ],
        'message' => ($isUpdate ? 'Update' : 'Buat') . " template: {$name}",
    ]);
    ezdoc_respond_raw([
        'success' => true,
        'message' => 'Template berhasil disimpan',
        'id' => $newId,
    ]);
} else {
    ezdoc_respond_error('Gagal menyimpan: ' . mysqli_error($conn));
}
