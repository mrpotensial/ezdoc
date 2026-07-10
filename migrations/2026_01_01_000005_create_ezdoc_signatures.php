<?php
/**
 * Create ezdoc_signatures — signature envelopes per document.
 *
 * Menyimpan satu row per signer per document (multi-signer via
 * signature_id_within_doc: 0..N). Mendukung tiga level assurance:
 *   L1: 'hmac'      — envelope_format='hmac',  no cert, fastest
 *   L2: 'local_pki' — envelope_format='raw'|'pkcs7', local X.509
 *   L3: 'peruri'    — envelope_format='pades', PSrE-issued cert + TSA
 *
 * Query patterns:
 *   Signatures of doc:     WHERE document_id = ? ORDER BY signature_id_within_doc
 *   Signer history:        WHERE signer_id = ? ORDER BY signed_at DESC
 *   Pending verify:        WHERE verify_status = 'pending'
 *   Revocation lookup:     WHERE certificate_serial = ?
 *   Provider stats:        WHERE provider = ? AND level = ?
 *
 * FK ke ezdoc_documents.id dengan ON DELETE RESTRICT — signatures tidak
 * boleh hilang kalau doc dihapus (compliance). Soft-delete via deleted_at.
 *
 * envelope + tsa_response = MEDIUMBLOB (up to 16MB) untuk PKCS7/PAdES yang
 * bisa besar (terutama dengan embedded certificate chain).
 */

return [
    'name' => '2026_01_01_000005_create_ezdoc_signatures',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_signatures (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL,
                document_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to ezdoc_documents.id',
                signature_id_within_doc TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0..N for multi-signer docs',
                -- Signer identity
                signer_id VARCHAR(128) NOT NULL COMMENT 'Opaque identity string (domain-agnostic)',
                signer_role VARCHAR(64) NULL COMMENT 'Role at signing time (snapshot)',
                signer_user_id BIGINT UNSIGNED NULL COMMENT 'Consumer user id if available',
                -- Signature envelope
                provider VARCHAR(32) NOT NULL COMMENT 'hmac | local_pki | peruri | ...',
                level TINYINT UNSIGNED NOT NULL COMMENT '1 | 2 | 3 (assurance level)',
                envelope_format VARCHAR(32) NOT NULL COMMENT 'hmac | raw | pkcs7 | pades',
                envelope MEDIUMBLOB NOT NULL COMMENT 'Raw signature bytes',
                content_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hex of signed content',
                content_hash_algo VARCHAR(16) NOT NULL DEFAULT 'sha256',
                -- Certificate (L2/L3)
                certificate_pem TEXT NULL COMMENT 'Signer X.509 certificate (PEM)',
                certificate_serial VARCHAR(64) NULL COMMENT 'For revocation check',
                certificate_subject VARCHAR(255) NULL COMMENT 'Subject CN',
                certificate_issuer VARCHAR(255) NULL COMMENT 'Issuer CN',
                -- Timestamp authority (v0.8)
                tsa_response MEDIUMBLOB NULL COMMENT 'RFC 3161 timestamp token',
                -- Timing
                signed_at DATETIME(3) NOT NULL COMMENT 'Millisecond precision',
                verified_at DATETIME(3) NULL COMMENT 'Last verify time',
                -- Verification result
                verify_status ENUM('valid','tampered','expired','revoked','untrusted','error','pending') NOT NULL DEFAULT 'pending',
                verify_reason VARCHAR(255) NULL,
                -- Extensibility
                metadata JSON NULL,
                -- Audit timestamps
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                -- Indexes
                UNIQUE KEY uq_uuid (uuid),
                INDEX idx_document (document_id),
                INDEX idx_signer (signer_id),
                INDEX idx_provider_level (provider, level),
                INDEX idx_signed_at (signed_at),
                INDEX idx_verify_status (verify_status),
                INDEX idx_cert_serial (certificate_serial),
                CONSTRAINT fk_ezdoc_signatures_document
                    FOREIGN KEY (document_id) REFERENCES ezdoc_documents(id)
                    ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Signature envelopes per document — L1 HMAC, L2 LocalPKI, L3 PSrE'
        ");
    },
];
