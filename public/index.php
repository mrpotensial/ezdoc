<?php

declare(strict_types=1);

/**
 * ezdoc demo — front-controller entry.
 *
 * This file is the single mount point for `php -S ... -t ezdoc/public/`.
 * It resolves autoload, then either:
 *   - runs `Ezdoc\App::run(require config.php)` if a sibling config.php exists, or
 *   - falls back to `Ezdoc\App::demo()` for zero-config SQLite mode.
 *
 * A consumer app never needs to touch this file — it's a canonical example
 * of the 1-line mount pattern for the built-in dev server.
 */

// ini_set('display_errors', '1');
// error_reporting(E_ALL);

// ─── Autoload resolution ──────────────────────────────────────────────
// ALWAYS load library's own PSR-4 loader first — supaya \Ezdoc\* classes
// available bahkan kalau consumer punya Composer autoload sendiri yang tidak
// include ezdoc (mis. ezdoc di-drop sebagai submodule/subtree, bukan composer
// require). ADDITIONALLY: load Composer kalau ada — untuk consumer's other
// deps (tanpa override ezdoc PSR-4).
$libAutoload = __DIR__ . '/../autoload.php';
if (!is_file($libAutoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ezdoc: library autoload missing at {$libAutoload}\n";
    exit;
}
require_once $libAutoload;

// Optional Composer autoload — extends dengan consumer deps kalau ada.
$composerAutoloads = [
    __DIR__ . '/../vendor/autoload.php',       // library dev install (composer require)
    __DIR__ . '/../../vendor/autoload.php',    // consumer app-level Composer
];
foreach ($composerAutoloads as $path) {
    if (is_file($path)) {
        require_once $path;
    }
}

// Final sanity check
if (!class_exists(\Ezdoc\App::class)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ezdoc: \\Ezdoc\\App class not found after autoload. Library install seems incomplete.\n";
    exit;
}

// ─── Config discovery ────────────────────────────────────────────────
// If someone drops a `public/config.php` next to this file, prefer it.
// Otherwise: zero-config demo mode.
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    /** @psalm-suppress UnresolvableInclude */
    $config = require $configFile;
    if (!is_array($config)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ezdoc: config.php must return an array, got " . gettype($config) . "\n";
        exit;
    }
    \Ezdoc\App::run($config);
} else {
    \Ezdoc\App::demo();
}
