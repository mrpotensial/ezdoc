<?php

/**
 * ezdoc autoloader — PSR-4 dengan polyfill layer.
 *
 * Load order:
 *   1. Polyfill untuk PHP 8+ functions (str_starts_with, dll)
 *   2. Composer autoload kalau available
 *   3. Fallback: custom PSR-4 loader untuk Ezdoc\ namespace
 *
 * Target: PHP 7.4+ (forward-compatible dengan PHP 8.x).
 */

if (defined('EZDOC_AUTOLOAD_LOADED')) return;
define('EZDOC_AUTOLOAD_LOADED', true);

// Step 1: Load polyfill duluan sebelum apapun.
// Kalau autoloader kita pakai str_starts_with, harus available dulu.
require_once __DIR__ . '/lib/polyfill.php';

// Step 2: Try Composer autoload
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    return;
}

// Step 3: Fallback PSR-4 loader untuk Ezdoc\ namespace
spl_autoload_register(function ($class) {
    if (!str_starts_with($class, 'Ezdoc\\')) return;

    $relative = substr($class, strlen('Ezdoc\\'));
    $path = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});
