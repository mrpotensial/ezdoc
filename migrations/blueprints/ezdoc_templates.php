<?php

declare(strict_types=1);

/**
 * Blueprint: ezdoc_templates
 *
 * Source of truth untuk `ezdoc_templates` schema. spec-dump CLI baca file
 * ini, generate ezdoc-spec/ddl/*.sql per platform + tables.yaml + tables.json.
 *
 * Runtime migration (Runner.php) untuk sekarang tetap pakai imperative SQL
 * di `migrations/2026_01_01_000001_create_ezdoc_templates.php` (backward
 * compat). v0.9.10 nanti Runner switch pakai Blueprint file ini juga —
 * single source of truth full.
 *
 * ## Design
 *
 * - UUID v7 stable identifier (family, sama across versions)
 * - Template versioning: uuid + version + is_current flag + parent chain
 * - JSON native untuk config columns (signature/layout/verify/access)
 * - Content integrity via content_hash SHA-256
 * - FULLTEXT index tidak di-scope Blueprint MVP v0.9.9 (grammar-specific,
 *   MyISAM-era feature; consumer bisa add via post-migration ALTER)
 */

use Ezdoc\Db\Schema\Blueprint;

return Blueprint::create('ezdoc_templates', function (Blueprint $t) {
    // BIGINT SIGNED (bukan UNSIGNED) — konsisten dgn existing prod schema.
    // Blueprint::id() shortcut default unsigned (Laravel-familiar); disini
    // explicit signed supaya FK ke id (juga BIGINT) tetap compatible.
    $t->bigint('id')->autoIncrement()->primary();

    // Identifiers
    $t->uuid('uuid')->comment('Template family ID (UUID v7, same across versions)');
    $t->string('slug', 120)->comment('URL-friendly identifier (per family)');

    // Versioning
    $t->integer('version')->unsigned()->default(1)->comment('Version dalam family');
    $t->boolean('is_current')->default(true)->comment('1 = active version untuk family');
    $t->bigint('parent_version_id')->nullable()->comment('Chain ke previous version');

    // Basic info
    $t->string('name', 255);
    $t->string('category', 100)->default('')->comment('Kategori/folder pengelompokan');
    $t->enum('scope', ['patient', 'general'])->default('patient');

    // Content
    $t->longText('content')->nullable()->comment('HTML template dari WYSIWYG editor');
    $t->string('content_hash', 64)->nullable()->comment('SHA-256 canonical content+configs');

    // Configs (JSON native)
    $t->json('signature_config')->nullable()->comment('Config TTD placeholders');
    $t->json('layout_config')->nullable()->comment('Config logos, page size, padding');
    $t->json('verify_config')->nullable()->comment('Field mana yg tampil di verify page');
    $t->json('access_config')->nullable()->comment('RBAC per-template');

    // Extensibility
    $t->json('metadata')->nullable()->comment('Custom fields per-tenant tanpa migration');

    // Ownership & state
    $t->bigint('owner_id')->nullable()->comment('ID pegawai/user creator');
    $t->boolean('is_active')->default(true)->comment('Soft-disable tanpa delete');
    $t->boolean('is_locked')->default(false)->comment('Prevent destructive field changes');

    // Optimistic locking
    $t->integer('revision')->unsigned()->default(1)->comment('Increment on each UPDATE');

    // Soft-delete
    $t->datetime('deleted_at')->nullable();
    $t->string('deleted_by', 100)->nullable();
    $t->text('deleted_reason')->nullable()->comment('Alasan delete untuk audit trail');

    // Actor + timestamps
    $t->bigint('created_by')->nullable();
    $t->bigint('updated_by')->nullable();
    $t->datetime('created_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');
    $t->datetime('updated_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');

    // Constraints
    $t->unique(['uuid', 'version'], 'uk_uuid_version');

    // Indexes untuk query patterns umum
    $t->index('uuid', 'idx_uuid');
    $t->index('slug', 'idx_slug');
    $t->index(['is_current', 'is_active', 'deleted_at'], 'idx_current_active');
    $t->index('category', 'idx_category');
    $t->index('scope', 'idx_scope');
    $t->index('owner_id', 'idx_owner');
    $t->index('parent_version_id', 'idx_parent');
    $t->index('updated_at', 'idx_updated');

    // FK self-referencing (version chain)
    $t->foreign('parent_version_id', 'ezdoc_templates', ['id'])
        ->name('fk_ezdoc_templates_parent')
        ->onDelete('set null');

    $t->comment('Template design storage dengan versioning + integrity check');
});
