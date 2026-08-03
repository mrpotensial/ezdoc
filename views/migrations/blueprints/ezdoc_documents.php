<?php

declare(strict_types=1);

/**
 * Blueprint: ezdoc_documents
 *
 * Document instances dari template dengan versioning + integrity + lifecycle.
 * Source of truth untuk spec-dump CLI.
 */

use Ezdoc\Db\Schema\Blueprint;

return Blueprint::create('ezdoc_documents', function (Blueprint $t) {
    // BIGINT SIGNED — konsisten dgn ezdoc_templates.id (SIGNED). FK
    // compatibility MySQL: matching sign/unsigned wajib.
    $t->bigint('id')->autoIncrement()->primary();

    // Identifier (UUID v7 time-ordered)
    $t->uuid('uuid')->unique()->comment('Stable UUID untuk API/external ref');

    // Template reference (snapshot at creation time). BIGINT SIGNED untuk
    // match ezdoc_templates.id (foreignId() shortcut default UNSIGNED, skip).
    $t->bigint('template_id')->comment('FK ke specific template version (immutable)');
    $t->uuid('template_uuid')->comment('Template family (denormalized untuk query cepat)');
    $t->integer('template_version')->unsigned()->default(1);

    // Identity
    $t->string('title', 255)->nullable()->comment('Human-readable name (auto-computed)');
    $t->string('norm', 50)->nullable()->comment('No Rekam Medis (nullable untuk general scope)');
    $t->string('nopen', 50)->nullable()->comment('No Pendaftaran (nullable untuk general scope)');
    $t->string('label', 100)->default('-')->comment('Pembeda dokumen dalam slot');

    // Versioning
    $t->integer('version')->unsigned()->default(1);

    // Data (JSON native)
    $t->json('field_values')->nullable()->comment('User-input field values');
    $t->json('signature_values')->nullable()->comment('Base64 signature images per TTD placeholder');

    // Extensibility
    $t->json('metadata')->nullable()->comment('Custom fields per-tenant tanpa migration');

    // Lifecycle
    $t->enum('status', ['draft', 'published', 'locked', 'archived'])->default('published');
    $t->boolean('is_locked')->default(false)->comment('Duplicate of status=locked untuk fast query');

    // Content integrity (Level 3 verify)
    $t->string('content_hash', 64)->nullable()->comment('SHA-256 canonical data');
    $t->datetime('content_hash_at')->nullable();
    $t->integer('content_hash_version')->unsigned()->nullable();

    // Public verify (Level 1-2)
    $t->string('public_slug', 32)->nullable()->comment('Random slug untuk QR verify');
    $t->boolean('public_slug_active')->default(true)->comment('0 = revoked');
    $t->integer('public_slug_scan_count')->unsigned()->default(0);
    $t->datetime('public_slug_last_scan')->nullable();

    // Lifecycle timestamps
    $t->datetime('published_at')->nullable()->comment('Waktu status = published');
    $t->datetime('expires_at')->nullable()->comment('Auto-expire timestamp (optional)');

    // Optimistic locking
    $t->integer('revision')->unsigned()->default(1)->comment('Increment on each UPDATE');

    // Soft-delete
    $t->datetime('deleted_at')->nullable();
    $t->string('deleted_by', 100)->nullable();
    $t->text('deleted_reason')->nullable()->comment('Alasan delete untuk audit trail');

    // Actor tracking
    $t->bigint('created_by')->nullable();
    $t->bigint('updated_by')->nullable();

    // Timestamps
    $t->datetime('created_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');
    $t->datetime('updated_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');

    // Constraints
    $t->unique(['template_uuid', 'norm', 'nopen', 'label', 'version'], 'uk_slot_version');
    $t->unique('public_slug', 'uk_public_slug');

    // Indexes
    $t->index('uuid', 'idx_uuid');
    $t->index('template_id', 'idx_template');
    $t->index('template_uuid', 'idx_template_uuid');
    $t->index(['norm', 'nopen'], 'idx_norm_nopen');
    $t->index(['status', 'deleted_at'], 'idx_status_deleted');
    $t->index(['template_uuid', 'norm', 'nopen', 'label', 'deleted_at'], 'idx_slot');
    $t->index('deleted_at', 'idx_deleted');
    $t->index('expires_at', 'idx_expires');
    $t->index(['created_by', 'created_at'], 'idx_created_by');
    $t->index('updated_at', 'idx_updated');

    // FK ke template (specific version)
    $t->foreign('template_id', 'ezdoc_templates', ['id'])
        ->name('fk_ezdoc_documents_template')
        ->onDelete('restrict');

    $t->comment('Document instances dari template dengan versioning + integrity + lifecycle');
});
