<?php
/**
 * Create ezdoc_templates — canonical schema untuk template design.
 *
 * Design principles:
 *   - UUID v7 (time-ordered) sebagai stable identifier
 *   - Template versioning: uuid = family, is_current flag, parent_version_id chain
 *   - JSON native untuk config/settings columns
 *   - metadata JSON untuk extensibility (custom fields tanpa migration)
 *   - hash CHAR(64) untuk template integrity check
 *   - revision INT untuk optimistic locking (increment on each UPDATE)
 *   - FK constraint ke parent_version_id (self-referencing)
 *   - FULLTEXT index untuk text search
 *   - Composite indexes untuk query patterns umum
 *
 * Query patterns:
 *   Get current:      WHERE uuid = X AND is_current = 1 AND deleted_at IS NULL
 *   List active:      WHERE is_current = 1 AND deleted_at IS NULL AND is_active = 1
 *   Version history:  WHERE uuid = X ORDER BY version DESC
 *   Search by name:   WHERE MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE)
 */

return [
    'name' => '2026_01_01_000001_create_ezdoc_templates',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_templates (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                -- Identifiers (UUID v7 time-ordered)
                uuid CHAR(36) NOT NULL COMMENT 'Template family ID (UUID v7, same across versions)',
                slug VARCHAR(120) NOT NULL COMMENT 'URL-friendly identifier (per family)',
                -- Versioning
                version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Version dalam family (increment on design edit)',
                is_current TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active version untuk family ini',
                parent_version_id BIGINT NULL COMMENT 'Chain ke previous version',
                -- Basic info
                name VARCHAR(255) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Kategori/folder pengelompokan',
                scope ENUM('patient','general') NOT NULL DEFAULT 'patient',
                -- Content
                content LONGTEXT COMMENT 'HTML template dari WYSIWYG editor',
                content_hash CHAR(64) NULL COMMENT 'SHA-256 canonical content+configs (integrity check)',
                -- Configs (JSON native)
                signature_config JSON NULL COMMENT 'Config TTD placeholders',
                layout_config JSON NULL COMMENT 'Config logos, page size, padding',
                verify_config JSON NULL COMMENT 'Field mana yg tampil di verify page',
                access_config JSON NULL COMMENT 'RBAC per-template (create/edit/lock/delete)',
                -- Extensibility
                metadata JSON NULL COMMENT 'Custom fields per-tenant/per-template tanpa migration',
                -- Ownership & state
                owner_id BIGINT NULL COMMENT 'ID pegawai/user creator',
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft-disable tanpa delete',
                is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Prevent destructive field changes',
                -- Optimistic locking
                revision INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Increment on each UPDATE untuk cegah lost updates',
                -- Soft-delete
                deleted_at DATETIME NULL DEFAULT NULL,
                deleted_by VARCHAR(100) NULL DEFAULT NULL,
                deleted_reason TEXT NULL COMMENT 'Alasan delete untuk audit trail',
                -- Actor & timestamps
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                -- Constraints
                UNIQUE KEY uk_uuid_version (uuid, version),
                -- Indexes untuk query patterns umum
                INDEX idx_uuid (uuid),
                INDEX idx_slug (slug),
                INDEX idx_current_active (is_current, is_active, deleted_at),
                INDEX idx_category (category),
                INDEX idx_scope (scope),
                INDEX idx_owner (owner_id),
                INDEX idx_parent (parent_version_id),
                INDEX idx_updated (updated_at),
                -- FULLTEXT untuk text search
                FULLTEXT KEY ft_name_category (name, category),
                -- FK self-referencing (version chain)
                CONSTRAINT fk_ezdoc_templates_parent
                    FOREIGN KEY (parent_version_id) REFERENCES ezdoc_templates(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Template design storage dengan versioning + integrity check'
        ");
    },
];
