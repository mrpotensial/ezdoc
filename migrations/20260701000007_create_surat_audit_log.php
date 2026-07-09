<?php
/**
 * Create surat_audit_log — persistent event trail untuk compliance.
 */

return [
    'name' => '20260701000007_create_surat_audit_log',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS surat_audit_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(64) NOT NULL COMMENT 'e.g. doc.created, template.saved, authz.denied',
                actor_id INT NULL COMMENT 'id_pegawai (NULL untuk public/system events)',
                actor_roles VARCHAR(255) NULL COMMENT 'snapshot roles saat event',
                actor_type ENUM('user','system','public') NOT NULL DEFAULT 'user',
                target_type VARCHAR(32) NULL,
                target_id VARCHAR(64) NULL,
                template_id INT NULL,
                doc_id INT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                metadata TEXT NULL COMMENT 'JSON context per event',
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
    },
];
