<?php
/**
 * POST _doc_action=new_version
 *   body: template_id, norm, nopen, label, source_version (0 = blank)
 *
 * Buat versi baru dokumen di slot (template_uuid, norm, nopen, label).
 *   - source_version=0: blank (field_values & signature_values = {})
 *   - source_version>0: copy dari versi tersebut
 *
 * Response: { success, doc_id, version }
 *
 * ## v0.9.9 refactor
 *
 * Persistence lewat Connection. Template snapshot + max-version + source copy
 * via fetchOne/fetchScalar. INSERT via execute + lastInsertId.
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn, $author_id;

$tid = (int)($_POST['template_id'] ?? 0);
$n   = trim($_POST['norm']  ?? '');
$np  = trim($_POST['nopen'] ?? '');
$lb  = trim($_POST['label'] ?? '-');
$sourceVersion = isset($_POST['source_version']) ? (int) $_POST['source_version'] : 0;

if ($tid <= 0 || $n === '' || $np === '') {
    ezdoc_respond_error(t('response.incomplete_parameters', [], 'Incomplete parameters'));
}

$db = new MysqliConnection($conn);

// Resolve template_id → uuid + version (snapshot template state saat versi baru dibuat)
$tpl = $db->fetchOne(
    'SELECT uuid, version FROM ezdoc_templates WHERE id = ? LIMIT 1',
    [$tid]
);
if (!$tpl) ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'));
$templateUuid    = (string) $tpl['uuid'];
$templateVersion = (int) $tpl['version'];

// Next version — include soft-deleted supaya unique key tidak collision.
// Search by template_uuid (family) supaya lintas template version ke-detect.
$nextVersion = (int) $db->fetchScalar(
    'SELECT COALESCE(MAX(version), 0) + 1
     FROM ezdoc_documents WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?',
    [$templateUuid, $n, $np, $lb]
);
if ($nextVersion < 1) $nextVersion = 1;

// Copy dari source atau blank
$newFields = '{}';
$newTtd = '{}';
if ($sourceVersion > 0) {
    $src = $db->fetchOne(
        'SELECT field_values, signature_values FROM ezdoc_documents
         WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?
           AND version = ? AND deleted_at IS NULL',
        [$templateUuid, $n, $np, $lb, $sourceVersion]
    );
    if ($src) {
        $newFields = $src['field_values'] ?: '{}';
        $newTtd    = $src['signature_values'] ?: '{}';
    }
}

$authorId    = (int) ($author_id ?? 0);
$docUuid     = ezdoc_uuid_v7();
$publishedAt = date('Y-m-d H:i:s');

try {
    $db->execute(
        'INSERT INTO ezdoc_documents
         (uuid, template_id, template_uuid, template_version,
          norm, nopen, label, version,
          field_values, signature_values,
          status, published_at, is_locked, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)',
        [
            $docUuid, $tid, $templateUuid, $templateVersion,
            $n, $np, $lb, $nextVersion,
            $newFields, $newTtd,
            'published', $publishedAt, $authorId,
        ]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error($e->getMessage());
}

$newDocId = (int) $db->lastInsertId();

ezdoc_audit_log('doc.version_created', [
    'target_type' => 'document',
    'target_id'   => (string) $newDocId,
    'template_id' => $tid,
    'doc_id'      => $newDocId,
    'metadata'    => [
        'version'        => $nextVersion,
        'source_version' => $sourceVersion,
        'norm'           => $n,
        'nopen'          => $np,
        'label'          => $lb,
        'template_uuid'  => $templateUuid,
    ],
    'message' => "Versi baru v{$nextVersion} " . ($sourceVersion > 0 ? "(copy dari v{$sourceVersion})" : '(blank)'),
]);

ezdoc_respond_success([
    'doc_id'  => $newDocId,
    'version' => $nextVersion,
], t('response.version_created', ['version' => $nextVersion], 'New version v{version} created successfully'));
