<?php
/**
 * Copy data dari surat_template_v2 → ezdoc_templates.
 *
 * Column mapping:
 *   id             → id (preserve)
 *   nama_template  → name
 *   category       → category
 *   doc_scope      → scope
 *   template_html  → content
 *   config_ttd     → signature_config (JSON string, DB akan validate)
 *   config_header  → layout_config (JSON string)
 *   verify_config  → verify_config
 *   access_config  → access_config
 *   is_locked      → is_locked
 *   created_at     → created_at
 *   updated_at     → updated_at
 *
 * New fields di-populate:
 *   uuid           → auto-generate UUID v4
 *   slug           → derived dari name (lowercase + underscore)
 *   version        → 1 (semua template lama = v1)
 *   is_current     → 1 (semua current)
 *   parent_version_id → NULL
 *   owner_id       → NULL (unknown untuk imported data)
 *   is_active      → 1 (assume active)
 *   deleted_at     → NULL
 *
 * Idempotent: cek dulu apakah source table ada + destination sudah punya row.
 * Kalau row dengan `uuid` sudah ada di destination, SKIP (INSERT IGNORE pattern).
 */

return [
    'name' => '20260706000004_migrate_data_surat_template_to_ezdoc',
    'up' => function ($conn): void {
        // Cek apakah source table ada — kalau tidak (fresh install), skip
        $srcCheck = @$conn->query("SHOW TABLES LIKE 'surat_template_v2'");
        if (!$srcCheck || $srcCheck->num_rows === 0) return;

        // Fetch source rows yang belum di-migrate (belum ada di ezdoc_templates dengan id sama)
        // Preserve id supaya reference dari dokumen tetap konsisten
        $rs = @$conn->query("
            SELECT s.* FROM surat_template_v2 s
            LEFT JOIN ezdoc_templates e ON e.id = s.id
            WHERE e.id IS NULL
        ");
        if (!$rs) return;

        // Helper: UUID v4
        $uuid4 = function (): string {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };

        // Helper: slugify
        $slugify = function (string $name): string {
            $slug = strtolower(trim($name));
            $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
            $slug = trim($slug, '_');
            if ($slug === '') $slug = 'template';
            return substr($slug, 0, 120);
        };

        // Prepare INSERT
        $stmt = @mysqli_prepare($conn, "
            INSERT INTO ezdoc_templates
            (id, uuid, slug, version, is_current, parent_version_id,
             name, category, scope, content,
             signature_config, layout_config, verify_config, access_config,
             owner_id, is_active, is_locked,
             created_at, updated_at)
            VALUES (?, ?, ?, 1, 1, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, ?, ?, ?)
        ");
        if (!$stmt) return;

        while ($row = mysqli_fetch_assoc($rs)) {
            $id = (int)$row['id'];
            $uuid = $uuid4();
            $slug = $slugify((string)$row['nama_template']) . '_' . $id; // append id supaya unique
            $name = (string)$row['nama_template'];
            $category = (string)($row['category'] ?? '');
            $scope = in_array($row['doc_scope'] ?? 'patient', ['patient', 'general'], true) ? $row['doc_scope'] : 'patient';
            $content = (string)($row['template_html'] ?? '');
            // JSON fields — old data mungkin bukan JSON valid. Validate sebelum insert supaya
            // MySQL JSON type tidak reject.
            $normalizeJson = function ($val): ?string {
                if ($val === null || $val === '') return null;
                $decoded = json_decode((string)$val, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) return null;
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            };
            $signatureConfig = $normalizeJson($row['config_ttd'] ?? null);
            $layoutConfig = $normalizeJson($row['config_header'] ?? null);
            $verifyConfig = $normalizeJson($row['verify_config'] ?? null);
            $accessConfig = $normalizeJson($row['access_config'] ?? null);
            $isLocked = (int)($row['is_locked'] ?? 0);
            $createdAt = $row['created_at'] ?? null;
            $updatedAt = $row['updated_at'] ?? null;

            // 14 params: i(id) sss(uuid,slug,name) ss(cat,scope) s(content)
            //           ssss(4 json configs) i(isLocked) ss(createdAt,updatedAt)
            @mysqli_stmt_bind_param(
                $stmt,
                'issssssssssiss',
                $id, $uuid, $slug,
                $name, $category, $scope, $content,
                $signatureConfig, $layoutConfig, $verifyConfig, $accessConfig,
                $isLocked, $createdAt, $updatedAt
            );
            @mysqli_stmt_execute($stmt);
        }
    },
];
