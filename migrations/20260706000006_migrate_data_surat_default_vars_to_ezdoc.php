<?php
/**
 * Copy data dari surat_default_vars → ezdoc_default_vars.
 * INSERT IGNORE untuk idempotent + backward-compat kalau row sudah di-seed dari
 * migration 20260706000003.
 */

return [
    'name' => '20260706000006_migrate_data_surat_default_vars_to_ezdoc',
    'up' => function ($conn): void {
        $srcCheck = @$conn->query("SHOW TABLES LIKE 'surat_default_vars'");
        if (!$srcCheck || $srcCheck->num_rows === 0) return;

        @$conn->query("
            INSERT IGNORE INTO ezdoc_default_vars (var_name, description, created_at)
            SELECT var_name, description, created_at FROM surat_default_vars
        ");
    },
];
