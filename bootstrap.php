<?php
/**
 * ezdoc bootstrap — load-once entry point.
 *
 * Order matters:
 *   1. autoload.php — Composer OR fallback PSR-4 (untuk Ezdoc\* namespaced classes)
 *   2. config.php   — feature flags, path constants
 *   3. lib/*.php    — global function wrappers (backward compat untuk existing code)
 *   4. Auto-migrate — kalau $conn tersedia & EZDOC_AUTO_MIGRATE = true
 *   5. Sanity check — pastikan core tables ada setelah migration
 *
 * Config constants (bisa di-define BEFORE require bootstrap):
 *   EZDOC_AUTO_MIGRATE = false → skip auto-migrate saat bootstrap
 *   EZDOC_ENFORCE_RBAC = false → permissive mode
 *   EZDOC_TEMPLATE_MANAGER_ROLES → role yang boleh manage template
 *   EZDOC_STRICT_SETUP = true → tampilkan error page kalau tables missing (default true)
 */

if (defined('EZDOC_LOADED')) return;
define('EZDOC_LOADED', true);

// Feature flag default
if (!defined('EZDOC_AUTO_MIGRATE')) define('EZDOC_AUTO_MIGRATE', true);
if (!defined('EZDOC_STRICT_SETUP')) define('EZDOC_STRICT_SETUP', true);

// PSR-4 autoloader (Composer or fallback) — MUST BE FIRST
require_once __DIR__ . '/autoload.php';

require_once __DIR__ . '/config.php';
require_once EZDOC_LIB . '/responses.php';
require_once EZDOC_LIB . '/uuid.php';
require_once EZDOC_LIB . '/role_provider.php';
require_once EZDOC_LIB . '/authorization.php';
require_once EZDOC_LIB . '/schema.php';
require_once EZDOC_LIB . '/migrations.php';
require_once EZDOC_LIB . '/audit.php';

// Auto-run migrations (menggunakan Ezdoc\Migrations\Runner via wrapper)
if (EZDOC_AUTO_MIGRATE && isset($GLOBALS['conn']) && $GLOBALS['conn']) {
    $__migResult = ezdoc_migrate($GLOBALS['conn']);
    // Log migration result untuk debugging (via error_log — akan muncul di PHP error log)
    if (!empty($__migResult['applied'])) {
        @error_log('[ezdoc:migrate] Applied: ' . implode(', ', $__migResult['applied']));
    }
    if (!empty($__migResult['failed'])) {
        foreach ($__migResult['failed'] as $__name => $__err) {
            @error_log("[ezdoc:migrate] FAILED {$__name}: {$__err}");
        }
    }
    if (!empty($__migResult['healed'])) {
        @error_log('[ezdoc:migrate] Registry auto-healed');
    }
    @ezdoc_ensure_schema($GLOBALS['conn']); // legacy verify_helpers schema (silent)
    unset($__migResult, $__name, $__err);
}

// ─── Sanity check ───
// Kalau EZDOC_STRICT_SETUP=true dan core tables tidak ada setelah migration,
// tampilkan error page friendly (bukan blank white PHP fatal downstream).
if (EZDOC_STRICT_SETUP && isset($GLOBALS['conn']) && $GLOBALS['conn']) {
    $__ezdocConn = $GLOBALS['conn'];
    $__ezdocTables = ['ezdoc_templates', 'ezdoc_documents', 'ezdoc_signatures'];
    $__ezdocMissing = [];
    foreach ($__ezdocTables as $__t) {
        $__rs = @mysqli_query($__ezdocConn, "SHOW TABLES LIKE '{$__t}'");
        if (!$__rs || mysqli_num_rows($__rs) === 0) $__ezdocMissing[] = $__t;
    }
    if (!empty($__ezdocMissing)) {
        // Skip check kalau ini AJAX/action request — biar handler return proper JSON error
        $__isAjax = (
            ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' ||
            !empty($_POST['ajax']) || !empty($_POST['_ajax']) || !empty($_POST['_doc_action']) ||
            (($_GET['action'] ?? '') === 'generate_qr')
        );
        if (!$__isAjax) {
            http_response_code(500);
            $__missingList = implode(', ', $__ezdocMissing);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Diperlukan</title>'
               . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
               . '<body class="bg-light"><div class="container py-5"><div class="col-md-8 mx-auto">'
               . '<div class="card border-warning"><div class="card-header bg-warning">'
               . '<h5 class="mb-0">⚠ Setup Database Diperlukan</h5></div>'
               . '<div class="card-body">'
               . '<p>Tables ezdoc missing: <code>' . htmlspecialchars($__missingList) . '</code></p>'
               . '<p>Migration ter-record tapi tabelnya tidak ada. Beberapa cara fix:</p>'
               . '<ol>'
               . '<li><strong>Reload halaman</strong> (F5) — bootstrap akan auto-heal & re-run migration</li>'
               . '<li>Jalankan reset registry manual di DB:<br><code>TRUNCATE ezdoc_migrations;</code> lalu reload</li>'
               . '<li>Cek PHP error log (biasanya di <code>xampp/apache/logs/error.log</code>) untuk pesan <code>[ezdoc:migrate]</code></li>'
               . '</ol>'
               . '<div class="alert alert-secondary mt-3 small"><strong>Debug info:</strong><br>'
               . 'EZDOC_LOADED: yes<br>'
               . 'AUTO_MIGRATE: ' . (EZDOC_AUTO_MIGRATE ? 'yes' : 'no') . '<br>'
               . '$conn: ' . (isset($GLOBALS['conn']) && $GLOBALS['conn'] ? 'connected' : 'MISSING') . '<br>'
               . 'Migration dir: <code>' . htmlspecialchars(EZDOC_ROOT . '/migrations') . '</code>'
               . '</div>'
               . '<div class="mt-3"><a href="javascript:location.reload()" class="btn btn-primary">🔄 Coba Lagi</a></div>'
               . '</div></div></div></div></body></html>';
            exit;
        }
    }
    unset($__ezdocConn, $__ezdocTables, $__ezdocMissing, $__t, $__rs, $__isAjax, $__missingList);
}
