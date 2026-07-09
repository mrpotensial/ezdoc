<?php
/**
 * ALTER surat_template_v2 — tambah kolom untuk existing DBs yang belum punya.
 * Idempotent: cek SHOW COLUMNS dulu sebelum ADD.
 *
 * Kolom:
 *   - is_locked (existing v2 schema)
 *   - category
 *   - doc_scope
 *   - verify_config
 *   - access_config
 */

return [
    'name' => '20260701000002_alter_surat_template_v2_add_columns',
    'up' => function ($conn): void {
        $existing = [];
        $rs = @$conn->query("SHOW COLUMNS FROM surat_template_v2");
        if ($rs) while ($c = $rs->fetch_assoc()) $existing[$c['Field']] = true;

        $add = [
            'is_locked'     => "ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Protect from destructive field changes' AFTER config_header",
            'category'      => "ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Kategori' AFTER nama_template, ADD INDEX idx_category (category)",
            'doc_scope'     => "ADD COLUMN doc_scope ENUM('patient','general') NOT NULL DEFAULT 'patient' COMMENT 'patient=butuh norm+nopen' AFTER category, ADD INDEX idx_scope (doc_scope)",
            'verify_config' => "ADD COLUMN verify_config TEXT NULL DEFAULT NULL COMMENT 'JSON config field verify page' AFTER config_header",
            'access_config' => "ADD COLUMN access_config TEXT NULL DEFAULT NULL COMMENT 'JSON RBAC per-template' AFTER verify_config",
        ];

        foreach ($add as $col => $sql) {
            if (!isset($existing[$col])) {
                @$conn->query("ALTER TABLE surat_template_v2 $sql");
            }
        }
    },
];
