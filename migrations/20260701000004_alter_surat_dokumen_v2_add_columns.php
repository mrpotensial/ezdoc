<?php
/**
 * ALTER surat_dokumen_v2 — tambah kolom untuk existing DBs.
 * Combined 3 phase evolution:
 *   - Original schema: id, template_id, norm, nopen, data_fields, data_ttd, is_locked
 *   - Phase 2: label, version, soft-delete (deleted_at, deleted_by)
 *   - Phase 3: public_slug (verify), data_hash (Level 3 verify)
 */

return [
    'name' => '20260701000004_alter_surat_dokumen_v2_add_columns',
    'up' => function ($conn): void {
        $existing = [];
        $rs = @$conn->query("SHOW COLUMNS FROM surat_dokumen_v2");
        if ($rs) while ($c = $rs->fetch_assoc()) $existing[$c['Field']] = true;

        $add = [
            'label'                => "ADD COLUMN label VARCHAR(100) NOT NULL DEFAULT '-' AFTER nopen",
            'version'              => "ADD COLUMN version INT NOT NULL DEFAULT 1 COMMENT 'Versi dokumen dalam slot' AFTER label",
            'is_locked'            => "ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Read-only state' AFTER data_ttd",
            'deleted_at'           => "ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL COMMENT 'Soft-delete' AFTER is_locked",
            'deleted_by'           => "ADD COLUMN deleted_by VARCHAR(100) NULL DEFAULT NULL AFTER deleted_at",
            'public_slug'          => "ADD COLUMN public_slug VARCHAR(32) NULL DEFAULT NULL COMMENT 'Random slug QR verify' AFTER deleted_by",
            'public_slug_active'   => "ADD COLUMN public_slug_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=revoked' AFTER public_slug",
            'public_slug_scan_count' => "ADD COLUMN public_slug_scan_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER public_slug_active",
            'public_slug_last_scan' => "ADD COLUMN public_slug_last_scan DATETIME NULL DEFAULT NULL AFTER public_slug_scan_count",
            'data_hash'            => "ADD COLUMN data_hash CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 canonical data' AFTER data_ttd",
            'data_hash_at'         => "ADD COLUMN data_hash_at DATETIME NULL DEFAULT NULL COMMENT 'Waktu hash' AFTER data_hash",
            'data_hash_version'    => "ADD COLUMN data_hash_version INT NULL DEFAULT NULL COMMENT 'Version saat hash' AFTER data_hash_at",
        ];

        foreach ($add as $col => $sql) {
            if (!isset($existing[$col])) {
                @$conn->query("ALTER TABLE surat_dokumen_v2 $sql");
            }
        }
    },
];
