<?php
/**
 * Create ezdoc_documents — new schema untuk instances dari template.
 *
 * Improvements dari surat_dokumen_v2:
 *   1. Prefix ezdoc_ konsisten
 *   2. Naming semantik: data_fields → field_values, data_ttd → signature_values,
 *      data_hash → content_hash, dll
 *   3. UUID untuk API-friendly reference
 *   4. JSON native type untuk field_values, signature_values
 *   5. Kolom baru: title (human-readable), status (draft/published/etc), expires_at, updated_by
 *   6. Template reference: template_id (specific version) + template_uuid (family)
 *      → dokumen selalu bisa reproduce dari template version yang exact
 *
 * Status lifecycle:
 *   - draft: work-in-progress, belum finalized
 *   - published: sudah finalized (default state — backward compat dengan v2)
 *   - locked: locked untuk edit (was: is_locked=1)
 *   - archived: read-only historical
 */

return [
    'name' => '20260706000002_create_ezdoc_documents',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_documents (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL UNIQUE COMMENT 'Stable identifier untuk API',
                -- Template reference
                template_id BIGINT NOT NULL COMMENT 'FK to specific template version (immutable ref)',
                template_uuid CHAR(36) NOT NULL COMMENT 'Denormalized — template family',
                template_version INT NOT NULL DEFAULT 1 COMMENT 'Version snapshot saat doc dibuat',
                -- Identity
                title VARCHAR(255) NULL COMMENT 'Human-readable name (auto-computed dari nomor surat)',
                norm VARCHAR(50) NULL COMMENT 'No Rekam Medis (nullable untuk general scope)',
                nopen VARCHAR(50) NULL COMMENT 'No Pendaftaran (nullable untuk general scope)',
                label VARCHAR(100) NOT NULL DEFAULT '-' COMMENT 'Label pembeda dokumen dalam slot',
                -- Doc versioning (dalam slot yang sama)
                version INT NOT NULL DEFAULT 1,
                -- Data (JSON native)
                field_values JSON NULL COMMENT 'was: data_fields',
                signature_values JSON NULL COMMENT 'was: data_ttd — base64 images',
                -- State
                status ENUM('draft','published','locked','archived') NOT NULL DEFAULT 'published',
                is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Duplicate of status=locked untuk backward compat query',
                -- Content integrity (Level 3 verify)
                content_hash CHAR(64) NULL COMMENT 'SHA-256 canonical data',
                content_hash_at DATETIME NULL,
                content_hash_version INT NULL,
                -- Public verify (Level 1-2)
                public_slug VARCHAR(32) NULL COMMENT 'Random slug untuk QR verify',
                public_slug_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = revoked',
                public_slug_scan_count INT UNSIGNED NOT NULL DEFAULT 0,
                public_slug_last_scan DATETIME NULL,
                -- Lifecycle
                published_at DATETIME NULL COMMENT 'Waktu status berubah ke published',
                expires_at DATETIME NULL COMMENT 'Optional auto-expire (mis. surat 30 hari)',
                -- Soft-delete
                deleted_at DATETIME NULL,
                deleted_by VARCHAR(100) NULL,
                -- Actor tracking
                created_by INT NULL COMMENT 'id_pegawai creator',
                updated_by INT NULL COMMENT 'id_pegawai last editor',
                -- Timestamps
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                -- Indexes
                INDEX idx_uuid (uuid),
                INDEX idx_template (template_id),
                INDEX idx_template_uuid (template_uuid),
                INDEX idx_norm_nopen (norm, nopen),
                INDEX idx_status (status, deleted_at),
                INDEX idx_slot (template_uuid, norm, nopen, label, deleted_at),
                INDEX idx_deleted (deleted_at),
                INDEX idx_public_slug (public_slug),
                INDEX idx_expires (expires_at),
                INDEX idx_created_by (created_by, created_at),
                UNIQUE KEY uk_slot_version (template_uuid, norm, nopen, label, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
];
