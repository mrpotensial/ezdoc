<?php
/**
 * CLI publisher untuk ezdoc.
 *
 * Copy views / assets / sample config dari library folder ke consumer app directory.
 * DB-free — tidak memerlukan koneksi.php (pure filesystem).
 *
 * Usage:
 *   php ezdoc/cli/publish.php <command> [target_dir] [--force]
 *
 * Commands:
 *   views    <target_dir>   Copy views/ ke target
 *   assets   <target_dir>   Copy assets/ ke target
 *   config   <target_dir>   Copy sample config ke target
 *   all      <target_dir>   Copy semua (views + assets + config) ke sub-folder target
 *   list                    List semua file yang akan di-copy (dry-run)
 *   help                    Tampilkan help ini
 *
 * Flags:
 *   --force   Overwrite target file kalau sudah ada
 *
 * Exit codes:
 *   0   sukses (semua file copied/skipped tanpa error)
 *   1   error (source hilang, permission denied, dsb)
 *   2   usage error (argumen invalid / missing)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This tool must be run from CLI only.\n";
    exit(1);
}

// Bootstrap autoloader saja (tanpa DB — publisher tidak butuh koneksi)
require_once __DIR__ . '/../autoload.php';

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\UI\PublishCommand;

// ─── Parse argv ──────────────────────────────────────────────────────
$argv = isset($argv) ? $argv : [];
$scriptName = basename(isset($argv[0]) ? $argv[0] : 'publish.php');
$positional = [];
$force = false;
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--force' || $arg === '-f') {
        $force = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        print_usage($scriptName);
        exit(0);
    } else {
        $positional[] = $arg;
    }
}

if ($positional === []) {
    print_usage($scriptName);
    exit(2);
}

$command = $positional[0];
$targetDir = isset($positional[1]) ? $positional[1] : '';

$libraryRoot = realpath(__DIR__ . '/..');
if ($libraryRoot === false) {
    fwrite(STDERR, "Error: gagal resolve library root.\n");
    exit(1);
}

try {
    $publisher = new PublishCommand($libraryRoot);

    echo "=== ezdoc publish tool ===\n";
    echo "Library root : {$libraryRoot}\n";
    echo "Command      : {$command}\n";
    if ($command !== 'list' && $command !== 'help') {
        echo "Target       : {$targetDir}\n";
        echo "Force        : " . ($force ? 'yes' : 'no') . "\n";
    }
    echo "\n";

    switch ($command) {
        case 'help':
            print_usage($scriptName);
            exit(0);

        case 'list':
            $items = $publisher->listPublishable();
            if ($items === []) {
                echo "(nothing to publish — views/, assets/, config.sample.php belum ada)\n";
                exit(0);
            }
            $grouped = ['view' => [], 'asset' => [], 'config' => []];
            foreach ($items as $it) $grouped[$it['type']][] = $it;
            foreach ($grouped as $type => $rows) {
                if ($rows === []) continue;
                echo strtoupper($type) . "S (" . count($rows) . "):\n";
                foreach ($rows as $r) {
                    echo "  - {$r['source']}\n";
                    echo "      → suggested: {$r['targetSuggestion']}\n";
                }
                echo "\n";
            }
            exit(0);

        case 'views':
            require_target($targetDir, $scriptName);
            $results = $publisher->publishViews($targetDir, $force);
            break;

        case 'assets':
            require_target($targetDir, $scriptName);
            $results = $publisher->publishAssets($targetDir, $force);
            break;

        case 'config':
            require_target($targetDir, $scriptName);
            $results = $publisher->publishConfig($targetDir, $force);
            break;

        case 'all':
            require_target($targetDir, $scriptName);
            $results = $publisher->publishAll($targetDir, $force);
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n\n");
            print_usage($scriptName);
            exit(2);
    }

    // ─── Report ─────────────────────────────────────────────────────
    $counts = ['copied' => 0, 'skipped' => 0, 'failed' => 0];
    foreach ($results as $r) {
        $status = strtoupper($r['status']);
        $label = $r['status'] === 'copied' ? '[COPY]'
              : ($r['status'] === 'skipped' ? '[SKIP]' : '[FAIL]');
        echo "{$label} {$r['file']}";
        if (($r['status'] === 'skipped' || $r['status'] === 'failed') && !empty($r['reason'])) {
            echo "  — {$r['reason']}";
        }
        echo "\n";
        if (isset($counts[$r['status']])) $counts[$r['status']]++;
    }
    echo "\n";
    echo "Summary: {$counts['copied']} copied, {$counts['skipped']} skipped, {$counts['failed']} failed\n";
    exit($counts['failed'] > 0 ? 1 : 0);

} catch (EzdocException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    $ctx = $e->getContext();
    if (!empty($ctx)) {
        fwrite(STDERR, "Context: " . json_encode($ctx) . "\n");
    }
    exit(1);
} catch (\Throwable $t) {
    fwrite(STDERR, "Fatal: " . $t->getMessage() . "\n");
    fwrite(STDERR, $t->getTraceAsString() . "\n");
    exit(1);
}

// ─── Helpers ────────────────────────────────────────────────────────

function print_usage($scriptName) {
    echo <<<TXT
Usage:
  php {$scriptName} <command> [target_dir] [--force]

Commands:
  views    <target_dir>   Copy views/ ke target
  assets   <target_dir>   Copy assets/ ke target
  config   <target_dir>   Copy sample config ke target
  all      <target_dir>   Copy semua (views + assets + config) ke sub-folder target
  list                    List file yang akan di-copy (dry-run)
  help                    Tampilkan pesan ini

Flags:
  --force, -f   Overwrite target file kalau sudah ada
  --help,  -h   Tampilkan help

Contoh:
  php {$scriptName} list
  php {$scriptName} views /var/www/app/resources/views/ezdoc
  php {$scriptName} all C:/app/public/vendor/ezdoc --force

Exit codes: 0 sukses, 1 error, 2 usage error.

TXT;
}

function require_target($targetDir, $scriptName) {
    if ($targetDir === '' || $targetDir === null) {
        fwrite(STDERR, "Error: target_dir wajib untuk command ini.\n\n");
        print_usage($scriptName);
        exit(2);
    }
}
