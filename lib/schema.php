<?php
/**
 * ezdoc schema — LEGACY consumer-specific auto-migrations.
 *
 * ## ⚠️ DEPRECATED as of v0.9.10 — will be removed in v1.0
 *
 * File ini mengelola tabel **consumer-app-specific** (`surat_template_v2`,
 * `surat_audit_log`) yang merupakan legacy tables dari dogfood consumer
 * (SIMpel/RSIA). BUKAN tabel ezdoc library (`ezdoc_templates`,
 * `ezdoc_audit_log`) yang di-manage via proper migrations runner di
 * `ezdoc/migrations/`.
 *
 * Consumer apps yang punya legacy `surat_*` tables → copy migration ini
 * ke consumer's own bootstrap (bukan di ezdoc library). Untuk v1.0 Packagist
 * release, file ini akan dihapus.
 *
 * Idempotent — safe call multiple times.
 *
 * @deprecated Since v0.9.10 — akan dihapus di v1.0. Consumer migrate legacy
 *             tables via consumer's own bootstrap (not via library).
 */

if (defined('EZDOC_SCHEMA_LOADED')) return;
define('EZDOC_SCHEMA_LOADED', true);

/**
 * Auto-migrate kolom yang ezdoc butuhkan.
 * Silent fail per migration (log error, tidak crash) supaya production tidak down
 * kalau permission tidak cukup untuk ALTER.
 *
 * @param \mysqli|null $conn
 */
function ezdoc_ensure_schema($conn): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (!$conn) return;

    // ─── surat_template_v2: kolom access_config (RBAC per-template) ───
    $existing = [];
    $rs = @$conn->query("SHOW COLUMNS FROM surat_template_v2");
    if ($rs) {
        while ($c = $rs->fetch_assoc()) $existing[$c['Field']] = true;
    }

    if (!empty($existing) && !isset($existing['access_config'])) {
        @$conn->query("
            ALTER TABLE surat_template_v2
            ADD COLUMN access_config TEXT NULL DEFAULT NULL
            COMMENT 'JSON: RBAC config per-template (create, edit, lock, mode: strict|permissive)'
            AFTER verify_config
        ");
    }

    // ─── surat_audit_log: persistent event trail untuk compliance ───
    // Idempotent CREATE TABLE — safe untuk semua environment.
    @$conn->query("
        CREATE TABLE IF NOT EXISTS surat_audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL COMMENT 'e.g. doc.created, doc.updated, template.saved, authz.denied',
            actor_id INT NULL COMMENT 'id_pegawai (NULL untuk public/system events)',
            actor_roles VARCHAR(255) NULL COMMENT 'snapshot roles saat event terjadi (comma-separated)',
            actor_type ENUM('user','system','public') NOT NULL DEFAULT 'user',
            target_type VARCHAR(32) NULL COMMENT 'template | document | signature | verification',
            target_id VARCHAR(64) NULL COMMENT 'id target (bisa non-int untuk slug)',
            template_id INT NULL COMMENT 'FK-like reference untuk filtering',
            doc_id INT NULL COMMENT 'FK-like reference untuk filtering',
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            metadata TEXT NULL COMMENT 'JSON context-specific per event type',
            result ENUM('success','denied','error') NOT NULL DEFAULT 'success',
            message TEXT NULL,
            occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_actor (actor_id, occurred_at),
            INDEX idx_target (target_type, target_id),
            INDEX idx_template (template_id, occurred_at),
            INDEX idx_doc (doc_id, occurred_at),
            INDEX idx_time (occurred_at),
            INDEX idx_result (result, occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
