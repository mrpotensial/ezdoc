<?php
/**
 * Copy data dari surat_dokumen_v2 → ezdoc_documents.
 *
 * Column mapping:
 *   id                   → id (preserve)
 *   template_id          → template_id (references ezdoc_templates.id — already migrated)
 *   norm, nopen, label   → same
 *   version              → version (dokumen versioning dalam slot)
 *   data_fields          → field_values (JSON)
 *   data_ttd             → signature_values (JSON)
 *   is_locked            → is_locked + status='locked' kalau =1
 *   data_hash*           → content_hash*
 *   public_slug*         → public_slug*
 *   deleted_at, deleted_by → same
 *   created_by           → created_by (VARCHAR di old → cast ke INT)
 *   created_at, updated_at → same
 *
 * New fields di-populate:
 *   uuid                → auto-generate
 *   template_uuid       → lookup dari ezdoc_templates
 *   template_version    → 1 (dari template versioning)
 *   title               → NULL (bisa di-compute later dari data)
 *   status              → 'locked' kalau is_locked=1, else 'published'
 *   published_at        → created_at (backdate: assume published saat create)
 *   expires_at          → NULL
 *   updated_by          → NULL (unknown history)
 */

return [
    'name' => '20260706000005_migrate_data_surat_dokumen_to_ezdoc',
    'up' => function ($conn): void {
        // Cek source table
        $srcCheck = @$conn->query("SHOW TABLES LIKE 'surat_dokumen_v2'");
        if (!$srcCheck || $srcCheck->num_rows === 0) return;

        // Fetch source rows yang belum di-migrate
        $rs = @$conn->query("
            SELECT s.* FROM surat_dokumen_v2 s
            LEFT JOIN ezdoc_documents e ON e.id = s.id
            WHERE e.id IS NULL
        ");
        if (!$rs) return;

        // Helper: UUID v4
        $uuid4 = function (): string {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };

        // Helper: normalize JSON
        $normalizeJson = function ($val): ?string {
            if ($val === null || $val === '') return null;
            $decoded = json_decode((string)$val, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) return null;
            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        };

        // Cache template UUID lookup — hindari N+1 query
        $tplUuidCache = [];
        $rsTpl = @$conn->query("SELECT id, uuid FROM ezdoc_templates");
        if ($rsTpl) while ($t = $rsTpl->fetch_assoc()) $tplUuidCache[(int)$t['id']] = $t['uuid'];

        // Prepare INSERT
        $stmt = @mysqli_prepare($conn, "
            INSERT INTO ezdoc_documents
            (id, uuid, template_id, template_uuid, template_version,
             title, norm, nopen, label, version,
             field_values, signature_values,
             status, is_locked,
             content_hash, content_hash_at, content_hash_version,
             public_slug, public_slug_active, public_slug_scan_count, public_slug_last_scan,
             published_at, deleted_at, deleted_by,
             created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;

        while ($row = mysqli_fetch_assoc($rs)) {
            $id = (int)$row['id'];
            $uuid = $uuid4();
            $templateId = (int)$row['template_id'];
            $templateUuid = $tplUuidCache[$templateId] ?? '';
            if ($templateUuid === '') continue; // skip orphan (template belum di-migrate — shouldn't happen)

            $norm = (string)($row['norm'] ?? '');
            $nopen = (string)($row['nopen'] ?? '');
            $label = (string)($row['label'] ?? '-');
            $version = (int)($row['version'] ?? 1);
            $fieldValues = $normalizeJson($row['data_fields'] ?? null);
            $signatureValues = $normalizeJson($row['data_ttd'] ?? null);
            $isLocked = (int)($row['is_locked'] ?? 0);
            $status = $isLocked === 1 ? 'locked' : 'published';
            $contentHash = $row['data_hash'] ?? null;
            $contentHashAt = $row['data_hash_at'] ?? null;
            $contentHashVersion = isset($row['data_hash_version']) ? (int)$row['data_hash_version'] : null;
            $publicSlug = $row['public_slug'] ?? null;
            $publicSlugActive = (int)($row['public_slug_active'] ?? 1);
            $publicSlugScanCount = (int)($row['public_slug_scan_count'] ?? 0);
            $publicSlugLastScan = $row['public_slug_last_scan'] ?? null;
            $publishedAt = $row['created_at'] ?? null; // backdate = assume published on create
            $deletedAt = $row['deleted_at'] ?? null;
            $deletedBy = $row['deleted_by'] ?? null;
            $createdBy = isset($row['created_by']) && is_numeric($row['created_by']) ? (int)$row['created_by'] : null;
            $createdAt = $row['created_at'] ?? null;
            $updatedAt = $row['updated_at'] ?? null;

            // 25 params (5 groups of 5):
            //   1-5:  i(id) s(uuid) i(tpl_id) s(tpl_uuid) s(norm)         → "isiss"
            //   6-10: s(nopen) s(label) i(version) s(field) s(sig)         → "ssiss"
            //   11-15: s(status) i(is_locked) s(hash) s(hash_at) i(hash_v) → "sissi"
            //   16-20: s(slug) i(active) i(scans) s(last_scan) s(pub_at)   → "siiss"
            //   21-25: s(del_at) s(del_by) i(created_by) s(cre_at) s(upd)  → "ssiss"
            @mysqli_stmt_bind_param(
                $stmt,
                'isissssisssissisiissssiss',
                $id, $uuid, $templateId, $templateUuid,
                $norm, $nopen, $label, $version,
                $fieldValues, $signatureValues,
                $status, $isLocked,
                $contentHash, $contentHashAt, $contentHashVersion,
                $publicSlug, $publicSlugActive, $publicSlugScanCount, $publicSlugLastScan,
                $publishedAt, $deletedAt, $deletedBy,
                $createdBy, $createdAt, $updatedAt
            );
            @mysqli_stmt_execute($stmt);
        }
    },
];
