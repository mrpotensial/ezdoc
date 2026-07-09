<?php
/**
 * Create ezdoc_templates — new schema untuk template design.
 *
 * Improvements dari surat_template_v2:
 *   1. Prefix `ezdoc_` konsisten
 *   2. Naming semantik: nama_template → name, config_ttd → signature_config, dll
 *   3. Template versioning: uuid + version + is_current (chain via parent_version_id)
 *   4. UUID + slug untuk API-friendly reference
 *   5. JSON native type untuk config columns (validation + JSON functions)
 *   6. Kolom baru: owner_id, is_active, deleted_at (soft-delete di template level juga)
 *
 * Template versioning behavior:
 *   - Setiap template punya `uuid` stabil (family identifier)
 *   - Design edit → create new version (INSERT dengan version+1, is_current=1, parent_version_id=old.id)
 *   - Set old version is_current=0
 *   - Query current: WHERE uuid = X AND is_current = 1
 *   - Dokumen link ke SPECIFIC version via template_id — supaya reproducible
 */

return [
    'name' => '20260706000001_create_ezdoc_templates',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_templates (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL COMMENT 'Template family ID — same across versions',
                slug VARCHAR(120) NOT NULL COMMENT 'URL-friendly identifier (per family)',
                version INT NOT NULL DEFAULT 1 COMMENT 'Template version dalam family',
                is_current TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Only 1 current per uuid',
                parent_version_id BIGINT NULL COMMENT 'Link ke previous version (chain)',
                -- Basic info
                name VARCHAR(255) NOT NULL COMMENT 'was: nama_template',
                category VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Kategori/folder',
                scope ENUM('patient','general') NOT NULL DEFAULT 'patient' COMMENT 'was: doc_scope',
                -- Content
                content LONGTEXT COMMENT 'was: template_html — HTML from WYSIWYG',
                -- JSON configs (native type)
                signature_config JSON NULL DEFAULT NULL COMMENT 'was: config_ttd',
                layout_config JSON NULL DEFAULT NULL COMMENT 'was: config_header (logos, sizes)',
                verify_config JSON NULL DEFAULT NULL COMMENT 'Field visibility di verify page',
                access_config JSON NULL DEFAULT NULL COMMENT 'RBAC per-template (create/edit/lock/delete)',
                -- Meta & state
                owner_id INT NULL COMMENT 'id_pegawai creator (NULL untuk imported/system)',
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft-disable tanpa delete',
                is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Protect from destructive field changes',
                -- Soft-delete
                deleted_at DATETIME NULL DEFAULT NULL,
                deleted_by VARCHAR(100) NULL DEFAULT NULL,
                -- Timestamps
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                -- Indexes
                UNIQUE KEY uk_uuid_version (uuid, version),
                INDEX idx_uuid (uuid),
                INDEX idx_slug (slug),
                INDEX idx_current (uuid, is_current),
                INDEX idx_name (name),
                INDEX idx_category (category),
                INDEX idx_scope (scope),
                INDEX idx_owner (owner_id),
                INDEX idx_active (is_active, deleted_at),
                INDEX idx_parent (parent_version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
];
