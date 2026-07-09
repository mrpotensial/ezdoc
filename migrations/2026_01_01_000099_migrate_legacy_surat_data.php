<?php
/**
 * Optional migration — copy data dari legacy surat_* tables ke ezdoc_* tables.
 *
 * Kondisi run: hanya kalau surat_template_v2 / surat_dokumen_v2 EXIST.
 * Fresh install (tanpa legacy) → migration ini skip silently.
 *
 * Idempotent:
 *   - Kalau row dengan legacy_id sudah ada di ezdoc_*, skip
 *   - Match via metadata['legacy_id'] atau dedicated column
 *
 * Populate baru:
 *   - uuid: UUID v7 (time-ordered)
 *   - slug: derived dari name + random suffix
 *   - version: 1 (initial)
 *   - is_current: 1
 *   - metadata: {"legacy_id": X, "legacy_source": "surat_*_v2"}
 *   - content_hash: computed via SHA-256
 */

return [
    'name' => '2026_01_01_000099_migrate_legacy_surat_data',
    'up' => function ($conn): void {
        // Load UUID helper (defensive — mungkin dipanggil dari CLI tanpa bootstrap)
        if (!function_exists('ezdoc_uuid_v7')) {
            require_once __DIR__ . '/../lib/uuid.php';
        }

        // ─── 1. Templates: surat_template_v2 → ezdoc_templates ───
        $srcCheck = @$conn->query("SHOW TABLES LIKE 'surat_template_v2'");
        if ($srcCheck && $srcCheck->num_rows > 0) {
            // Fetch rows yang belum di-migrate (cek via metadata.legacy_id)
            $rs = @$conn->query("
                SELECT s.* FROM surat_template_v2 s
                WHERE NOT EXISTS (
                    SELECT 1 FROM ezdoc_templates e
                    WHERE JSON_EXTRACT(e.metadata, '$.legacy_id') = s.id
                       OR e.id = s.id
                )
            ");
            if ($rs) {
                $slugify = function (string $name): string {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?: 'template');
                    return substr(trim($slug, '_'), 0, 100);
                };
                $normalizeJson = function ($val): ?string {
                    if ($val === null || $val === '') return null;
                    $decoded = json_decode((string)$val, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) return null;
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                };

                $stmt = @mysqli_prepare($conn, "
                    INSERT INTO ezdoc_templates
                    (uuid, slug, version, is_current, name, category, scope, content,
                     signature_config, layout_config, verify_config, access_config,
                     metadata, owner_id, is_active, is_locked, revision,
                     created_at, updated_at)
                    VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?, 1, ?, ?)
                ");
                if ($stmt) {
                    while ($row = mysqli_fetch_assoc($rs)) {
                        $uuid = ezdoc_uuid_v7();
                        $slug = $slugify((string)$row['nama_template']) . '_' . (int)$row['id'];
                        $name = (string)$row['nama_template'];
                        $category = (string)($row['category'] ?? '');
                        $scope = in_array($row['doc_scope'] ?? 'patient', ['patient', 'general'], true) ? $row['doc_scope'] : 'patient';
                        $content = (string)($row['template_html'] ?? '');
                        $signatureConfig = $normalizeJson($row['config_ttd'] ?? null);
                        $layoutConfig = $normalizeJson($row['config_header'] ?? null);
                        $verifyConfig = $normalizeJson($row['verify_config'] ?? null);
                        $accessConfig = $normalizeJson($row['access_config'] ?? null);
                        $metadata = json_encode([
                            'legacy_id' => (int)$row['id'],
                            'legacy_source' => 'surat_template_v2',
                            'migrated_at' => date('c'),
                        ], JSON_UNESCAPED_SLASHES);
                        $isLocked = (int)($row['is_locked'] ?? 0);
                        $createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
                        $updatedAt = $row['updated_at'] ?? $createdAt;

                        // 14 params: 11×s + 1×i + 2×s = "sssssssssssiss"
                        @mysqli_stmt_bind_param(
                            $stmt,
                            'sssssssssssiss',
                            $uuid, $slug,
                            $name, $category, $scope, $content,
                            $signatureConfig, $layoutConfig, $verifyConfig, $accessConfig,
                            $metadata, $isLocked, $createdAt, $updatedAt
                        );
                        @mysqli_stmt_execute($stmt);
                    }
                }
            }
        }

        // ─── 2. Documents: surat_dokumen_v2 → ezdoc_documents ───
        $srcCheck = @$conn->query("SHOW TABLES LIKE 'surat_dokumen_v2'");
        if ($srcCheck && $srcCheck->num_rows > 0) {
            // Cache template_id (legacy) → template_uuid (new) mapping
            $tplUuidMap = [];
            $rsTpl = @$conn->query("
                SELECT id, uuid, JSON_EXTRACT(metadata, '$.legacy_id') AS legacy_id
                FROM ezdoc_templates
            ");
            if ($rsTpl) {
                while ($t = $rsTpl->fetch_assoc()) {
                    $legacyId = $t['legacy_id'] !== null ? (int)json_decode($t['legacy_id']) : (int)$t['id'];
                    $tplUuidMap[$legacyId] = ['id' => (int)$t['id'], 'uuid' => (string)$t['uuid']];
                }
            }

            $rs = @$conn->query("
                SELECT s.* FROM surat_dokumen_v2 s
                WHERE NOT EXISTS (
                    SELECT 1 FROM ezdoc_documents e
                    WHERE JSON_EXTRACT(e.metadata, '$.legacy_id') = s.id
                )
            ");
            if ($rs) {
                $normalizeJson = function ($val): ?string {
                    if ($val === null || $val === '') return null;
                    $decoded = json_decode((string)$val, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) return null;
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                };

                $stmt = @mysqli_prepare($conn, "
                    INSERT INTO ezdoc_documents
                    (uuid, template_id, template_uuid, template_version,
                     norm, nopen, label, version,
                     field_values, signature_values, metadata,
                     status, is_locked,
                     content_hash, content_hash_at, content_hash_version,
                     public_slug, public_slug_active, public_slug_scan_count, public_slug_last_scan,
                     published_at, deleted_at, deleted_by,
                     revision, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
                ");
                if ($stmt) {
                    while ($row = mysqli_fetch_assoc($rs)) {
                        $legacyTplId = (int)$row['template_id'];
                        if (!isset($tplUuidMap[$legacyTplId])) continue; // skip orphan
                        $tplNew = $tplUuidMap[$legacyTplId];

                        $uuid = ezdoc_uuid_v7();
                        $norm = (string)($row['norm'] ?? '');
                        $nopen = (string)($row['nopen'] ?? '');
                        $label = (string)($row['label'] ?? '-');
                        $version = (int)($row['version'] ?? 1);
                        $fieldValues = $normalizeJson($row['data_fields'] ?? null);
                        $signatureValues = $normalizeJson($row['data_ttd'] ?? null);
                        $metadata = json_encode([
                            'legacy_id' => (int)$row['id'],
                            'legacy_source' => 'surat_dokumen_v2',
                            'migrated_at' => date('c'),
                        ], JSON_UNESCAPED_SLASHES);
                        $isLocked = (int)($row['is_locked'] ?? 0);
                        $status = $isLocked === 1 ? 'locked' : 'published';
                        $contentHash = $row['data_hash'] ?? null;
                        $contentHashAt = $row['data_hash_at'] ?? null;
                        $contentHashVersion = isset($row['data_hash_version']) ? (int)$row['data_hash_version'] : null;
                        $publicSlug = $row['public_slug'] ?? null;
                        $publicSlugActive = (int)($row['public_slug_active'] ?? 1);
                        $publicSlugScanCount = (int)($row['public_slug_scan_count'] ?? 0);
                        $publicSlugLastScan = $row['public_slug_last_scan'] ?? null;
                        $publishedAt = $row['created_at'] ?? null;
                        $deletedAt = $row['deleted_at'] ?? null;
                        $deletedBy = $row['deleted_by'] ?? null;
                        $createdBy = is_numeric($row['created_by'] ?? null) ? (int)$row['created_by'] : null;
                        $createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
                        $updatedAt = $row['updated_at'] ?? $createdAt;

                        // 25 params — types by position:
                        //   1-6:   s i s s s s (uuid, tpl_id, tpl_uuid, norm, nopen, label)
                        //   7-12:  i s s s s i (version, field, sig, meta, status, is_locked)
                        //   13-18: s s i s i i (hash, hash_at, hash_ver, slug, active, scans)
                        //   19-25: s s s s i s s (last_scan, pub_at, del_at, del_by, cre_by, cre_at, upd)
                        @mysqli_stmt_bind_param(
                            $stmt,
                            'sissssissssissisiissssiss',
                            $uuid, $tplNew['id'], $tplNew['uuid'],
                            $norm, $nopen, $label, $version,
                            $fieldValues, $signatureValues, $metadata,
                            $status, $isLocked,
                            $contentHash, $contentHashAt, $contentHashVersion,
                            $publicSlug, $publicSlugActive, $publicSlugScanCount, $publicSlugLastScan,
                            $publishedAt, $deletedAt, $deletedBy,
                            $createdBy, $createdAt, $updatedAt
                        );
                        @mysqli_stmt_execute($stmt);
                    }
                }
            }
        }

        // ─── 3. Default vars: surat_default_vars → ezdoc_default_vars ───
        $srcCheck = @$conn->query("SHOW TABLES LIKE 'surat_default_vars'");
        if ($srcCheck && $srcCheck->num_rows > 0) {
            @$conn->query("
                INSERT IGNORE INTO ezdoc_default_vars (var_name, description, created_at)
                SELECT var_name, description, IFNULL(created_at, NOW()) FROM surat_default_vars
            ");
        }
    },
];
