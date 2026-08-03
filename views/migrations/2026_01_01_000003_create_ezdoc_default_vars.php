<?php
/**
 * Create ezdoc_default_vars — whitelist default variables untuk template placeholder.
 * Simple lookup table.
 */

return [
    'name' => '2026_01_01_000003_create_ezdoc_default_vars',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_default_vars (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                var_name VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255) NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft-disable tanpa delete',
                metadata JSON NULL COMMENT 'Extensibility',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_enabled (is_enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Whitelist default variables untuk template placeholder'
        ");

        // Seed defaults
        $seedVars = [
            ['author_nama', 'Nama pegawai yang login'],
            ['author_id', 'ID pegawai yang login'],
            ['author_id_simgos', 'ID SIMGOS user yang login'],
            ['author_nama_ruangan', 'Nama ruangan user'],
            ['author_id_ruangan', 'ID ruangan user'],
            ['author_initial', 'Inisial nama user'],
        ];
        $stmt = @mysqli_prepare($conn, "INSERT IGNORE INTO ezdoc_default_vars (var_name, description) VALUES (?, ?)");
        if ($stmt) {
            foreach ($seedVars as $sv) {
                mysqli_stmt_bind_param($stmt, "ss", $sv[0], $sv[1]);
                @mysqli_stmt_execute($stmt);
            }
        }
    },
];
