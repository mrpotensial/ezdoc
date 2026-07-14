<?php

declare(strict_types=1);

/**
 * ezdoc demo server launcher.
 *
 * Usage:
 *   php ezdoc/cli/serve.php               # runs on 127.0.0.1:8765
 *   EZDOC_HOST=0.0.0.0 EZDOC_PORT=9090 php ezdoc/cli/serve.php
 *
 * What it does:
 *   1. Auto-migrate a temp SQLite DB (skipped if already exists)
 *   2. Seed 3 sample templates
 *   3. Spawn PHP's built-in web server rooted at ezdoc/public/
 *   4. Print the URL to open in a browser
 *
 * The actual request handling lives in ezdoc/public/index.php which just
 * calls Ezdoc\App::demo().
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli/serve.php must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../autoload.php';

$host = getenv('EZDOC_HOST');
if (!is_string($host) || $host === '') {
    $host = '127.0.0.1';
}
$port = (int) (getenv('EZDOC_PORT') ?: 8765);
if ($port <= 0) {
    $port = 8765;
}

$publicDir = realpath(__DIR__ . '/../public');
if ($publicDir === false || !is_dir($publicDir)) {
    fwrite(STDERR, "public/ directory not found. Expected: " . __DIR__ . "/../public\n");
    exit(1);
}

// Pre-warm the SQLite DB so first request feels instant.
if (extension_loaded('pdo_sqlite')) {
    try {
        $dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ezdoc-demo.sqlite';
        $isFresh = !is_file($dbPath);
        // Use the same code path App::demo() will hit — via App::run() with a temp req.
        \Ezdoc\App::bootstrap(['app.db' => new \PDO('sqlite:' . $dbPath)]);
        if ($isFresh) {
            fwrite(STDOUT, "[ezdoc] Created fresh SQLite demo DB at: {$dbPath}\n");
        } else {
            fwrite(STDOUT, "[ezdoc] Reusing existing SQLite demo DB at: {$dbPath}\n");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[ezdoc] WARN: pre-warm failed — first request will provision instead. Reason: " . $e->getMessage() . "\n");
    }
} else {
    fwrite(STDERR, "[ezdoc] WARN: pdo_sqlite extension not loaded — demo will run UI-only.\n");
}

$url = "http://{$host}:{$port}/?ezdoc_page=list";
fwrite(STDOUT, "\n");
fwrite(STDOUT, "  ezdoc demo server ready\n");
fwrite(STDOUT, "  ─────────────────────────\n");
fwrite(STDOUT, "  Open:     {$url}\n");
fwrite(STDOUT, "  Designer: http://{$host}:{$port}/?ezdoc_page=designer\n");
fwrite(STDOUT, "  Generate: http://{$host}:{$port}/?ezdoc_page=generate\n");
fwrite(STDOUT, "  Stop:     Ctrl+C\n\n");

$cmd = sprintf(
    'php -S %s:%d -t %s',
    escapeshellarg($host),
    $port,
    escapeshellarg($publicDir)
);
passthru($cmd, $exitCode);
exit((int) $exitCode);
