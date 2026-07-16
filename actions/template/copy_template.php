<?php
/**
 * POST ajax=1 action=copy_template, body: template_id
 *
 * Duplicate template dgn nama "(Copy)" appended. Generate UUID + slug baru.
 * Copy semua config: content, signature_config, layout_config, verify_config,
 * access_config. Dokumen dari source TIDAK ikut di-copy.
 *
 * Auth: template manager.
 *
 * Response: { success, id, nama }
 *
 * ## v0.9.9 refactor — Connection via Repository read, direct INSERT untuk
 * duplikat (Repository::save-nya untuk create baru; kita reuse pattern
 * berbeda karena copy langsung dari row source).
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

use Ezdoc\Context;

global $author_id;

ezdoc_require_manage_templates('Tidak berhak duplikat template');

$tid = (int)($_POST['template_id'] ?? 0);
if ($tid <= 0) ezdoc_respond_error(t('response.invalid_id', [], 'Invalid ID'));

$db = new MysqliConnection(Context::default()->db);

// Fetch source template
$row = $db->fetchOne(
    'SELECT name, category, scope, content, signature_config, layout_config, verify_config, access_config
     FROM ezdoc_templates WHERE id = ?',
    [$tid]
);
if (!$row) ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'));

$newName = $row['name'] . ' (Copy)';
$newUuid = ezdoc_uuid_v7();
$slugBase = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($newName))) ?: 'template';
$newSlug = trim($slugBase, '_') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
if (strlen($newSlug) > 120) $newSlug = substr($newSlug, 0, 120);
$ownerId = (int) ($author_id ?? 0) ?: null;

try {
    $db->execute(
        'INSERT INTO ezdoc_templates
         (uuid, slug, version, is_current, name, category, scope, content,
          signature_config, layout_config, verify_config, access_config,
          owner_id, is_active, is_locked)
         VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)',
        [
            $newUuid, $newSlug,
            $newName, $row['category'], $row['scope'], $row['content'],
            $row['signature_config'], $row['layout_config'], $row['verify_config'], $row['access_config'] ?? null,
            $ownerId,
        ]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error($e->getMessage());
}

$newTid = (int) $db->lastInsertId();

ezdoc_audit_log('template.copied', [
    'target_type' => 'template',
    'target_id'   => (string) $newTid,
    'template_id' => $newTid,
    'metadata'    => [
        'source_template_id' => $tid,
        'new_name'           => $newName,
        'new_uuid'           => $newUuid,
    ],
    'message' => "Duplikat template dari #{$tid} → #{$newTid} ({$newName})",
]);

// Backward-compat: field `nama` (v3 list JS masih akses ini)
ezdoc_respond_raw([
    'success' => true,
    'id'      => $newTid,
    'nama'    => $newName,
]);
