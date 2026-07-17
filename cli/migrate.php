<?php
/**
 * CLI migration tool untuk ezdoc.
 *
 * Usage (dari terminal / CLI PHP):
 *   php ezdoc/cli/migrate.php [status|migrate|reset|fresh]
 *
 * Actions:
 *   status  — tampilkan yang applied vs pending (default)
 *   migrate — run pending migrations (self-heal auto)
 *   reset   — TRUNCATE registry (next migrate akan re-run semua)
 *   fresh   — reset + migrate (equivalent dengan drop+run semua)
 *
 * Butuh $conn dari koneksi.php di parent dir.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This tool must be run from CLI only.\n";
    exit(1);
}

// Load koneksi.php dari parent dir untuk dapatkan $conn
$koneksiPath = __DIR__ . '/../../koneksi.php';
if (!file_exists($koneksiPath)) {
    fwrite(STDERR, "Error: koneksi.php tidak ditemukan di {$koneksiPath}\n");
    exit(1);
}

// Bypass auth gate
$_GET['token'] = 'AyamCabeIjo';

require_once $koneksiPath;

if (!isset($conn) || !$conn) {
    fwrite(STDERR, "Error: koneksi DB gagal.\n");
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';

$action = $argv[1] ?? 'status';

echo "=== ezdoc migration tool ===\n";
echo "Migrations dir: " . EZDOC_ROOT . "/migrations\n";
echo "Action: {$action}\n\n";

switch ($action) {
    case 'status':
        $applied = [];
        $rs = @$conn->query("SHOW TABLES LIKE 'ezdoc_migrations'");
        if ($rs && $rs->num_rows > 0) {
            $rs2 = @$conn->query("SELECT migration, executed_at FROM ezdoc_migrations ORDER BY migration");
            if ($rs2) {
                while ($row = $rs2->fetch_assoc()) $applied[$row['migration']] = $row['executed_at'];
            }
        }
        $files = ezdoc_scan_migration_files();
        echo "Applied (" . count($applied) . "):\n";
        foreach ($applied as $name => $at) {
            echo "  ✓ {$name}  ({$at})\n";
        }
        $pending = [];
        foreach ($files as $f) {
            $spec = ezdoc_load_migration_file($f);
            if (!$spec) continue;
            if (!isset($applied[$spec['name']])) $pending[] = $spec['name'];
        }
        echo "\nPending (" . count($pending) . "):\n";
        foreach ($pending as $name) echo "  ○ {$name}\n";

        // Cek core tables
        echo "\nCore tables:\n";
        foreach (['ezdoc_templates', 'ezdoc_documents', 'ezdoc_default_vars', 'ezdoc_audit_log', 'ezdoc_migrations'] as $t) {
            $rs = @$conn->query("SHOW TABLES LIKE '{$t}'");
            $exists = $rs && $rs->num_rows > 0;
            echo "  " . ($exists ? "✓" : "✗") . " {$t}\n";
        }
        break;

    case 'migrate':
        $result = ezdoc_migrate($conn);
        if (!empty($result['healed'])) echo "→ Registry auto-healed (orphan detected)\n";
        echo "Applied: " . count($result['applied']) . "\n";
        foreach ($result['applied'] as $name) echo "  ✓ {$name}\n";
        echo "Skipped: " . count($result['skipped']) . "\n";
        foreach ($result['skipped'] as $name) echo "  ⏭  {$name}\n";
        if (!empty($result['failed'])) {
            echo "\nFAILED (" . count($result['failed']) . "):\n";
            foreach ($result['failed'] as $name => $err) {
                echo "  ✗ {$name}\n";
                echo "     Error: {$err}\n";
            }
        }
        break;

    case 'reset':
        ezdoc_migrate_reset($conn);
        echo "✓ Registry ezdoc_migrations di-truncate. Next migrate akan re-run semua.\n";
        break;

    case 'fresh':
        echo "→ Resetting registry...\n";
        ezdoc_migrate_reset($conn);
        echo "→ Running all migrations...\n";
        $result = ezdoc_migrate($conn);
        echo "Applied: " . count($result['applied']) . "\n";
        foreach ($result['applied'] as $name) echo "  ✓ {$name}\n";
        if (!empty($result['failed'])) {
            echo "\nFAILED (" . count($result['failed']) . "):\n";
            foreach ($result['failed'] as $name => $err) {
                echo "  ✗ {$name}: {$err}\n";
            }
        }
        break;

    default:
        fwrite(STDERR, "Unknown action: {$action}\nValid: status | migrate | reset | fresh\n");
        exit(1);
}

echo "\nDone.\n";
