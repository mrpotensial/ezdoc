<?php

declare(strict_types=1);

/**
 * Blueprint: ezdoc_signatures
 *
 * Signature envelopes per document. Multi-signer via signature_id_within_doc.
 * Support 3 assurance levels: L1 HMAC, L2 LocalPKI, L3 Peruri.
 * Source of truth untuk spec-dump CLI.
 *
 * Note: `envelope` + `tsa_response` original MEDIUMBLOB. Blueprint DSL saat
 * ini pakai `binary()` → 'blob' canonical name → Grammar map ke LONGBLOB
 * (MySQL) / BYTEA (Postgres) / BLOB (SQLite). Consumer bisa override via
 * grammar-specific override kalau butuh MEDIUMBLOB precision.
 */

use Ezdoc\Db\Schema\Blueprint;

return Blueprint::create('ezdoc_signatures', function (Blueprint $t) {
    // BIGINT SIGNED — sinkron dgn ezdoc_documents.id (SIGNED). FK compat.
    $t->bigint('id')->autoIncrement()->primary();
    $t->uuid('uuid')->unique();

    $t->bigint('document_id')->comment('FK to ezdoc_documents.id');
    $t->integer('signature_id_within_doc')->unsigned()->default(0)
        ->comment('0..N for multi-signer docs');

    // Signer identity
    $t->string('signer_id', 128)->comment('Opaque identity string (domain-agnostic)');
    $t->string('signer_role', 64)->nullable()->comment('Role at signing time (snapshot)');
    $t->bigint('signer_user_id')->nullable()->comment('Consumer user id if available');

    // Signature envelope
    $t->string('provider', 32)->comment('hmac | local_pki | peruri | ...');
    $t->integer('level')->unsigned()->comment('1 | 2 | 3 (assurance level)');
    $t->string('envelope_format', 32)->comment('hmac | raw | pkcs7 | pades');
    $t->binary('envelope')->comment('Raw signature bytes');
    $t->string('content_hash', 64)->comment('SHA-256 hex of signed content');
    $t->string('content_hash_algo', 16)->default('sha256');

    // Certificate (L2/L3)
    $t->text('certificate_pem')->nullable()->comment('Signer X.509 certificate (PEM)');
    $t->string('certificate_serial', 64)->nullable()->comment('For revocation check');
    $t->string('certificate_subject', 255)->nullable()->comment('Subject CN');
    $t->string('certificate_issuer', 255)->nullable()->comment('Issuer CN');

    // Timestamp authority (v0.8)
    $t->binary('tsa_response')->nullable()->comment('RFC 3161 timestamp token');

    // Timing
    $t->datetime('signed_at')->comment('Signature timestamp');
    $t->datetime('verified_at')->nullable()->comment('Last verify time');

    // Verification result
    $t->enum('verify_status', ['valid', 'tampered', 'expired', 'revoked', 'untrusted', 'error', 'pending'])
        ->default('pending');
    $t->string('verify_reason', 255)->nullable();

    // Extensibility
    $t->json('metadata')->nullable();

    // Audit timestamps
    $t->datetime('created_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');
    $t->datetime('updated_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');
    $t->datetime('deleted_at')->nullable();

    // Indexes
    $t->index('document_id', 'idx_document');
    $t->index('signer_id', 'idx_signer');
    $t->index(['provider', 'level'], 'idx_provider_level');
    $t->index('signed_at', 'idx_signed_at');
    $t->index('verify_status', 'idx_verify_status');
    $t->index('certificate_serial', 'idx_cert_serial');

    // FK ke document
    $t->foreign('document_id', 'ezdoc_documents', ['id'])
        ->name('fk_ezdoc_signatures_document')
        ->onDelete('restrict')
        ->onUpdate('cascade');

    $t->comment('Signature envelopes per document — L1 HMAC, L2 LocalPKI, L3 PSrE');
});
