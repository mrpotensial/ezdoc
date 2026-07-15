<?php

declare(strict_types=1);

/**
 * Blueprint: ezdoc_default_vars
 *
 * Whitelist default variables untuk template placeholder. Simple lookup
 * table. Source of truth untuk spec-dump CLI.
 */

use Ezdoc\Db\Schema\Blueprint;

return Blueprint::create('ezdoc_default_vars', function (Blueprint $t) {
    $t->id();
    $t->string('var_name', 100)->unique();
    $t->string('description', 255)->nullable();
    $t->boolean('is_enabled')->default(true)->comment('Soft-disable tanpa delete');
    $t->json('metadata')->nullable()->comment('Extensibility');
    $t->datetime('created_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');
    $t->datetime('updated_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');

    $t->index('is_enabled', 'idx_enabled');

    $t->comment('Whitelist default variables untuk template placeholder');
});
