<?php
/**
 * ALTER surat_dokumen_v2 — add indexes untuk performance.
 * Idempotent: cek SHOW INDEX dulu.
 *
 * Indexes:
 *   - Drop old uk_template_norm_nopen (superseded by uk_...label)
 *   - Drop old uk_template_norm_nopen_label (superseded by uk_...label_version)
 *   - Add unique key composite: template_id + norm + nopen + label + version
 *   - Add performance indexes: idx_deleted, idx_template_active, idx_template_slot
 *   - Add unique index public_slug
 */

return [
    'name' => '20260701000005_alter_surat_dokumen_v2_indexes',
    'up' => function ($conn): void {
        // Helper: check if index exists
        $hasIdx = function (string $name) use ($conn): bool {
            $rs = @$conn->query("SHOW INDEX FROM surat_dokumen_v2 WHERE Key_name = '" . $conn->real_escape_string($name) . "'");
            return $rs && $rs->num_rows > 0;
        };

        // Drop obsolete unique keys
        if ($hasIdx('uk_template_norm_nopen')) {
            @$conn->query("ALTER TABLE surat_dokumen_v2 DROP INDEX uk_template_norm_nopen");
        }
        if ($hasIdx('uk_template_norm_nopen_label')) {
            @$conn->query("ALTER TABLE surat_dokumen_v2 DROP INDEX uk_template_norm_nopen_label");
        }

        // Add new indexes
        $addIfMissing = [
            'uk_template_norm_nopen_label_version' => "ADD UNIQUE KEY uk_template_norm_nopen_label_version (template_id, norm, nopen, label, version)",
            'idx_deleted'                          => "ADD INDEX idx_deleted (deleted_at)",
            'idx_template_active'                  => "ADD INDEX idx_template_active (template_id, deleted_at)",
            'idx_template_slot'                    => "ADD INDEX idx_template_slot (template_id, norm, nopen, label, deleted_at)",
            'idx_public_slug'                      => "ADD UNIQUE INDEX idx_public_slug (public_slug)",
        ];
        foreach ($addIfMissing as $idxName => $sql) {
            if (!$hasIdx($idxName)) {
                @$conn->query("ALTER TABLE surat_dokumen_v2 $sql");
            }
        }
    },
];
