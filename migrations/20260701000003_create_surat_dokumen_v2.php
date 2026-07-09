<?php
/**
 * Create surat_dokumen_v2 dengan schema final.
 */

return [
    'name' => '20260701000003_create_surat_dokumen_v2',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS surat_dokumen_v2 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                norm VARCHAR(50) COMMENT 'No Rekam Medis',
                nopen VARCHAR(50) COMMENT 'No Pendaftaran',
                label VARCHAR(100) NOT NULL DEFAULT '-' COMMENT 'Label pembeda dokumen',
                version INT NOT NULL DEFAULT 1 COMMENT 'Versi dokumen dalam slot',
                data_fields LONGTEXT COMMENT 'JSON data field',
                data_ttd LONGTEXT COMMENT 'JSON data tanda tangan base64',
                data_hash CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 canonical data (Level 3 verify)',
                data_hash_at DATETIME NULL DEFAULT NULL COMMENT 'Waktu hash di-compute',
                data_hash_version INT NULL DEFAULT NULL COMMENT 'Version dokumen saat hash dibuat',
                is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Read-only state',
                deleted_at DATETIME NULL DEFAULT NULL COMMENT 'Soft-delete timestamp',
                deleted_by VARCHAR(100) NULL DEFAULT NULL,
                public_slug VARCHAR(32) NULL DEFAULT NULL COMMENT 'Random slug untuk QR verify',
                public_slug_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = revoked',
                public_slug_scan_count INT UNSIGNED NOT NULL DEFAULT 0,
                public_slug_last_scan DATETIME NULL DEFAULT NULL,
                created_by VARCHAR(100),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_template (template_id),
                INDEX idx_norm_nopen (norm, nopen),
                INDEX idx_deleted (deleted_at),
                INDEX idx_template_active (template_id, deleted_at),
                INDEX idx_template_slot (template_id, norm, nopen, label, deleted_at),
                UNIQUE KEY uk_template_norm_nopen_label_version (template_id, norm, nopen, label, version),
                UNIQUE INDEX idx_public_slug (public_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
];
