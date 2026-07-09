<?php
/**
 * ezdoc schema — auto-migrations untuk kolom yang ezdoc butuhkan.
 * Idempotent — safe call multiple times.
 *
 * Design: bikin di 1 tempat, dipanggil sekali dari bootstrap.php.
 * Kalau schema berubah, edit di sini saja (bukan tersebar di banyak file).
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
