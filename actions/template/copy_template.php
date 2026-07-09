<?php
/**
 * POST ajax=1 action=copy_template
 *   body: template_id
 *
 * Duplicate template dengan nama "(Copy)" appended. Generate UUID + slug baru
 * — dokumen dari source TIDAK ikut di-copy (masih link ke source template_uuid).
 * Copy semua config: content, signature_config, layout_config, verify_config, access_config.
 *
 * Auth: `ezdoc_require_manage_templates()`.
 *
 * Response: { success, id, nama }
 */

global $conn, $author_id;

ezdoc_require_manage_templates('Tidak berhak duplikat template');

$tid = (int)($_POST['template_id'] ?? 0);
if ($tid <= 0) ezdoc_respond_error('ID tidak valid');

// Fetch source template
$stmt = mysqli_prepare($conn, "SELECT name, category, scope, content, signature_config, layout_config, verify_config, access_config FROM ezdoc_templates WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $tid);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();

if (!$row) ezdoc_respond_error('Template tidak ditemukan');

$newName = $row['name'] . ' (Copy)';
$accessConfig = $row['access_config'] ?? null;
$newUuid = ezdoc_uuid_v7();
$slugBase = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($newName))) ?: 'template';
$newSlug = trim($slugBase, '_') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
if (strlen($newSlug) > 120) $newSlug = substr($newSlug, 0, 120);
$ownerId = (int)($author_id ?? 0) ?: null;

$stmtIns = mysqli_prepare($conn, "
    INSERT INTO ezdoc_templates
    (uuid, slug, version, is_current, name, category, scope, content,
     signature_config, layout_config, verify_config, access_config,
     owner_id, is_active, is_locked)
    VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)
");
mysqli_stmt_bind_param(
    $stmtIns,
    "sssssssssssi",
    $newUuid, $newSlug,
    $newName, $row['category'], $row['scope'], $row['content'],
    $row['signature_config'], $row['layout_config'], $row['verify_config'], $accessConfig,
    $ownerId
);

if (mysqli_stmt_execute($stmtIns)) {
    $newTid = mysqli_insert_id($conn);
    ezdoc_audit_log('template.copied', [
        'target_type' => 'template',
        'target_id' => (string)$newTid,
        'template_id' => $newTid,
        'metadata' => [
            'source_template_id' => $tid,
            'new_name' => $newName,
            'new_uuid' => $newUuid,
        ],
        'message' => "Duplikat template dari #{$tid} → #{$newTid} ({$newName})",
    ]);
    // Backward-compat: field `nama` (v3 list JS masih akses ini)
    ezdoc_respond_raw([
        'success' => true,
        'id' => $newTid,
        'nama' => $newName,
    ]);
} else {
    ezdoc_respond_error(mysqli_error($conn));
}
