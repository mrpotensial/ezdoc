<?php
/**
 * Create ezdoc_audit_log — persistent event trail untuk compliance.
 *
 * Improvements dari draft sebelumnya:
 *   - session_id + trace_id untuk distributed tracing (future-proof)
 *   - request_id untuk correlate multiple events per HTTP request
 *   - previous_value + new_value untuk field-level change tracking
 *   - Composite indexes lebih strategis (kombinasi actor+event+time)
 *
 * Query patterns (untuk viewer + compliance report):
 *   Timeline user:    WHERE actor_id = ? ORDER BY occurred_at DESC
 *   Doc history:      WHERE doc_id = ? ORDER BY occurred_at DESC
 *   Failed access:    WHERE event_type LIKE 'authz.%' AND result = 'denied'
 *   Public scans:     WHERE actor_type = 'public' AND event_type = 'verify.scanned'
 *
 * Retention: typically 12-24 months untuk hospital compliance,
 * archive ke cold storage after that.
 */

return [
    'name' => '2026_01_01_000004_create_ezdoc_audit_log',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_audit_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                -- Event identification
                event_type VARCHAR(64) NOT NULL COMMENT 'e.g. doc.created, template.saved, authz.denied',
                event_uuid CHAR(36) NULL COMMENT 'UUID v7 untuk correlate cross-system (optional)',
                -- Actor context
                actor_id BIGINT NULL COMMENT 'id_pegawai/user (NULL untuk public/system)',
                actor_roles VARCHAR(255) NULL COMMENT 'Snapshot roles saat event',
                actor_type ENUM('user','system','public','api') NOT NULL DEFAULT 'user',
                -- Target entity
                target_type VARCHAR(32) NULL COMMENT 'template | document | signature | verification',
                target_id VARCHAR(64) NULL COMMENT 'ID target (bisa non-int untuk slug/uuid)',
                -- Denormalized refs untuk filter cepat
                template_id BIGINT NULL,
                doc_id BIGINT NULL,
                -- Request context
                ip_address VARCHAR(45) NULL COMMENT 'IPv4 or IPv6',
                user_agent TEXT NULL,
                request_id CHAR(36) NULL COMMENT 'UUID v7 untuk correlate events per HTTP request',
                session_id VARCHAR(64) NULL COMMENT 'Session identifier untuk timeline user',
                trace_id CHAR(36) NULL COMMENT 'Distributed tracing (OpenTelemetry compatible)',
                -- Change tracking (field-level)
                previous_value JSON NULL COMMENT 'State sebelum change (untuk UPDATE events)',
                new_value JSON NULL COMMENT 'State setelah change',
                -- Extensibility
                metadata JSON NULL COMMENT 'Context-specific per event type',
                -- Result
                result ENUM('success','denied','error','warning') NOT NULL DEFAULT 'success',
                message TEXT NULL COMMENT 'Human-readable summary',
                -- Timestamp
                occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'Millisecond precision',
                -- Indexes (composite untuk query patterns)
                INDEX idx_event_time (event_type, occurred_at),
                INDEX idx_actor_time (actor_id, occurred_at),
                INDEX idx_target (target_type, target_id),
                INDEX idx_template_time (template_id, occurred_at),
                INDEX idx_doc_time (doc_id, occurred_at),
                INDEX idx_time (occurred_at),
                INDEX idx_result_time (result, occurred_at),
                INDEX idx_request (request_id),
                INDEX idx_session (session_id),
                INDEX idx_trace (trace_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Audit log untuk compliance + event sourcing (append-only)'
        ");
    },
];
