<?php
/**
 * Create ezdoc_documents — canonical schema untuk dokumen instances.
 *
 * Design improvements dari draft sebelumnya:
 *   - UUID v7 time-ordered
 *   - metadata JSON untuk extensibility
 *   - revision INT untuk optimistic locking
 *   - deleted_reason TEXT untuk audit
 *   - FK constraint ke ezdoc_templates
 *   - Composite indexes yang lebih strategis
 *
 * Reference chain:
 *   ezdoc_documents.template_id (FK) → ezdoc_templates.id (specific version)
 *   ezdoc_documents.template_uuid → ezdoc_templates.uuid (family, denormalized untuk query cepat)
 *
 * Query patterns:
 *   Latest by slot:  WHERE template_uuid = X AND norm = ? AND nopen = ? AND label = ?
 *                    ORDER BY version DESC LIMIT 1
 *   Public verify:   WHERE public_slug = ? AND public_slug_active = 1 AND deleted_at IS NULL
 *   User's docs:     WHERE created_by = ? AND deleted_at IS NULL ORDER BY created_at DESC
 *   Expiring soon:   WHERE expires_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY
 */

return [
    'name' => '2026_01_01_000002_create_ezdoc_documents',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_documents (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                -- Identifier (UUID v7 time-ordered)
                uuid CHAR(36) NOT NULL UNIQUE COMMENT 'Stable UUID untuk API/external ref',
                -- Template reference (snapshot at creation time)
                template_id BIGINT NOT NULL COMMENT 'FK ke specific template version (immutable)',
                template_uuid CHAR(36) NOT NULL COMMENT 'Template family (denormalized)',
                template_version INT UNSIGNED NOT NULL DEFAULT 1,
                -- Identity
                title VARCHAR(255) NULL COMMENT 'Human-readable name (auto-computed)',
                norm VARCHAR(50) NULL COMMENT 'No Rekam Medis (nullable untuk general scope)',
                nopen VARCHAR(50) NULL COMMENT 'No Pendaftaran (nullable untuk general scope)',
                label VARCHAR(100) NOT NULL DEFAULT '-' COMMENT 'Pembeda dokumen dalam slot',
                -- Versioning (dalam slot yang sama)
                version INT UNSIGNED NOT NULL DEFAULT 1,
                -- Data (JSON native)
                field_values JSON NULL COMMENT 'User-input field values',
                signature_values JSON NULL COMMENT 'Base64 signature images per TTD placeholder',
                -- Extensibility
                metadata JSON NULL COMMENT 'Custom fields per-tenant/per-doc tanpa migration',
                -- Lifecycle state
                status ENUM('draft','published','locked','archived') NOT NULL DEFAULT 'published',
                is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Duplicate of status=locked untuk fast query',
                -- Content integrity (Level 3 verify)
                content_hash CHAR(64) NULL COMMENT 'SHA-256 canonical data',
                content_hash_at DATETIME NULL,
                content_hash_version INT UNSIGNED NULL,
                -- Public verify (Level 1-2)
                public_slug VARCHAR(32) NULL COMMENT 'Random slug untuk QR verify',
                public_slug_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = revoked',
                public_slug_scan_count INT UNSIGNED NOT NULL DEFAULT 0,
                public_slug_last_scan DATETIME NULL,
                -- Lifecycle timestamps
                published_at DATETIME NULL COMMENT 'Waktu status = published',
                expires_at DATETIME NULL COMMENT 'Auto-expire timestamp (optional)',
                -- Optimistic locking
                revision INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Increment on each UPDATE',
                -- Soft-delete
                deleted_at DATETIME NULL,
                deleted_by VARCHAR(100) NULL,
                deleted_reason TEXT NULL COMMENT 'Alasan delete untuk audit trail',
                -- Actor tracking
                created_by BIGINT NULL,
                updated_by BIGINT NULL,
                -- Timestamps
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                -- Constraints
                UNIQUE KEY uk_slot_version (template_uuid, norm, nopen, label, version),
                UNIQUE KEY uk_public_slug (public_slug),
                -- Indexes
                INDEX idx_uuid (uuid),
                INDEX idx_template (template_id),
                INDEX idx_template_uuid (template_uuid),
                INDEX idx_norm_nopen (norm, nopen),
                INDEX idx_status_deleted (status, deleted_at),
                INDEX idx_slot (template_uuid, norm, nopen, label, deleted_at),
                INDEX idx_deleted (deleted_at),
                INDEX idx_expires (expires_at),
                INDEX idx_created_by (created_by, created_at),
                INDEX idx_updated (updated_at),
                -- FK ke template (specific version)
                CONSTRAINT fk_ezdoc_documents_template
                    FOREIGN KEY (template_id) REFERENCES ezdoc_templates(id)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Document instances dari template dengan versioning + integrity + lifecycle'
        ");
    },
];
