<?php
/**
 * Create ezdoc_default_vars — whitelist default variables untuk template.
 * Renamed dari surat_default_vars.
 */

return [
    'name' => '20260706000003_create_ezdoc_default_vars',
    'up' => function ($conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ezdoc_default_vars (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                var_name VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
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
