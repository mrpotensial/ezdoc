<?php
/**
 * Create surat_template_v2 dengan schema final (semua kolom sekaligus).
 *
 * IF NOT EXISTS supaya idempotent — kalau tabel sudah ada (existing DB), skip
 * create. Kolom yang belum ada di-add oleh migration terpisah setelahnya.
 */

return [
    'name' => '20260701000001_create_surat_template_v2',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS surat_template_v2 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama_template VARCHAR(255) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Kategori/folder pengelompokan template',
                doc_scope ENUM('patient','general') NOT NULL DEFAULT 'patient' COMMENT 'patient=butuh norm+nopen; general=tidak',
                template_html LONGTEXT COMMENT 'HTML content from WYSIWYG editor',
                config_ttd TEXT COMMENT 'JSON config for signatures',
                config_header TEXT COMMENT 'JSON config for logos, sizes, page settings',
                verify_config TEXT COMMENT 'JSON config: field mana yg tampil di verify page',
                access_config TEXT NULL DEFAULT NULL COMMENT 'JSON: RBAC config per-template',
                is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Protect from destructive field changes',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_nama (nama_template),
                INDEX idx_category (category),
                INDEX idx_scope (doc_scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
];
