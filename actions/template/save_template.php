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
 * Auth: template manager. Audit log.
 *
 * Response backward-compat: { success, message, id }
 *
 * ## v0.9.9 refactor
 *
 * Persistence via `Ezdoc\Db\Connection`. Update vs insert branching preserved
 * — dgn support "partial update" (kalau access_config tidak di-POST, jangan
 * di-clobber). Repository::save() TIDAK dipakai disini karena partial-update
 * semantic (skip access_config) tidak di Repository model.
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

use Ezdoc\Context;

// $author_id (consumer-provided current user id) kept global untuk audit/versioning.
// $conn removed — use Context::default()->db instead (library-standalone since v0.9.10).
global $author_id;

ezdoc_require_manage_templates('Tidak berhak simpan template');

// Frontend backward compat: legacy key names
$name = trim($_POST['nama_template'] ?? '');
$category = trim($_POST['category'] ?? '');
if (mb_strlen($category) > 100) $category = mb_substr($category, 0, 100);

$scope = trim($_POST['doc_scope'] ?? 'patient');
if (!in_array($scope, ['patient', 'general'], true)) $scope = 'patient';

$content         = $_POST['template_html'] ?? '';
$signatureConfig = $_POST['config_ttd'] ?? '[]';
$layoutConfig    = $_POST['config_header'] ?? '{}';

// Verify config: validate JSON; null-safe
$verifyConfigRaw = trim($_POST['verify_config'] ?? '');
$verifyConfig = null;
if ($verifyConfigRaw !== '' && $verifyConfigRaw !== 'null') {
    $decoded = json_decode($verifyConfigRaw, true);
    if (is_array($decoded)) $verifyConfig = json_encode($decoded, JSON_UNESCAPED_UNICODE);
}

// Access config: RBAC per-template
$hasAccessConfigInPost = array_key_exists('access_config', $_POST);
$accessConfig = null;
if ($hasAccessConfigInPost) {
    $accessConfigRaw = trim((string) $_POST['access_config']);
    if ($accessConfigRaw !== '' && $accessConfigRaw !== 'null') {
        $decoded = json_decode($accessConfigRaw, true);
        if (is_array($decoded)) $accessConfig = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
}

$template_id = (int) ($_POST['template_id'] ?? 0);
$ownerId = (int) ($author_id ?? 0) ?: null;

if (empty($name)) {
    ezdoc_respond_error(t('response.template_name_required', [], 'Template name is required'));
}

$db = new MysqliConnection(Context::default()->db);

try {
    if ($template_id > 0) {
        if ($hasAccessConfigInPost) {
            $db->execute(
                'UPDATE ezdoc_templates SET name = ?, category = ?, scope = ?, content = ?,
                    signature_config = ?, layout_config = ?, verify_config = ?, access_config = ?
                 WHERE id = ?',
                [$name, $category, $scope, $content, $signatureConfig, $layoutConfig, $verifyConfig, $accessConfig, $template_id]
            );
        } else {
            // Preserve existing access_config (partial update)
            $db->execute(
                'UPDATE ezdoc_templates SET name = ?, category = ?, scope = ?, content = ?,
                    signature_config = ?, layout_config = ?, verify_config = ?
                 WHERE id = ?',
                [$name, $category, $scope, $content, $signatureConfig, $layoutConfig, $verifyConfig, $template_id]
            );
        }
        $newId = $template_id;
    } else {
        // Insert baru — generate UUID + slug
        $newUuid = ezdoc_uuid_v7();
        $slugBase = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($name))) ?: 'template';
        $newSlug = trim($slugBase, '_') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        if (strlen($newSlug) > 120) $newSlug = substr($newSlug, 0, 120);

        $db->execute(
            'INSERT INTO ezdoc_templates
             (uuid, slug, version, is_current, name, category, scope, content,
              signature_config, layout_config, verify_config, access_config,
              owner_id, is_active, is_locked)
             VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)',
            [
                $newUuid, $newSlug,
                $name, $category, $scope, $content,
                $signatureConfig, $layoutConfig, $verifyConfig, $accessConfig,
                $ownerId,
            ]
        );
        $newId = (int) $db->lastInsertId();
    }
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.save_template_failed', ['error' => $e->getMessage()], 'Failed to save: {error}'), 500);
}

$isUpdate = ($template_id > 0);
ezdoc_audit_log($isUpdate ? 'template.updated' : 'template.created', [
    'target_type' => 'template',
    'target_id'   => (string) $newId,
    'template_id' => (int) $newId,
    'metadata'    => [
        'name'              => $name,
        'category'          => $category,
        'scope'             => $scope,
        'has_access_config' => ($accessConfig !== null),
    ],
    'message' => ($isUpdate ? 'Update' : 'Buat') . " template: {$name}",
]);

ezdoc_respond_raw([
    'success' => true,
    'message' => t('response.template_saved', [], 'Template saved successfully'),
    'id'      => $newId,
]);
