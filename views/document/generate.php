<?php
/**
 * ezdoc/views/document/generate.php — full-featured document generator + printer.
 * Ported dari reference implementation (monolith consumer app cetak view).
 *
 * Expected vars in scope (all optional — bootstrap fallback below):
 *   @var \Ezdoc\Context   $ctx      DB + role provider + verify service
 *   @var \Ezdoc\UI\Config $config   App config (URLs, brand, slot identity)
 *   @var \Ezdoc\UI\Theme  $theme    Branding + asset URLs (optional)
 *
 * Cross-framework portability: this view is DUMB — no business logic.
 * All server operations via fetch to actions/*.php REST endpoints,
 * with endpoint URLs resolved via `$config` (see $ezdocUrls bag).
 * Consumer library user may swap Context to PDO/Doctrine backend.
 *
 * Modes:
 *   - view=pdf : Output as PDF (stream to browser)
 *   - debug=html : Dump raw PDF HTML for debugging
 *   - download=1 : Force PDF as attachment (Content-Disposition)
 *
 * spec: ezdoc-spec/views/generate.md
 * spec: ezdoc-spec/schemas/document.json
 */

// ═══════════════════════════════════════════════════════════════
// Bootstrap fallback — support standalone invocation dari consumer's page/ dir
// (kalau consumer wire manual, $ctx sudah ter-inject sebelum include ini)
// ═══════════════════════════════════════════════════════════════
if (!isset($ctx) || !($ctx instanceof \Ezdoc\Context)) {
    if (!class_exists(\Ezdoc\Context::class)) {
        $__bootstrapCandidates = [
            __DIR__ . '/../../bootstrap.php',
            __DIR__ . '/../../../ezdoc/bootstrap.php',
        ];
        foreach ($__bootstrapCandidates as $__b) {
            if (is_file($__b)) { require_once $__b; break; }
        }
    }
    $ctx = \Ezdoc\Context::default();
}
  
// Config resolution: consumer OR default empty config
if (!isset($config) || !($config instanceof \Ezdoc\UI\Config)) {
    $config = new \Ezdoc\UI\Config([]);
}

// Translator resolution: consumer OR default (Indonesian) locale.
// $GLOBALS promotion is REQUIRED (not just convenience) — same scope-isolation
// trap already fixed for $dbFields/$dbTtd in commit 1c4a12a: this view can be
// include()'d from Ezdoc\Http\Router::renderView(), a METHOD, in which case
// top-level vars here live in method-local scope, not global. The global t()
// helper below reads $GLOBALS directly so it works from inside function-scoped
// renderers (renderContent(), renderFieldForPdf(), etc.) too. See docs/I18N.md.
if (!isset($translator) || !($translator instanceof \Ezdoc\UI\Translator)) {
    $translator = \Ezdoc\UI\Translator::forView('generate', (string) $config->get('app.locale', 'id'));
}
$GLOBALS['translator'] = $translator;

// Consumer-app globals → abstracted via Context DI
$conn              = $ctx->db;
$author_id         = $ctx->roleProvider->currentUserId();
$author_role_array = $ctx->roleProvider->currentUserRoles();

// AJAX action dispatcher — extract AJAX endpoint handlers.
// Endpoints handled: generate_qr, save_document, toggle_doc_lock, list_versions,
// new_version, delete_version, delete_slot, restore_slot.
// spec: ezdoc-spec/actions/document.md
if (is_file(__DIR__ . '/../../actions/_dispatcher.php')) {
    require_once __DIR__ . '/../../actions/_dispatcher.php';
}

// Extracted helpers (v0.6.5) — pure SELECT lookups + template expression evaluators.
// Cari di 2 lokasi: library `ezdoc/lib/` (kalau sudah copied) atau consumer app
// `<consumer>/lib/` (fallback untuk monolith consumer yang masih share helpers).
// v0.9.9 target: move helpers wholly ke library, deprecate consumer-side fallback.
$__helperCandidates = [
    __DIR__ . '/../../lib/',              // ezdoc/lib/
    __DIR__ . '/../../../lib/',           // consumer/lib/ (one level up from ezdoc/)
    __DIR__ . '/../../../../lib/',        // deeper consumer layout
];
foreach (['doc_meta_helpers.php', 'doc_template_helpers.php', 'doc_verify_helpers.php'] as $__helperFile) {
    foreach ($__helperCandidates as $__dir) {
        $__full = $__dir . $__helperFile;
        if (is_file($__full)) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $__full;
            break;
        }
    }
}

// Local helper — escape output (keeps existing h() call sites working)
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Local helper — translate a dot-notation i18n key (see lang/id/*.php).
// $default preserves the original Indonesian copy so a missing/mistyped key
// still displays correct text instead of a raw key or blank string.
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $default = null): string {
        $translator = $GLOBALS['translator'] ?? null;
        if (!($translator instanceof \Ezdoc\UI\Translator)) {
            return $default !== null ? $default : $key;
        }
        return $translator->t($key, $params, $default);
    }
}

if (!function_exists('safeDataImg')) {
    function safeDataImg(string $s): string {
        if ($s === '') return '';
        return preg_match('#^data:image/(png|jpe?g|gif);base64,#', $s) ? $s : '';
    }
}

// ═══════════════════════════════════════════════════════════════
// Config-driven URLs + copy — swap-able per consumer
// spec: ezdoc-spec/config/urls.md
// ═══════════════════════════════════════════════════════════════
$urlGenerateQr    = (string) $config->get('urls.actions.document.generate_qr',     '?action=generate_qr');
$urlSaveDocument  = (string) $config->get('urls.actions.document.save',            '');
$urlDocAction     = (string) $config->get('urls.actions.document.doc_action',      '');
$urlDesigner      = (string) $config->get('urls.designer',                         '');
$urlDesignerCreate= (string) $config->get('urls.designer_create',                  '');
$urlPicker        = (string) $config->get('urls.picker',                           '');
$urlTrashList     = (string) $config->get('urls.trash_list',                       '');
$urlSelf          = (string) $config->get('urls.self',                             '?');

// Genericized copy — consumer overrides for branding
$pickerPageTitle  = (string) $config->get('generate.picker_page_title',            'Select Template');
$pickerHeader     = (string) $config->get('generate.picker_header',                'Select Template');
$pickerManageLabel= (string) $config->get('generate.picker_manage_label',          'Manage');
$pickerEmptyMsg   = (string) $config->get('generate.picker_empty_message',         'No templates yet.');
$pickerCreateLabel= (string) $config->get('generate.picker_create_label',          'Create new');
$pdfFilenamePrefix= (string) $config->get('generate.pdf.filename_prefix',          'document');
$adminRoleSlug    = (string) $config->get('roles.admin',                           'superadmin');

// URL bag published to JS via inline JSON (data-attribute injection pattern)
// spec: ezdoc-spec/js/url_bag.md
$ezdocUrls = [
    'generateQr'  => $urlGenerateQr,
    'save'        => $urlSaveDocument,
    'docAction'   => $urlDocAction,
    'trashList'   => $urlTrashList,
    'picker'      => $urlPicker,
];

// Superadmin capability — driven by RoleProvider + config-driven role slug
$isSuperadmin = $ctx->roleProvider->hasRole($adminRoleSlug);

$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$doc_id = isset($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;
$param_norm = trim($_GET['norm'] ?? $_POST['_norm'] ?? '');
$param_nopen = trim($_GET['nopen'] ?? $_POST['_nopen'] ?? '');
$param_label = trim($_GET['label'] ?? $_POST['_label'] ?? '-');
if ($param_label === '') $param_label = '-';
$param_version = isset($_GET['version']) ? (int)$_GET['version'] : 0; // 0 = latest
$param_is_locked = 0;

// Document metadata (populated after load)
$docMeta = [
    'id' => null,
    'created_by' => null,
    'created_by_name' => null,
    'created_at' => null,
    'updated_at' => null,
];

// Template selection — allow caller override $templates (demo/consumer bypass DB)
// spec: ezdoc-spec/views/generate.md#picker-templates-injection
if ($template_id <= 0) {
    if (!isset($templates) || !is_array($templates)) {
        $result = mysqli_query($conn, "SELECT id, name AS nama_template FROM ezdoc_templates WHERE content IS NOT NULL AND content != '' AND is_current = 1 AND deleted_at IS NULL ORDER BY name");
        $templates = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) $templates[] = $row;
        }
    }
    // Fragment mode: picker di-wrap layout.php (dapat primary nav).
    $__ezdoc_isFragment = !empty($__ezdoc_fragment);

    // v0.9.11 view separation — template picker extracted ke standalone file
    // untuk industry-standard MVC one-view-per-action pattern (Laravel/Filament/
    // Symfony convention). Inherits parent scope for shared vars.
    require __DIR__ . '/generate_list.php';

    // return (bukan exit) supaya kalau di-include Router::renderView, ob buffer
    // captured untuk layout wrapping. Di standalone mode, return top-level sama
    // efeknya dengan exit (script terminates cleanly).
    return;
}

// Load template — pakai alias supaya kode rendering existing tetap works
$stmt = mysqli_prepare($conn, "
    SELECT id, uuid, version,
           name AS nama_template,
           category,
           scope AS doc_scope,
           content AS template_html,
           floating_elements,
           signature_config AS config_ttd,
           layout_config AS config_header,
           verify_config,
           access_config,
           is_locked
    FROM ezdoc_templates
    WHERE id = ? AND content IS NOT NULL
");
mysqli_stmt_bind_param($stmt, "i", $template_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$template = mysqli_fetch_assoc($result);

if (!$template) {
    // spec: ezdoc-spec/views/generate.md#not-found-redirect
    $__redir = $urlPicker !== '' ? $urlPicker : $urlSelf;
    header("Location: " . $__redir);
    exit;
}

// v0.9.12 sidecar rehydration — inject floating markers dari JSON column
// balik ke templateHtml supaya renderContent pipeline unchanged. Backward-
// compat: legacy rows dgn floating markers still in HTML tetap works.
$templateHtml = $template['template_html'] ?: '';
if (!empty($template['floating_elements'])) {
    $floating = \Ezdoc\Template\FloatingExtractor::fromJson($template['floating_elements']);
    if (!empty($floating)) {
        $templateHtml = \Ezdoc\Template\FloatingInjector::inject($templateHtml, $floating);
    }
}
$configTtdRaw = json_decode($template['config_ttd'] ?: '[]', true) ?: [];
$configHeader = json_decode($template['config_header'] ?: '{}', true) ?: [];
// Scope template: patient (butuh NORM+NOPEN) atau general (tanpa)
$templateDocScope = $template['doc_scope'] ?? 'patient';
$isGeneralDoc = $templateDocScope === 'general';

// Check if template uses new TTD placeholder format
$hasTtdPlaceholders = strpos($templateHtml, 'ttd-placeholder') !== false;

// If using placeholders, extract TTD config from placeholders (not from old configTtd)
$configTtd = [];
if ($hasTtdPlaceholders) {
    // Extract TTD info from placeholders in content — robust to attribute ordering
    preg_match_all('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>/s', $templateHtml, $ttdMatches, PREG_SET_ORDER);
    foreach ($ttdMatches as $m) {
        $attrs = $m[1];
        preg_match('/data-ttd="([^"]+)"/', $attrs, $tm);
        $ttdId = $tm[1] ?? '';
        if (!$ttdId) continue;

        preg_match('/data-label="([^"]+)"/', $attrs, $lm);
        preg_match('/data-nama-field="([^"]+)"/', $attrs, $nm);
        preg_match('/data-ttd-modes="([^"]+)"/', $attrs, $mm);
        preg_match('/data-ttd-qr-data="([^"]+)"/', $attrs, $qm);

        $configTtd[] = [
            'id' => $ttdId,
            'label' => $lm[1] ?? t('fallback.signature', [], 'Signature'),
            'nama_field' => $nm[1] ?? 'nama_' . $ttdId,
            'ttd_modes' => $mm[1] ?? 'image',
            'qr_data' => html_entity_decode($qm[1] ?? '')
        ];
    }
} else {
    // Use old format configTtd
    $configTtd = $configTtdRaw;
}

// Get logos (support old and new format)
$logos = $configHeader['logos'] ?? [];
$logoSizes = $configHeader['logoSizes'] ?? [];
if (isset($configHeader['logoKiri'])) $logos['logo_kiri'] = $configHeader['logoKiri'];
if (isset($configHeader['logoKanan'])) $logos['logo_kanan'] = $configHeader['logoKanan'];

// Paper size and padding settings
$paperSizes = [
    'A4' => ['width' => 210, 'height' => 297],
    'A5' => ['width' => 148, 'height' => 210],
    'Letter' => ['width' => 216, 'height' => 279],
    'Legal' => ['width' => 216, 'height' => 356],
    'F4' => ['width' => 215, 'height' => 330]
];

$paperSize = $configHeader['paperSize'] ?? 'A4';
$orientation = $configHeader['orientation'] ?? 'portrait';

// Handle custom size or predefined
if ($paperSize === 'Custom') {
    $paperDim = [
        'width' => (int)($configHeader['customWidth'] ?? 210),
        'height' => (int)($configHeader['customHeight'] ?? 297)
    ];
} else {
    if (!isset($paperSizes[$paperSize])) $paperSize = 'A4';
    $paperDim = $paperSizes[$paperSize];
}

// Swap dimensions for landscape
if ($orientation === 'landscape') {
    $paperDim = ['width' => $paperDim['height'], 'height' => $paperDim['width']];
}

$padding = $configHeader['padding'] ?? ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20];
$padTop = (int)($padding['top'] ?? 20);
$padRight = (int)($padding['right'] ?? 20);
$padBottom = (int)($padding['bottom'] ?? 20);

// Layout mode: 'paged' (default, multi-page cards with mask/spacer) atau
// 'continuous' (single scrollable container, no page breaks). Padding rules
// tetap apply di both modes.
$layoutMode = $configHeader['layoutMode'] ?? 'paged';
if (!in_array($layoutMode, ['paged', 'continuous'], true)) $layoutMode = 'paged';
$padLeft = (int)($padding['left'] ?? 20);

// Load document
$dokumen = null;
$dbFields = [];
$dbTtd = [];
// BUG FIX: init $GLOBALS['dbFields'] + $GLOBALS['dbTtd'] awal supaya helper
// functions `v()`, `vRaw()` yang pakai `global $dbFields` selalu work walau
// generate.php di-include dari method scope (Router::renderView).
$GLOBALS['dbFields'] = $dbFields;
$GLOBALS['dbTtd']    = $dbTtd;
$isEditMode = false;
// Allow superadmin to preview soft-deleted document via ?preview_deleted=1 (read-only mode)
$preview_deleted = !empty($_GET['preview_deleted']) && $isSuperadmin;
$param_is_deleted = 0;
$param_deleted_at = null;
$param_deleted_by = null;

if ($doc_id > 0) {
    // Bypass deleted_at filter only for superadmin preview
    $delClause = $preview_deleted ? '' : ' AND deleted_at IS NULL';
    $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE id = ? AND template_id = ?" . $delClause);
    mysqli_stmt_bind_param($stmt, "ii", $doc_id, $template_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $dokumen = mysqli_fetch_assoc($result);
    if ($dokumen) {
        $isEditMode = true;
        $dbFields = json_decode($dokumen['field_values'] ?: '{}', true) ?: [];
        $dbTtd = json_decode($dokumen['signature_values'] ?: '{}', true) ?: [];
        // BUG FIX (see note near line 344) — promote ke GLOBALS untuk v()/vRaw()
        $GLOBALS['dbFields'] = $dbFields;
        $GLOBALS['dbTtd']    = $dbTtd;
        $param_norm = $dokumen['norm'] ?? $param_norm;
        $param_nopen = $dokumen['nopen'] ?? $param_nopen;
        $param_label = $dokumen['label'] ?? $param_label;
        $param_version = (int)($dokumen['version'] ?? 1);
        $param_is_locked = (int)($dokumen['is_locked'] ?? 0);
        $param_is_deleted = !empty($dokumen['deleted_at']) ? 1 : 0;
        $param_deleted_at = $dokumen['deleted_at'] ?? null;
        $param_deleted_by = $dokumen['deleted_by'] ?? null;
        $docMeta = [
            'id' => (int)$dokumen['id'],
            'created_by' => $dokumen['created_by'] ?? null,
            'created_by_name' => null,
            'created_at' => $dokumen['created_at'] ?? null,
            'updated_at' => $dokumen['updated_at'] ?? null,
        ];
    }
}

// Lookup dokumen existing:
//   - Kalau scope=patient: lookup by (template_id, norm, nopen, label, version)
//   - Kalau scope=general: lookup by (template_id, label, version) — norm/nopen kosong OK
$canLookup = false;
if ($isGeneralDoc) {
    $canLookup = ($param_label !== '' && $param_label !== '-') || $param_version > 0;
} else {
    $canLookup = ($param_norm !== '' && $param_nopen !== '');
}
// Init client-side debug bag (dump ke window.EZDOC_DEBUG lewat script inline di bawah)
$__ezdocLoadDebug = null;

if (!$dokumen && $canLookup) {
    $delClause = $preview_deleted ? '' : ' AND deleted_at IS NULL';
    if ($isGeneralDoc) {
        // General: lookup by (template_id, label, [version])
        if ($param_version > 0) {
            $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND label = ? AND version = ?" . $delClause);
            mysqli_stmt_bind_param($stmt, "isi", $template_id, $param_label, $param_version);
        } else {
            $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND label = ?" . $delClause . " ORDER BY version DESC LIMIT 1");
            mysqli_stmt_bind_param($stmt, "is", $template_id, $param_label);
        }
    } elseif ($param_version > 0) {
        // Specific version requested
        $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND norm = ? AND nopen = ? AND label = ? AND version = ?" . $delClause);
        mysqli_stmt_bind_param($stmt, "isssi", $template_id, $param_norm, $param_nopen, $param_label, $param_version);
    } else {
        // Load latest version (MAX)
        $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND norm = ? AND nopen = ? AND label = ?" . $delClause . " ORDER BY version DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, "isss", $template_id, $param_norm, $param_nopen, $param_label);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $dokumen = mysqli_fetch_assoc($result);
    if ($dokumen) {
        $isEditMode = true;
        $doc_id = $dokumen['id'];
        $dbFields = json_decode($dokumen['field_values'] ?: '{}', true) ?: [];
        $dbTtd = json_decode($dokumen['signature_values'] ?: '{}', true) ?: [];

        // v0.9.12 sidecar per-doc floating override — kalau doc punya
        // `floating_elements` non-null, override template default (rehydrate
        // ke $templateHtml supaya rendering pipeline unchanged). Kalau NULL,
        // inherit template.floating_elements yg sudah rehydrated earlier.
        if (!empty($dokumen['floating_elements'])) {
            $docFloating = \Ezdoc\Template\FloatingExtractor::fromJson($dokumen['floating_elements']);
            if (!empty($docFloating)) {
                // Strip any template-level markers pertama (template rehydration
                // sudah append earlier), lalu inject doc-level override
                $stripped = \Ezdoc\Template\FloatingExtractor::extract($templateHtml);
                $templateHtml = \Ezdoc\Template\FloatingInjector::inject(
                    $stripped['html'],
                    $docFloating
                );
            }
        }
        // BUG FIX: promote $dbFields + $dbTtd ke $GLOBALS supaya helper functions
        // `v()`, `t()`, `dbFieldRaw()` (yang pakai `global $dbFields`) bisa akses
        // saat generate.php di-include dari method Router::renderView() (scope
        // isolation antara caller method dan include file).
        $GLOBALS['dbFields'] = $dbFields;
        $GLOBALS['dbTtd']    = $dbTtd;
        // Client-side debug — expose diagnostic ke window.EZDOC_DEBUG.load
        // supaya user cek via F12 Console tanpa akses server error log.
        $__ezdocLoadDebug = [
            'query'  => compact('template_id') + ['norm' => $param_norm, 'nopen' => $param_nopen, 'label' => $param_label],
            'result' => ['found' => true, 'doc_id' => (int)$dokumen['id']],
            'raw'    => [
                'field_values'     => (string)($dokumen['field_values'] ?? ''),
                'signature_values' => (string)($dokumen['signature_values'] ?? ''),
            ],
            'parsed' => [
                'dbFields_keys' => array_keys($dbFields),
                'dbFields'      => $dbFields,
                'dbTtd_keys'    => array_keys($dbTtd),
            ],
        ];
        $param_version = (int)($dokumen['version'] ?? 1);
        $param_is_locked = (int)($dokumen['is_locked'] ?? 0);
        $param_is_deleted = !empty($dokumen['deleted_at']) ? 1 : 0;
        $param_deleted_at = $dokumen['deleted_at'] ?? null;
        $param_deleted_by = $dokumen['deleted_by'] ?? null;
        $docMeta = [
            'id' => (int)$dokumen['id'],
            'created_by' => $dokumen['created_by'] ?? null,
            'created_by_name' => null,
            'created_at' => $dokumen['created_at'] ?? null,
            'updated_at' => $dokumen['updated_at'] ?? null,
        ];
    } else {
        // Client-side debug — dokumen not found scenario
        $__ezdocLoadDebug = [
            'query'  => ['template_id' => $template_id, 'norm' => $param_norm, 'nopen' => $param_nopen, 'label' => $param_label, 'version' => $param_version, 'scope' => $isGeneralDoc ? 'general' : 'patient'],
            'result' => ['found' => false, 'doc_id' => null],
            'raw'    => null,
            'parsed' => null,
            'hint'   => 'Query no match — cek exact values (whitespace, case-sensitive) vs DB row',
        ];
    }
}

// Force read-only mode for deleted previews (regardless of is_locked)
if ($param_is_deleted) {
    $param_is_locked = 1;
}

// For new doc (no match), default version = 1
if (!$dokumen) $param_version = 1;

// Lookup creator display name via RoleProvider — consumer resolves against their user store.
// Legacy helper still available via ezdoc_fetch_creator_name() for backward compat.
// spec: ezdoc-spec/context/role_provider.md#displayName
if (!empty($docMeta['created_by'])) {
    if (method_exists($ctx->roleProvider, 'displayName')) {
        $docMeta['created_by_name'] = $ctx->roleProvider->displayName((int)$docMeta['created_by']);
    } elseif (function_exists('ezdoc_fetch_creator_name')) {
        $docMeta['created_by_name'] = ezdoc_fetch_creator_name($conn, $docMeta['created_by']);
    }
}

// Get field value (escaped for HTML output)
function v($name) {
    global $dbFields;
    if (isset($dbFields[$name]) && $dbFields[$name] !== '') return h($dbFields[$name]);
    if (isset($_POST[$name]) && $_POST[$name] !== '') return h($_POST[$name]);
    if (isset($_GET[$name]) && $_GET[$name] !== '') return h($_GET[$name]);
    return '';
}

// Get raw field value (not escaped, for processing like QR generation)
function vRaw($name) {
    global $dbFields;
    if (isset($dbFields[$name]) && $dbFields[$name] !== '') return $dbFields[$name];
    if (isset($_POST[$name]) && $_POST[$name] !== '') return $_POST[$name];
    if (isset($_GET[$name]) && $_GET[$name] !== '') return $_GET[$name];
    return '';
}

// Load whitelisted default variables (extracted to ezdoc/lib/doc_meta_helpers.php)
// resolveDefault() moved to ezdoc/lib/doc_template_helpers.php — reads $whitelistedVars via global.
$whitelistedVars = ezdoc_load_whitelisted_vars($conn);

// Dynamic Tables config
$tableDbQueries = $configHeader['tableDbQueries'] ?? [];

/**
 * Execute a dynamic table query with parameter binding.
 * Parameters like {nopen}, {norm}, {field_name} are replaced with prepared statement placeholders.
 */
function executeDynQuery($query, $param_norm, $param_nopen, $dbFields, $conn) {
    // Validate SELECT only
    $normalized = preg_replace('/\s+/', ' ', strtoupper(ltrim($query)));
    if (strpos($normalized, 'SELECT') !== 0 && strpos($normalized, 'WITH') !== 0) {
        return [];
    }
    $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE'];
    foreach ($blocked as $kw) {
        if (strpos($normalized, $kw) === 0) return [];
    }

    // Extract {param} placeholders and their order
    $paramNames = [];
    $paramValues = [];
    $paramTypes = '';

    $safeQuery = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function($m) use (&$paramNames) {
        $paramNames[] = $m[1];
        return '?';
    }, $query);

    // Map param names to values
    foreach ($paramNames as $name) {
        $val = '';
        if ($name === 'nopen') $val = $param_nopen;
        elseif ($name === 'norm') $val = $param_norm;
        else $val = $dbFields[$name] ?? '';
        $paramValues[] = $val;
        $paramTypes .= 's';
    }

    $stmt = @mysqli_prepare($conn, $safeQuery);
    if (!$stmt) return [];

    if (!empty($paramValues)) {
        mysqli_stmt_bind_param($stmt, $paramTypes, ...$paramValues);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Process {{tabledb.<ns>.<col>}} or {{tabledb.<col>}} references inside <tr> rows.
 * For each <tr> that contains at least one such reference:
 *   1. Resolve namespace (default "default" if missing).
 *   2. Run the configured query (executeDynQuery) for that namespace.
 *   3. Clone the row N times (one per result), substituting placeholders with row values.
 *   4. If query returns 0 rows, keep ONE row with placeholders replaced by "—".
 *   5. Replace original <tr> with concatenated cloned rows.
 *
 * $tableDbQueries : assoc array, keyed by namespace, value: ['sql' => '...', 'columns' => [...]]
 */
function processTabledbRows($html, $tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn) {
    if (!is_array($tableDbQueries) || empty($tableDbQueries)) return $html;

    // Cache: run each namespace's query at most once per template render
    $resultCache = [];

    return preg_replace_callback('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', function($m) use ($tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn, &$resultCache) {
        $rowHtml = $m[0];

        // Find all {{tabledb.<ns>.<col>}} or {{tabledb.<col>}} references in this row
        if (!preg_match_all('/\{\{tabledb(?:\.([a-zA-Z_][a-zA-Z0-9_]*))?\.([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $rowHtml, $refs, PREG_SET_ORDER)) {
            return $rowHtml; // no tabledb refs, leave untouched
        }

        // Determine namespace from first reference (assume all refs in one row share ns)
        $ns = $refs[0][1] !== '' ? $refs[0][1] : 'default';
        $cfg = $tableDbQueries[$ns] ?? null;
        if (!$cfg || empty($cfg['sql'])) {
            // Unknown namespace — replace with em-dash to make missing config visible
            return preg_replace('/\{\{tabledb(?:\.[a-zA-Z_]\w*)?\.[a-zA-Z_]\w*\}\}/', '—', $rowHtml);
        }

        // Execute query (cached per namespace)
        if (!array_key_exists($ns, $resultCache)) {
            $resultCache[$ns] = executeDynQuery($cfg['sql'], $param_norm, $param_nopen, $dbFields, $conn);
        }
        $rows = $resultCache[$ns];

        // Empty result: keep 1 row with placeholders replaced by "—"
        if (empty($rows)) {
            return preg_replace('/\{\{tabledb(?:\.[a-zA-Z_]\w*)?\.[a-zA-Z_]\w*\}\}/', '—', $rowHtml);
        }

        // Clone row per result, substituting each placeholder with its column value
        $output = '';
        foreach ($rows as $dataRow) {
            $clone = preg_replace_callback('/\{\{tabledb(?:\.[a-zA-Z_]\w*)?\.([a-zA-Z_]\w*)\}\}/', function($mm) use ($dataRow) {
                $col = $mm[1];
                $val = $dataRow[$col] ?? '';
                return ($val === '' || $val === null) ? '—' : htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
            }, $rowHtml);
            $output .= $clone;
        }
        return $output;
    }, $html);
}

// renderDynTable() removed — replaced by processTabledbRows() (new {{tabledb.*}} system)

// Conditional section evaluators + processConditionalSections extracted to
// ezdoc/lib/doc_template_helpers.php (evalCondExprPHP, evalSingleCondPHP,
// processConditionalSections). Loaded near top of file.

// Handle save
$saveMessage = '';
$saveSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    $norm = trim($_POST['_norm'] ?? '');
    $nopen = trim($_POST['_nopen'] ?? '');
    $label = trim($_POST['_label'] ?? '-');
    if ($label === '') $label = '-';

    // spec: ezdoc-spec/schemas/slot_identity.md — patient-slot fields (norm, nopen) enforced when scope=patient
    if ($norm === '' || $nopen === '') {
        $saveMessage = (string) $config->get('generate.error_missing_identity', 'Patient identity fields (norm, nopen) are required');
    } else {
        // Collect all fields from template
        $allFields = [];
        preg_match_all('/\{\{([^}]+)\}\}/', $templateHtml, $matches);
        foreach ($matches[1] as $fieldName) $allFields[$fieldName] = true;

        // Also collect QR field names from data-qr attributes
        preg_match_all('/data-qr="([^"]+)"/', $templateHtml, $qrMatches);
        foreach ($qrMatches[1] as $qrField) $allFields[$qrField] = true;

        $fieldData = [];
        foreach ($allFields as $fieldName => $v) {
            $fieldData[$fieldName] = $_POST[$fieldName] ?? '';
        }
        foreach ($configTtd as $ttd) {
            $nameField = $ttd['nama_field'] ?? '';
            if ($nameField) $fieldData[$nameField] = $_POST[$nameField] ?? '';
            // Save TTD mode selection
            $modeKey = '_ttd_mode_' . $ttd['id'];
            if (isset($_POST[$modeKey])) $fieldData[$modeKey] = $_POST[$modeKey];
        }

        $ttdData = [];
        foreach ($configTtd as $ttd) {
            $ttdId = $ttd['id'];
            $ttdValue = $_POST[$ttdId] ?? '';
            if ($ttdValue && safeDataImg($ttdValue)) $ttdData[$ttdId] = $ttdValue;
        }

        $jsonFields = json_encode($fieldData);
        $jsonTtd = json_encode($ttdData);
        // $author_id already resolved via $ctx->roleProvider at bootstrap (top of file)
        $author = $author_id !== null ? $author_id : 'system';

        if ($isEditMode && $doc_id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET norm=?, nopen=?, label=?, field_values=?, signature_values=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssssi", $norm, $nopen, $label, $jsonFields, $jsonTtd, $doc_id);
        } else {
            // INSERT baru — generate UUID + populate template snapshot
            // Fetch template_uuid + version untuk konsistensi
            $tplUuid = (string)($template['uuid'] ?? '');
            $tplVer  = (int)($template['version'] ?? 1);
            // ezdoc_uuid_v7() is guaranteed by bootstrap.php now that view lives inside the lib
            $docUuid = function_exists('ezdoc_uuid_v7') ? ezdoc_uuid_v7() : bin2hex(random_bytes(18));
            $authorInt = (int)($author_id !== null ? $author_id : 0);
            $stmt = mysqli_prepare($conn, "
                INSERT INTO ezdoc_documents
                (uuid, template_id, template_uuid, template_version,
                 norm, nopen, label, version,
                 field_values, signature_values, status, published_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'published', NOW(), ?)
            ");
            mysqli_stmt_bind_param($stmt, "sisisssssi", $docUuid, $template_id, $tplUuid, $tplVer, $norm, $nopen, $label, $jsonFields, $jsonTtd, $authorInt);
        }

        if (mysqli_stmt_execute($stmt)) {
            $saveSuccess = true;
            $saveMessage = t('save.success', [], 'Document saved successfully');
            if (!$isEditMode) { $doc_id = mysqli_insert_id($conn); $isEditMode = true; }
            $dbFields = $fieldData;
            $dbTtd = $ttdData;
            // BUG FIX: promote ke GLOBALS untuk v()/vRaw() helper functions
            $GLOBALS['dbFields'] = $dbFields;
            $GLOBALS['dbTtd']    = $dbTtd;
            $param_norm = $norm;
            $param_nopen = $nopen;
            $param_label = $label;
            // Level 3: compute + store data hash (best-effort)
            @doc_verify_compute_and_store_hash($conn, (int)$doc_id);
        } else {
            $saveMessage = t('save.failed', ['error' => mysqli_error($conn)], 'Failed to save: {error}');
        }
    }
}

// Get TTD values
$ttdValues = [];
foreach ($configTtd as $ttd) {
    $ttdId = $ttd['id'];
    $val = $dbTtd[$ttdId] ?? $_POST[$ttdId] ?? '';
    $ttdValues[$ttdId] = safeDataImg($val);
}

// Render HTML: replace placeholders with inputs, logos with images, TTD with signature areas
function renderContent($html, $logos, $logoSizes, $configTtd, $ttdValues, $dbFields, $dynamicTables = [], $conn = null, $param_norm = '', $param_nopen = '', $param_is_locked = 0, $tableDbQueries = [], $doc_id = 0) {
    // Replace logo placeholders with actual images (handles inline and floating logos)
    $html = preg_replace_callback('/<span[^>]*class="([^"]*logo-placeholder[^"]*)"[^>]*data-logo="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?(?:[^>]*style="([^"]*)")?[^>]*>.*?<\/span>/s', function($m) use ($logos, $logoSizes) {
        $classes = $m[1] ?? '';
        $logoName = $m[2];
        $width = $m[3] ?? $logoSizes[$logoName] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';
        $src = $logos[$logoName] ?? '';

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        if ($src) {
            $style = "width:" . h($width) . ";";
            $imgClass = "logo-img";

            if ($isFloating) {
                $imgClass .= " logo-floating";
                $imgClass .= $isBehind ? " logo-behind" : " logo-front";
                $style .= "position:absolute;left:{$posX}px;top:{$posY}px;";
            }

            return '<img src="' . h($src) . '" class="' . $imgClass . '" style="' . $style . '" alt="' . h($logoName) . '">';
        }
        return '<span class="logo-empty">[' . h($logoName) . ']</span>';
    }, $html);

    // Position offset correction for floating elements (to match generator/logo)
    // Based on user testing: logo is accurate reference, QR and TTD should match logo
    // Set to 0 = no adjustment (same as logo rendering)
    $posOffsetQR = 0;
    $posOffsetTTD = 0;

    // Replace TTD placeholders with signature areas
    //
    // Note: TTD placeholder has 3 nested divs inside, so we match each inner div pair then outer close
    $html = preg_replace_callback('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<\/div>/s', function($m) use ($configTtd, $ttdValues, $dbFields, $posOffsetTTD, $conn, $doc_id) {
        $tag = $m[1];
        // Parse all needed attributes from the opening tag
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-ttd="([^"]+)"/', $tag, $tm); $ttdId = $tm[1] ?? '';
        preg_match('/data-label="([^"]+)"/', $tag, $lm); $label = $lm[1] ?? t('fallback.signature', [], 'Signature');
        preg_match('/data-nama-field="([^"]+)"/', $tag, $nm); $namaField = $nm[1] ?? 'nama_' . $ttdId;
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '50';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '100';
        preg_match('/data-ttd-modes="([^"]+)"/', $tag, $mm); $ttdModes = $mm[1] ?? 'image';
        preg_match('/data-ttd-qr-data="([^"]+)"/', $tag, $qm); $qrDataTpl = html_entity_decode($qm[1] ?? '');
        // Default value untuk nama (fallback kalau field belum diisi)
        preg_match('/data-default-nama="([^"]*)"/', $tag, $dnm); $defaultNama = html_entity_decode($dnm[1] ?? '');
        // RBAC per-TTD (siapa yang boleh sign) — parse dari data-allowed-* attributes
        preg_match('/data-allowed-roles="([^"]*)"/', $tag, $arm); $allowedRolesRaw = $arm[1] ?? '';
        preg_match('/data-allowed-users="([^"]*)"/', $tag, $aum); $allowedUsersRaw = $aum[1] ?? '';
        $ttdRbac = function_exists('ezdoc_parse_ttd_config')
            ? ezdoc_parse_ttd_config($allowedRolesRaw, $allowedUsersRaw)
            : ['roles' => [], 'users' => []];
        $canSignThisTtd = function_exists('ezdoc_can_sign_ttd')
            ? ezdoc_can_sign_ttd($ttdRbac)
            : true;

        if (!$ttdId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        $ttdClass = 'ttd-item-inline';
        $style = '';
        if ($isFloating) {
            $ttdClass = 'ttd-item-floating';
            $ttdClass .= $isBehind ? ' ttd-behind' : ' ttd-front';
            $adjX = max(0, intval($posX) - $posOffsetTTD);
            $adjY = max(0, intval($posY) - $posOffsetTTD);
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        $ttdImg = $ttdValues[$ttdId] ?? '';
        // Resolve with fall-through on empty (same semantics as v() helper)
        $pick = function($key) use ($dbFields) {
            if (isset($dbFields[$key]) && $dbFields[$key] !== '') return $dbFields[$key];
            if (isset($_POST[$key]) && $_POST[$key] !== '') return $_POST[$key];
            if (isset($_GET[$key]) && $_GET[$key] !== '') return $_GET[$key];
            return '';
        };
        $namaRaw = $pick($namaField);
        // Fallback ke data-default-nama kalau field kosong
        if ($namaRaw === '' && $defaultNama !== '') $namaRaw = $defaultNama;
        $namaValue = h($namaRaw);

        $hasImage = strpos($ttdModes, 'image') !== false;
        $hasQr = strpos($ttdModes, 'qr') !== false;
        $hasBoth = $hasImage && $hasQr;

        // Determine active mode from saved data or default
        $savedMode = $pick('_ttd_mode_' . $ttdId);
        $activeMode = $savedMode ?: ($hasImage ? 'image' : 'qr');

        // Mode toggle buttons (only if both modes available)
        $modeToggle = '';
        if ($hasBoth) {
            $modeToggle = '<div class="ttd-mode-toggle">
                <button type="button" class="ttd-mode-btn ' . ($activeMode === 'image' ? 'active' : '') . '" onclick="switchTtdMode(\'' . h($ttdId) . '\', \'image\')">' . h(t('ttd.mode_image', [], 'Image')) . '</button>
                <button type="button" class="ttd-mode-btn ' . ($activeMode === 'qr' ? 'active' : '') . '" onclick="switchTtdMode(\'' . h($ttdId) . '\', \'qr\')">' . h(t('ttd.mode_qr', [], 'QR')) . '</button>
            </div>';
        }

        // Image area — kalau user tidak allowed sign, disable interaksi + tampil badge lock
        if ($canSignThisTtd) {
            $actionsHtml = '<div class="ttd-actions">
                <button type="button" class="btn-edit" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')">&#9998; ' . h(t('actions.edit', [], 'Edit')) . '</button>
                <button type="button" class="btn-delete" onclick="clearTtd(\'' . h($ttdId) . '\')">&#10005; ' . h(t('actions.delete', [], 'Delete')) . '</button>
            </div>';
            $imgContent = $ttdImg
                ? '<img src="' . h($ttdImg) . '" class="ttd-signature" alt="TTD">' . $actionsHtml
                : '<div class="ttd-canvas-placeholder" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')"></div>';
        } else {
            // Not allowed — read-only display, no edit button, no clickable canvas
            $rolesHint = $allowedRolesRaw !== '' ? ' (' . h($allowedRolesRaw) . ')' : '';
            $lockBadge = '<div class="ttd-locked-badge" style="position:absolute;top:2px;right:2px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:600;z-index:5;pointer-events:none;" title="' . h(t('title.no_sign_permission', ['roles' => $rolesHint], 'Not authorized to sign{roles}')) . '"><i class="fa fa-lock"></i></div>';
            $imgContent = $ttdImg
                ? '<img src="' . h($ttdImg) . '" class="ttd-signature" alt="TTD">' . $lockBadge
                : '<div class="ttd-canvas-placeholder ttd-locked" style="cursor:not-allowed;background:#f3f4f6;position:relative;" title="' . h(t('title.no_sign_permission_as', ['label' => $label, 'roles' => $rolesHint], 'Not authorized to sign as {label}{roles}')) . '">' . $lockBadge . '</div>';
        }
        $imgDisplay = ($activeMode === 'image' || !$hasQr) ? '' : 'display:none;';
        $imgArea = '<div class="ttd-area-image" id="ttd_area_image_' . h($ttdId) . '" style="' . $imgDisplay . '">' . $imgContent . '</div>';

        // Per-document QR content resolution: dbFields -> POST -> GET -> fallback pattern.
        // Key convention: <namaField>_qr (mengikuti nama TTD yang user set, cukup suffix _qr di URL)
        $qrContentKey = $namaField . '_qr';
        $qrContent = $pick($qrContentKey);
        // Auto-heal stale verify URL (kalau stored value pakai base local lama)
        if ($qrContent !== '' && function_exists('doc_verify_resolve_qr_value') && function_exists('doc_verify_ensure_slug')) {
            $__slugTtd = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slugTtd) $qrContent = doc_verify_resolve_qr_value($qrContent, $__slugTtd);
        }
        // Compute resolved pattern (used as placeholder hint, and also fallback when user leaves input empty)
        $resolvedPattern = '';
        if ($qrDataTpl !== '') {
            $resolvedPattern = preg_replace_callback('/\{([^}]+)\}/', function($pm) use ($pick, $conn) {
                $key = $pm[1];
                // Magic variable: {verify_url} → URL verifikasi dokumen (untuk QR scannable)
                if ($key === 'verify_url' || $key === 'verify') {
                    $docId = (int)($_GET['doc_id'] ?? $_POST['_doc_id'] ?? 0);
                    if ($docId > 0 && function_exists('doc_verify_ensure_slug')) {
                        $slug = doc_verify_ensure_slug($conn, $docId);
                        return $slug ? doc_verify_build_url($slug) : '';
                    }
                    return '';
                }
                return $pick($key);
            }, $qrDataTpl);
        }

        // QR area — konten QR disembunyikan, ada tombol edit
        $qrArea = '';
        if ($hasQr) {
            $qrDisplay = $activeMode === 'qr' ? '' : 'display:none;';
            $patternForJs = addslashes($resolvedPattern);
            $qrArea = '<div class="ttd-area-qr" id="ttd_area_qr_' . h($ttdId) . '" style="' . $qrDisplay . '">
                <div class="ttd-qr-preview" id="ttd_qr_preview_' . h($ttdId) . '"></div>
                <input type="hidden" class="ttd-qr-content-input" name="' . h($qrContentKey) . '" id="ttd_qr_content_' . h($ttdId) . '" value="' . h($qrContent) . '">
                <button type="button" class="btn-edit-qr-content" onclick="editQrContent(\'' . h($ttdId) . '\', \'' . h($patternForJs) . '\')" title="' . h(t('title.edit_qr_content', [], 'Edit QR content')) . '" style="display:block;margin:4px auto 0;font-size:10px;padding:2px 8px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;">&#9998; ' . h(t('ttd.edit_qr_button', [], 'Edit QR')) . '</button>
            </div>';
        }

        // ─── Verify QR mode (dokumen-level) — override TTD gambar dengan QR verify saat toggle ON ───
        // Flag berasal dari data_fields (`_show_verify_qr` = '1'), atau GET/POST, atau auto-fill.
        $showVerifyQrMode = ($dbFields['_show_verify_qr'] ?? '') === '1'
            || ($_POST['_show_verify_qr'] ?? '') === '1'
            || ($_GET['_show_verify_qr'] ?? '') === '1';
        // Verify URL — kalau doc sudah saved akan ada, kalau belum akan kosong (JS handle auto-save)
        // Prioritas: $doc_id dari renderContent param (paling akurat), fallback GET/POST
        $verifyUrlForTtd = '';
        $docIdForVerify = (int)($doc_id > 0 ? $doc_id : ($_GET['doc_id'] ?? $_POST['_doc_id'] ?? 0));
        if ($docIdForVerify > 0 && function_exists('doc_verify_ensure_slug')) {
            $__slug = doc_verify_ensure_slug($conn, $docIdForVerify);
            if ($__slug) $verifyUrlForTtd = doc_verify_build_url($__slug);
        }
        // Pre-render verify QR area — hidden kalau flag OFF, shown kalau ON
        // Content akan diisi oleh JS via /generate_qr endpoint (client-side)
        $verifyQrDisplay = $showVerifyQrMode ? '' : 'display:none;';
        $imgDisplayOverride = $showVerifyQrMode ? 'display:none;' : $imgDisplay;
        // Rebuild imgArea dengan display override
        $imgArea = '<div class="ttd-area-image" id="ttd_area_image_' . h($ttdId) . '" style="' . $imgDisplayOverride . '">' . $imgContent . '</div>';
        $verifyQrArea = '<div class="ttd-area-verify-qr" id="ttd_area_verify_qr_' . h($ttdId) . '" style="' . $verifyQrDisplay . 'text-align:center;">'
            . '<div class="ttd-verify-qr-preview" id="ttd_verify_qr_preview_' . h($ttdId) . '" data-verify-url="' . h($verifyUrlForTtd) . '">'
            . ($verifyUrlForTtd === ''
                ? '<small style="color:#f59e0b;font-size:10px;">' . h(t('ttd.save_first', [], 'Save document first')) . '</small>'
                : '<small style="color:#888;font-size:10px;">' . h(t('ttd.loading_qr', [], 'Loading QR...')) . '</small>')
            . '</div>'
            . '<div style="font-size:9px;color:#666;margin-top:2px;">' . h(t('ttd.scan_to_verify', [], 'Scan to verify')) . '</div>'
            . '</div>';

        return '<div class="' . $ttdClass . '" style="' . $style . '">
            <div class="ttd-label">' . h($label) . '</div>
            ' . $modeToggle . '
            <div class="ttd-img" id="preview_' . h($ttdId) . '">' . $imgArea . $qrArea . $verifyQrArea . '</div>
            <div class="ttd-name">(<span class="f" contenteditable="true" data-field="' . h($namaField) . '">' . ($namaValue ?: '') . '</span><input type="hidden" name="' . h($namaField) . '" value="' . $namaValue . '">)</div>
            <input type="hidden" name="' . h($ttdId) . '" id="ttd_' . h($ttdId) . '" value="' . h($ttdImg) . '">
            <input type="hidden" name="_ttd_mode_' . h($ttdId) . '" id="ttd_mode_' . h($ttdId) . '" value="' . h($activeMode) . '">
            <input type="hidden" name="_ttd_qr_data_' . h($ttdId) . '" id="ttd_qr_data_' . h($ttdId) . '" value="' . h($qrDataTpl) . '">
        </div>';
    }, $html);

    // Replace MATERAI placeholders with upload UI / empty area
    // Pattern: <div class="materai-placeholder ..." data-materai="X" data-label="..." data-mode="upload|kosong" data-pos-x data-pos-y data-width data-height>...nested div...</div>
    $html = preg_replace_callback('/<div([^>]*class="[^"]*materai-placeholder[^"]*"[^>]*)>\s*<div[^>]*>[\s\S]*?<\/div>\s*<\/div>/s', function($m) use ($dbFields, $param_is_locked) {
        $tag = $m[1];
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-materai="([^"]+)"/', $tag, $im); $materaiId = $im[1] ?? '';
        // Label may be empty/missing (optional)
        $label = '';
        if (preg_match('/data-label="([^"]*)"/', $tag, $lm)) $label = $lm[1];
        preg_match('/data-mode="([^"]+)"/', $tag, $mm); $matMode = $mm[1] ?? 'upload';
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '350';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '500';
        preg_match('/data-width="([^"]+)"/', $tag, $wm); $matW = max(20, intval($wm[1] ?? 100));
        preg_match('/data-height="([^"]+)"/', $tag, $hm); $matH = max(20, intval($hm[1] ?? 140));

        if (!$materaiId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind   = strpos($classes, 'behind') !== false;

        $cls = 'materai-item-inline';
        $style = '';
        if ($isFloating) {
            $cls = 'materai-item-floating';
            $cls .= $isBehind ? ' materai-behind' : ' materai-front';
            $adjX = max(0, intval($posX));
            $adjY = max(0, intval($posY));
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        // Per-document materai state from data_fields JSON
        $imgKey    = '_materai_' . $materaiId . '_image';
        $serialKey = '_materai_' . $materaiId . '_serial';
        $uploadKey = '_materai_' . $materaiId . '_uploaded_at';

        $matImg = '';
        if (isset($dbFields[$imgKey]) && $dbFields[$imgKey] !== '') {
            // Use safeDataImg() if available to validate base64
            $rawImg = $dbFields[$imgKey];
            if (function_exists('safeDataImg')) {
                $matImg = safeDataImg($rawImg);
            } else {
                $matImg = preg_match('#^data:image/(png|jpe?g|gif);base64,#', $rawImg) ? $rawImg : '';
            }
        }
        $matSerial = h($dbFields[$serialKey] ?? '');

        $fileInputId = 'materai_file_' . h($materaiId);
        $sizeStyle = "width:{$matW}px;height:{$matH}px;";

        $imgInner = '';
        if ($matMode === 'kosong') {
            // Always show the empty area (for manual stamping)
            $imgInner = '<div class="materai-empty-box" style="' . $sizeStyle . '" title="' . h(t('title.materai_manual_area', [], 'Materai area (manual stamp)')) . '"></div>';
        } else {
            // Upload mode
            if ($matImg) {
                $imgInner = '<img src="' . h($matImg) . '" class="materai-image" alt="Materai" style="max-width:' . $matW . 'px;max-height:' . $matH . 'px;">';
                if (!$param_is_locked) {
                    $imgInner .= '<div class="materai-actions">
                        <label for="' . $fileInputId . '" class="btn-edit" style="cursor:pointer;">&#9998; ' . h(t('materai.replace', [], 'Replace')) . '</label>
                        <button type="button" class="btn-delete" onclick="clearMaterai(\'' . h($materaiId) . '\')">&#10005; ' . h(t('actions.delete', [], 'Delete')) . '</button>
                    </div>';
                }
            } else {
                if (!$param_is_locked) {
                    // Use <label for=""> so click reliably opens hidden file input
                    $imgInner = '<label for="' . $fileInputId . '" class="materai-upload-box" style="' . $sizeStyle . 'cursor:pointer;">
                        <strong>' . t('materai.upload_prompt', [], 'UPLOAD<br>e-MATERAI') . '</strong>
                    </label>';
                } else {
                    $imgInner = '<div class="materai-empty-box" style="' . $sizeStyle . '" title="' . h(t('title.materai_not_uploaded', [], 'Not uploaded yet')) . '"></div>';
                }
            }
        }

        $hidden  = '<input type="hidden" name="' . h($imgKey) . '" id="materai_img_' . h($materaiId) . '" value="' . h($matImg) . '">';
        $hidden .= '<input type="hidden" name="' . h($uploadKey) . '" id="materai_upload_' . h($materaiId) . '" value="' . h($dbFields[$uploadKey] ?? '') . '">';

        $fileInput = '';
        $serialInput = '';
        if ($matMode === 'upload' && !$param_is_locked) {
            $fileInput = '<input type="file" id="' . $fileInputId . '" accept="image/png,image/jpeg" style="position:absolute;left:-9999px;opacity:0;width:0;height:0;" onchange="handleMateraiUpload(this, \'' . h($materaiId) . '\')">';
            $serialInput = '<input type="text" class="materai-serial-input" name="' . h($serialKey) . '" id="materai_serial_' . h($materaiId) . '" value="' . $matSerial . '" placeholder="' . h(t('placeholder.materai_serial', [], 'Serial No. (optional)')) . '" maxlength="30">';
        } elseif ($matMode === 'upload' && $param_is_locked) {
            // Locked: still expose serial as readonly hidden so it persists in form data
            $serialInput = '<input type="hidden" name="' . h($serialKey) . '" value="' . $matSerial . '">';
        }

        // Optional label — hide entirely if empty
        $labelHtml = ($label !== '') ? '<div class="materai-label">' . h($label) . '</div>' : '';

        return '<div class="materai-wrap ' . $cls . '" data-materai-id="' . h($materaiId) . '" data-materai-mode="' . h($matMode) . '" style="' . $style . '">
            ' . $labelHtml . '
            <div class="materai-img" id="materai_preview_' . h($materaiId) . '" style="width:' . $matW . 'px;height:' . $matH . 'px;">' . $imgInner . '</div>
            ' . $serialInput . '
            ' . $hidden . $fileInput . '
        </div>';
    }, $html);

    // Replace QR placeholders with QR code + editable input
    $html = preg_replace_callback('/<span[^>]*class="([^"]*qr-placeholder[^"]*)"[^>]*data-qr="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>.*?<\/span>/s', function($m) use ($posOffsetQR) {
        $classes = $m[1] ?? '';
        $fieldName = $m[2];
        $width = $m[3] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';

        // Get raw field value for QR data (not escaped, for QR generation)
        $qrDataRaw = vRaw($fieldName);

        // Auto-heal stale verify URL / resolve {verify_url} marker → pakai base URL current.
        // Kalau nilai tersimpan mengandung /verifikasi/<slug>?s=<hex> dengan base yang beda
        // (mis. dulu di-save saat local dev), auto-replace ke DOC_VERIFY_BASE_URL sekarang.
        if ($qrDataRaw !== '' && function_exists('doc_verify_resolve_qr_value') && function_exists('doc_verify_ensure_slug')) {
            $__slugQR = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slugQR) $qrDataRaw = doc_verify_resolve_qr_value($qrDataRaw, $__slugQR);
        }

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        $qrClass = 'qr-item-inline';
        $style = "width:" . h($width) . ";";
        if ($isFloating) {
            $qrClass = 'qr-item-floating';
            $qrClass .= $isBehind ? ' qr-behind' : ' qr-front';
            // Apply offset correction (QR has 8px padding in generator)
            $adjX = max(0, intval($posX) - $posOffsetQR);
            $adjY = max(0, intval($posY) - $posOffsetQR);
            $style .= "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        // QR image or placeholder — klik area → buka modal untuk generate QR
        // Modal support: default isi = verify_url dokumen (untuk kemudahan) atau user override manual
        $qrImgHtml = '';
        if ($qrDataRaw) {
            $qrSrc = generateQrForDompdf($qrDataRaw, 200, 5);
            // Kalau sudah ada value → tampil QR. Klik → modal untuk edit
            $qrImgHtml = '<img src="' . $qrSrc . '" class="qr-img" style="width:100%;height:auto;cursor:pointer;" alt="QR" id="qrimg_' . h($fieldName) . '" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" title="' . h(t('title.click_edit_qr', [], 'Click to edit QR content')) . '">';
        } else {
            // Kosong → placeholder clickable. Klik → modal muncul dengan default verify_url
            $qrImgHtml = '<div class="qr-canvas-placeholder" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" style="cursor:pointer;" title="' . h(t('title.click_generate_qr', [], 'Click to generate QR (default: verification URL)')) . '"></div>';
        }

        // Klik container QR → buka modal (onclick di parent, jadi bekerja bahkan setelah updateQrPreview replace innerHTML)
        return '<div class="' . $qrClass . '" style="' . $style . '" data-qr-field="' . h($fieldName) . '">
            <div class="qr-preview" id="qrpreview_' . h($fieldName) . '" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" style="cursor:pointer;" title="' . h(t('title.click_fill_edit_qr', [], 'Click to fill/edit QR')) . '">' . $qrImgHtml . '</div>
            <div class="qr-input" style="display:none;">
                <input type="text" class="qr-field" id="qrinput_' . h($fieldName) . '" name="' . h($fieldName) . '"
                    value="' . h($qrDataRaw) . '" placeholder="' . h(t('placeholder.qr_data', [], 'QR data...')) . '" onchange="updateQrPreview(\'' . h($fieldName) . '\')">
            </div>
        </div>';
    }, $html);

    // ===== TABLEDB row repeating =====
    // Process <tr> rows that contain {{tabledb.<ns>.<col>}} references.
    // Must happen BEFORE field-placeholder replacement so cloned rows have placeholders intact.
    if (!empty($tableDbQueries) && is_array($tableDbQueries)) {
        $html = processTabledbRows($html, $tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn);
    }

    // Replace field placeholders with appropriate input types
    $html = preg_replace_callback('/<span[^>]*class=["\']field-placeholder[^"\']*["\']([^>]*)>\{\{([^}:]+)(?::text)?\}\}<\/span>/', function($m) {
        $attrs = $m[1];
        $name = $m[2];
        $val = v($name);

        // Parse attributes
        $type = 'text';
        $options = '';
        $label = '';
        $default = '';
        if (preg_match('/data-type=["\']([^"\']+)["\']/', $attrs, $tm)) $type = $tm[1];
        if (preg_match('/data-options=["\']([^"\']+)["\']/', $attrs, $om)) $options = html_entity_decode($om[1]);
        if (preg_match('/data-label=["\']([^"\']+)["\']/', $attrs, $lm)) $label = html_entity_decode($lm[1]);
        if (preg_match('/data-default=["\']([^"\']+)["\']/', $attrs, $dm)) $default = html_entity_decode($dm[1]);

        // Validation attributes (#6) — pass through to renderFieldInput as 6th arg
        $validation = [];
        if (preg_match('/data-required=["\']1["\']/', $attrs)) $validation['required'] = '1';
        if (preg_match('/data-min=["\']([^"\']+)["\']/', $attrs, $mn)) $validation['min'] = $mn[1];
        if (preg_match('/data-max=["\']([^"\']+)["\']/', $attrs, $mx)) $validation['max'] = $mx[1];
        if (preg_match('/data-pattern=["\']([^"\']+)["\']/', $attrs, $pt)) $validation['pattern'] = html_entity_decode($pt[1]);
        if (preg_match('/data-error-msg=["\']([^"\']+)["\']/', $attrs, $em)) $validation['errorMsg'] = html_entity_decode($em[1]);

        // If no value from db/post/get, use resolved default
        if ($val === '' && $default !== '') {
            $val = h(resolveDefault($default));
        }

        return renderFieldInput($name, $type, $val, $options, $label, $validation);
    }, $html);

    // Handle bare {{field}} without span wrapper (fallback) - text type
    $html = preg_replace_callback('/\{\{([^}:]+)(?::text)?\}\}/', function($m) {
        $name = $m[1];
        $val = v($name);
        return renderFieldInput($name, 'text', $val, '', '');
    }, $html);

    // Process <p> tags - mark floating-only for paragraphs with only floating elements
    $html = preg_replace_callback('/<p([^>]*)>([\s\S]*?)<\/p>/i', function($match) {
        $attrs = $match[1];
        $content = $match[2];

        // Check if contains floating elements
        $floatingClasses = ['ttd-item-floating', 'qr-item-floating', 'logo-floating'];
        $hasFloating = false;
        foreach ($floatingClasses as $fc) {
            if (strpos($content, $fc) !== false) {
                $hasFloating = true;
                break;
            }
        }

        // If no floating elements, keep unchanged (including empty <p> for spacing)
        if (!$hasFloating) {
            return $match[0];
        }

        // Get text content only (strip all tags)
        $textOnly = strip_tags($content);
        $textOnly = preg_replace('/&nbsp;/i', ' ', $textOnly);
        $textOnly = trim($textOnly);

        // If has floating element but no text content, mark as floating-only
        if (empty($textOnly)) {
            if (strpos($attrs, 'class=') !== false) {
                $attrs = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 floating-only"', $attrs);
            } else {
                $attrs .= ' class="floating-only"';
            }
            return '<p' . $attrs . '>' . $content . '</p>';
        }

        return $match[0]; // Has floating + text, keep unchanged
    }, $html);

    return $html;
}

// Render field input based on type. $validation = ['required'=>'1','min'=>...,'max'=>...,'pattern'=>...,'errorMsg'=>...]
function renderFieldInput($name, $type, $val, $options, $label, $validation = []) {
    $hName = h($name);
    $hVal = h($val);

    // Build validation data attributes (rendered as data-v-* on the span/input)
    $vAttrs = '';
    if (!empty($validation['required'])) $vAttrs .= ' data-v-required="1"';
    if (isset($validation['min']) && $validation['min'] !== '') $vAttrs .= ' data-v-min="' . h($validation['min']) . '"';
    if (isset($validation['max']) && $validation['max'] !== '') $vAttrs .= ' data-v-max="' . h($validation['max']) . '"';
    if (!empty($validation['pattern'])) $vAttrs .= ' data-v-pattern="' . h($validation['pattern']) . '"';
    if (!empty($validation['errorMsg'])) $vAttrs .= ' data-v-error-msg="' . h($validation['errorMsg']) . '"';
    $reqMark = !empty($validation['required']) ? '<span class="field-required-mark" title="' . h(t('validation.required', [], 'Required')) . '">*</span>' : '';

    switch ($type) {
        case 'number':
            // Filter non-numeric on input (contenteditable tidak respect type="number").
            // Allowed: digits + optional decimal point + leading minus (industri standard).
            return '<span class="f-wrap"' . $vAttrs . '><span class="f field-number" contenteditable="true" inputmode="decimal" data-field="' . $hName . '" oninput="filterNumeric(this)" onpaste="handleNumericPaste(event)">' . ($hVal ?: '') . '</span><input type="hidden" name="' . $hName . '" value="' . $hVal . '">' . $reqMark . '</span>';

        case 'date':
            return '<span class="f-wrap"' . $vAttrs . '><input type="date" class="field-date" name="' . $hName . '" value="' . $hVal . '" data-field="' . $hName . '">' . $reqMark . '</span>';

        case 'checkbox':
            $checked = ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'Ya') ? ' checked' : '';
            $labelHtml = $label !== '' ? ' ' . h($label) : '';
            return '<label class="field-checkbox-wrap"' . $vAttrs . '><input type="checkbox" class="field-checkbox" name="' . $hName . '" value="1"' . $checked . ' data-field="' . $hName . '">' . $labelHtml . $reqMark . '</label>';

        case 'radio':
            $opts = array_map('trim', explode(',', $options));
            $html = '<span class="field-radio-wrap" data-field="' . $hName . '"' . $vAttrs . '>';
            foreach ($opts as $opt) {
                $hOpt = h($opt);
                $checked = ($val === $opt) ? ' checked' : '';
                $html .= '<label class="field-radio-label"><input type="radio" name="' . $hName . '" value="' . $hOpt . '"' . $checked . '> ' . $hOpt . '</label> ';
            }
            $html .= $reqMark . '</span>';
            return $html;

        case 'select':
            $opts = array_map('trim', explode(',', $options));
            $html = '<select class="field-select" name="' . $hName . '" data-field="' . $hName . '">';
            $html .= '<option value="">' . h(t('field.select_placeholder', [], '-- Select --')) . '</option>';
            foreach ($opts as $opt) {
                $hOpt = h($opt);
                $selected = ($val === $opt) ? ' selected' : '';
                $html .= '<option value="' . $hOpt . '"' . $selected . '>' . $hOpt . '</option>';
            }
            $html .= '</select>';
            return '<span class="f-wrap"' . $vAttrs . '>' . $html . $reqMark . '</span>';

        default: // text
            return '<span class="f-wrap"' . $vAttrs . '><span class="f" contenteditable="true" data-field="' . $hName . '">' . ($hVal ?: '') . '</span><input type="hidden" name="' . $hName . '" value="' . $hVal . '">' . $reqMark . '</span>';
    }
}

$currentUrl = "?template_id=$template_id";
if ($doc_id > 0) $currentUrl .= "&doc_id=$doc_id";
elseif ($param_norm && $param_nopen) {
    $currentUrl .= "&norm=" . urlencode($param_norm) . "&nopen=" . urlencode($param_nopen);
    if ($param_label !== '-') $currentUrl .= "&label=" . urlencode($param_label);
}

// ============================================
// PDF VIEW MODE
// ============================================
if (isset($_GET['view']) && $_GET['view'] === 'pdf') {
    // Generate PDF version
    $forceDownload = isset($_GET['download']) && $_GET['download'] == '1';

    // Build clean HTML for PDF (no toolbar, no scripts, no form elements)
    $pdfHtml = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>' . h($template['nama_template']) . '</title>
    <style>
        /* @page margin: reserves padT/padR/padB/padL di SETIAP physical page
           (CSS Paged Media Level 3 spec). dompdf paginator apply per-page.
           Sebelumnya .page{padding} approach cuma apply padding di element
           start+end (once), middle pages tidak dapat margin → content flush
           against physical edge.
           Fix: @page margin + .page padding=0 + .page shrunk ke printable area.
           Floating elements need translate compensation (see rule below). */
        @page {
            size: ' . $paperDim['width'] . 'mm ' . $paperDim['height'] . 'mm;
            margin: ' . $padTop . 'mm ' . $padRight . 'mm ' . $padBottom . 'mm ' . $padLeft . 'mm;
        }
        /* Global box-sizing only. Selective reset (body, .page margin/padding
           handled below). Blanket "* { margin: 0; padding: 0 }" reset removed —
           previously stripped ol/ul/li/p margins that we want per screen behavior. */
        * { box-sizing: border-box; }
        /* Font-family: "Times" first (native PDF core font, dompdf renders exact
           Times metrics). "Times New Roman" (browser name) + serif fallbacks.
           Sebelumnya "Times New Roman" first → dompdf tidak resolve → fallback
           DejaVu Sans (SANS-SERIF) → sangat beda visual dari screen. */
        body {
            font-family: "Times", "Times New Roman", serif;
            font-size: 12pt;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        /* Heading defaults — dompdf tidak apply browser default h1-h6 sizes/margins.
           Explicit sesuai browser standard (h1 2em, h2 1.5em, dst.) supaya render
           sesuai designer/screen. */
        h1, h2, h3, h4, h5, h6 { line-height: 1.25; page-break-after: avoid; }
        h1 { font-size: 2em; font-weight: bold; margin: 0.67em 0; }
        h2 { font-size: 1.5em; font-weight: bold; margin: 0.75em 0; }
        h3 { font-size: 1.17em; font-weight: bold; margin: 0.83em 0; }
        h4 { font-size: 1em; font-weight: bold; margin: 1.12em 0; }
        h5 { font-size: 0.83em; font-weight: bold; margin: 1.5em 0; }
        h6 { font-size: 0.75em; font-weight: bold; margin: 1.67em 0; }
        strong, b { font-weight: bold; }
        em, i { font-style: italic; }
        /* Inheritance safeguard — semua content dari .content, .page, body
           inherit line-height 1.6 kecuali override eksplisit (heading). */
        .page, .content { line-height: inherit; }
        /* .page shrunk ke printable area (paperW - padL - padR × paperH - padT -
           padB). @page margin di atas sudah reserve per-page margin. .page padding
           = 0 supaya no double margin. */
        .page {
            width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
            min-height: ' . ($paperDim['height'] - $padTop - $padBottom) . 'mm;
            margin: 0;
            padding: 0;
            position: relative;
        }
        /* Floating position compensation — @page margin shifts .page origin ke
           (padL, padT) di physical page coord. Floating stored di designer coord
           (from .page top-left = paper corner). Translate back ke visual paper
           corner position: -padL horizontal, -padT vertical. */
        .logo-floating, .ttd-item-floating, .qr-item-floating,
        .qr-behind, .qr-front, .materai-floating,
        .materai-behind, .materai-front {
            transform: translate(-' . $padLeft . 'mm, -' . $padTop . 'mm);
        }
        /* Content baseline — shared via Ezdoc\UI\ContentCss (single source of
           truth designer + generate + PDF). Historically these rules duplicated
           and drifted. Centralized now. */
        .content {
            line-height: 1.6;
            /* EXPLICIT WIDTH — dompdf tidak fully respect box-sizing: border-box
               untuk padding calc. Bypass dgn set .content width eksplisit
               = paper width - horizontal padding. Ensures text wraps at exact
               same width as designer (170mm untuk A4 dgn 20mm padding). */
            width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
            max-width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
        }
        /* Semua descendant `.content` inherit overflow protection. */
        .content * { max-width: 100%; }
        ' . \Ezdoc\UI\ContentCss::render() . '
        /* Logo images inline-block (max-width sudah declared di .content img rule
           di block sebelumnya). */
        .content .logo-img { display: inline-block; }

        /* Field values - just show text */
        .f-wrap { display: inline; }
        .f, .f-pdf {
            font-family: inherit;
            font-size: inherit;
            font-weight: inherit;
            font-style: inherit;
            color: inherit;
            line-height: inherit;
            display: inline;
        }

        /* Logo */
        .logo-img { height: auto; vertical-align: middle; }
        .logo-floating { position: absolute; }
        .logo-behind { z-index: -1; }
        .logo-front { z-index: 100; }
        .logo-empty { display: none; }

        /* TTD — sync dgn screen generate rules exactly */
        .ttd-item-inline {
            display: inline-block;
            text-align: center;
            min-width: 120px;
            vertical-align: top;
            margin: 5px;
        }
        .ttd-item-floating {
            position: absolute;
            text-align: center;
            min-width: 120px;
        }
        .ttd-behind { z-index: -1; }
        .ttd-front { z-index: 100; }
        .ttd-label { font-size: 12pt; margin-bottom: 5px; }
        .ttd-img { min-height: 50px; text-align: center; }
        .ttd-signature { max-height: 60px; max-width: 120px; }
        .ttd-name { font-size: 11pt; margin-top: 3px; }
        .ttd-canvas-placeholder { display: none; }
        .ttd-actions { display: none; }

        /* TTD wrap for old format */
        .ttd-wrap { margin-top: 40px; text-align: center; }
        .ttd-item { display: inline-block; min-width: 130px; text-align: center; margin: 0 20px; vertical-align: top; }

        /* QR Code */
        .qr-item-inline { display: inline-block; text-align: center; vertical-align: top; }
        .qr-item-floating { position: absolute; text-align: center; }
        .qr-behind { z-index: -1; }
        .qr-front { z-index: 100; }
        .qr-preview { text-align: center; }
        .qr-img { max-width: 100%; height: auto; }
        .qr-input { display: none; }
        .qr-canvas-placeholder { display: none; }

        /* Dynamic Tables */
        .dyntable-rendered { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        .dyntable-rendered th, .dyntable-rendered td { padding: 4px 8px; vertical-align: top; }
        .dyntable-rendered th { font-weight: bold; }
        .dyntable-rendered thead { display: table-header-group; }
        .dyntable-rendered tr { page-break-inside: avoid; }
        .dyntable-empty { color: #999; font-style: italic; text-align: center; padding: 10px; }
    </style>';
    // Slot: pdf-head-extra — consumer may inject additional CSS, @page rules, embedded fonts, or PDF metadata tags
    // spec: ezdoc-spec/slots/generate.md#pdf-head-extra
    $pdfHtml .= \Ezdoc\UI\Slot::render('generate:pdf-head-extra', [
        'template' => $template,
        'doc_id'   => (int)$doc_id,
        'paper'    => $paperDim,
    ]);
    $pdfHtml .= '
</head>
<body>
    <div class="page">';
    // Slot: pdf-body-start — top of every rendered PDF page (letterhead, security banner, top stamp/QR)
    // spec: ezdoc-spec/slots/generate.md#pdf-body-start
    $pdfHtml .= \Ezdoc\UI\Slot::render('generate:pdf-body-start', [
        'template' => $template,
        'doc_id'   => (int)$doc_id,
    ]);
    $pdfHtml .= '
        <div class="content">';

    // Merge GET parameters into dbFields (GET takes priority for auto-fill)
    $pdfFields = $dbFields;
    foreach ($_GET as $key => $val) {
        // Skip system parameters
        if (in_array($key, ['view', 'debug', 'download', 'template_id', 'doc_id', 'norm', 'nopen'])) continue;
        if (is_string($val) && $val !== '') {
            $pdfFields[$key] = $val;
        }
    }

    // Render content for PDF (with filled values, no form elements)
    $pdfContent = renderContentForPdf($templateHtml, $logos, $logoSizes, $configTtd, $ttdValues, $pdfFields, [], $conn, $param_norm, $param_nopen, $tableDbQueries, (int)$doc_id);
    $pdfHtml .= $pdfContent;

    $pdfHtml .= '</div>';

    // Old format TTD section
    if (!$hasTtdPlaceholders && !empty($configTtd)) {
        $pdfHtml .= '<div class="ttd-wrap">';
        foreach ($configTtd as $ttd) {
            $ttdId = $ttd['id'];
            $label = h($ttd['label'] ?? t('fallback.signature', [], 'Signature'));
            $namaField = $ttd['nama_field'] ?? 'nama_' . $ttdId;
            $namaValue = h($pdfFields[$namaField] ?? '');
            $ttdImg = $ttdValues[$ttdId] ?? '';

            $pdfHtml .= '<div class="ttd-item">';
            $pdfHtml .= '<div class="ttd-label">' . $label . '</div>';
            $pdfHtml .= '<div class="ttd-img">';
            if ($ttdImg) {
                $pdfHtml .= '<img src="' . h($ttdImg) . '" class="ttd-signature" alt="TTD">';
            } else {
                $pdfHtml .= '<div style="height:50px;"></div>';
            }
            $pdfHtml .= '</div>';
            $pdfHtml .= '<div class="ttd-name">(' . ($namaValue ?: '..................') . ')</div>';
            $pdfHtml .= '</div>';
        }
        $pdfHtml .= '</div>';
    }

    // Slot: pdf-body-end — bottom of every rendered PDF (page number, disclaimer, verify footer, QR verify text)
    // spec: ezdoc-spec/slots/generate.md#pdf-body-end
    $pdfHtml .= \Ezdoc\UI\Slot::render('generate:pdf-body-end', [
        'template' => $template,
        'doc_id'   => (int)$doc_id,
    ]);
    $pdfHtml .= '</div></body></html>';

    // Debug mode: view=html to see raw HTML before PDF conversion
    if (isset($_GET['debug']) && $_GET['debug'] === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        echo $pdfHtml;
        exit;
    }

    // Generate filename — config-driven prefix, safe slug of label
    // spec: ezdoc-spec/generate/pdf_filename.md
    $labelPart = ($param_label !== '-' && $param_label !== '') ? '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $param_label) : '';
    $filename = $pdfFilenamePrefix . '_' . ($param_norm ?: 'draft') . $labelPart . '_' . date('Ymd_His') . '.pdf';

    // echo $pdfHtml;
    // exit;

    // Render PDF — library-native pattern (Ezdoc\Rendering\PdfRenderer contract).
    //
    // Priority:
    //   1. Consumer-injected renderer via Context::withPdf() → $ctx->pdf
    //   2. Ezdoc-native DompdfRenderer default (kalau dompdf/dompdf composer
    //      package tersedia — most common case)
    //   3. Error page (no PDF backend available)
    //
    // Zero dependency ke consumer's local functions (generatePDF or bootstrap-specific helpers).
    // spec: docs/PDF-RENDERING.md
    $paperMm     = [$paperDim['width'], $paperDim['height']];
    // $orientation already set at header parsing (line ~339) from configHeader.
    // Fallback ke portrait kalau tidak set (defensive).
    $orientation = $orientation ?? 'portrait';

    $pdfRenderer = null;
    if (isset($ctx->pdf) && $ctx->pdf instanceof \Ezdoc\Rendering\PdfRenderer) {
        $pdfRenderer = $ctx->pdf;
    } elseif (isset($ctx->pdf) && is_object($ctx->pdf) && method_exists($ctx->pdf, 'stream')) {
        // Backward-compat: consumer wired object dgn stream() method but
        // tidak implement PdfRenderer interface — duck-typing accept.
        $pdfRenderer = $ctx->pdf;
    } elseif (class_exists('\\Dompdf\\Dompdf')) {
        // Auto-instantiate ezdoc-native DompdfRenderer (default fallback).
        // basePath = views/document/../.. = ezdoc root, untuk relative asset resolution.
        $pdfRenderer = new \Ezdoc\Rendering\DompdfRenderer([], __DIR__ . '/../../');
    }

    if ($pdfRenderer !== null) {
        $pdfRenderer->stream($pdfHtml, $filename, $paperMm, $orientation);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body><h1>PDF renderer not available</h1>'
            . '<p>Install <code>dompdf/dompdf</code> via Composer, or wire a custom renderer via '
            . '<code>Ezdoc\\Rendering\\PdfRenderer</code> interface (inject via <code>Context::withPdf()</code>).</p>'
            . '<p>spec: <code>docs/PDF-RENDERING.md</code></p></body>';
    }
    exit;
}

// Function to render content for PDF (no form elements, just values)
function renderContentForPdf($html, $logos, $logoSizes, $configTtd, $ttdValues, $dbFields, $dynamicTables = [], $conn = null, $param_norm = '', $param_nopen = '', $tableDbQueries = [], $doc_id = 0) {
    // Position offset corrections to match generator/logo (same as HTML rendering)
    // Set to 0 = no adjustment (logo is reference)
    $posOffsetQR = 0;
    $posOffsetTTD = 0;

    // Replace logo placeholders with actual images
    $html = preg_replace_callback('/<span[^>]*class="([^"]*logo-placeholder[^"]*)"[^>]*data-logo="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>.*?<\/span>/s', function($m) use ($logos, $logoSizes) {
        $classes = $m[1] ?? '';
        $logoName = $m[2];
        $width = $m[3] ?? $logoSizes[$logoName] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';
        $src = $logos[$logoName] ?? '';

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        if ($src) {
            $style = "width:" . h($width) . ";";
            $imgClass = "logo-img";

            if ($isFloating) {
                $imgClass .= " logo-floating";
                $imgClass .= $isBehind ? " logo-behind" : " logo-front";
                $style .= "position:absolute;left:{$posX}px;top:{$posY}px;";
            }

            return '<img src="' . h($src) . '" class="' . $imgClass . '" style="' . $style . '">';
        }
        return '';
    }, $html);

    // Replace TTD placeholders
    $html = preg_replace_callback('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<\/div>/s', function($m) use ($configTtd, $ttdValues, $dbFields, $posOffsetTTD, $conn, $doc_id) {
        $tag = $m[1];
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-ttd="([^"]+)"/', $tag, $tm); $ttdId = $tm[1] ?? '';
        preg_match('/data-label="([^"]+)"/', $tag, $lm); $label = $lm[1] ?? t('fallback.signature', [], 'Signature');
        preg_match('/data-nama-field="([^"]+)"/', $tag, $nm); $namaField = $nm[1] ?? 'nama_' . $ttdId;
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '50';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '100';
        preg_match('/data-ttd-modes="([^"]+)"/', $tag, $mm); $ttdModes = $mm[1] ?? 'image';
        preg_match('/data-ttd-qr-data="([^"]+)"/', $tag, $qm); $qrDataTpl = html_entity_decode($qm[1] ?? '');
        preg_match('/data-default-nama="([^"]*)"/', $tag, $dnm); $defaultNama = html_entity_decode($dnm[1] ?? '');

        if (!$ttdId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        $ttdClass = 'ttd-item-inline';
        $style = '';
        if ($isFloating) {
            $ttdClass = 'ttd-item-floating';
            $ttdClass .= $isBehind ? ' ttd-behind' : ' ttd-front';
            $adjX = max(0, intval($posX) - $posOffsetTTD);
            $adjY = max(0, intval($posY) - $posOffsetTTD);
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        $ttdImg = $ttdValues[$ttdId] ?? '';
        // Resolve with fall-through on empty: dbFields -> POST -> GET
        $pickPdf = function($key) use ($dbFields) {
            if (isset($dbFields[$key]) && $dbFields[$key] !== '') return $dbFields[$key];
            if (isset($_POST[$key]) && $_POST[$key] !== '') return $_POST[$key];
            if (isset($_GET[$key]) && $_GET[$key] !== '') return $_GET[$key];
            return '';
        };
        $namaRawPdf = $pickPdf($namaField);
        if ($namaRawPdf === '' && $defaultNama !== '') $namaRawPdf = $defaultNama;
        $namaValue = h($namaRawPdf);

        // Determine active mode from saved document data
        $hasQr = strpos($ttdModes, 'qr') !== false;
        $hasImage = strpos($ttdModes, 'image') !== false;
        $savedMode = $pickPdf('_ttd_mode_' . $ttdId);
        $activeMode = $savedMode ?: ($hasImage ? 'image' : 'qr');

        // ─── Verify QR mode (dokumen-level) — override semua TTD dengan verify QR ───
        // Dari data_fields JSON / POST / GET.
        $showVerifyQrModePdf = ($dbFields['_show_verify_qr'] ?? '') === '1'
            || ($_POST['_show_verify_qr'] ?? '') === '1'
            || ($_GET['_show_verify_qr'] ?? '') === '1';
        if ($showVerifyQrModePdf) {
            // Force render verify QR — abaikan TTD image/mode template
            // Prioritas: $doc_id dari renderContentForPdf param, fallback GET/POST
            $docId = (int)($doc_id > 0 ? $doc_id : ($_GET['doc_id'] ?? $_POST['_doc_id'] ?? 0));
            $verifyUrlPdf = '';
            if ($docId > 0 && function_exists('doc_verify_ensure_slug')) {
                $slug = doc_verify_ensure_slug($conn, $docId);
                if ($slug) $verifyUrlPdf = doc_verify_build_url($slug);
            }
            if ($verifyUrlPdf !== '') {
                try {
                    $qrSrc = generateQrForDompdf($verifyUrlPdf, 250, 6);
                    $contentHtml = '<img src="' . $qrSrc . '" style="width:100px;height:100px;max-width:none;max-height:none;" alt="' . h(t('ttd.verify_qr_alt', [], 'Verification QR')) . '">'
                        . '<div style="font-size:9px;color:#666;margin-top:2px;">' . h(t('ttd.scan_to_verify', [], 'Scan to verify')) . '</div>';
                } catch (Exception $e) {
                    $contentHtml = '<div style="height:100px;"></div>';
                }
            } else {
                $contentHtml = '<div style="height:100px;"></div>';
            }
            return '<div class="' . $ttdClass . '" style="' . $style . '">
                <div class="ttd-label">' . h($label) . '</div>
                <div class="ttd-img">' . $contentHtml . '</div>
                <div class="ttd-name">(' . ($namaValue !== '' ? $namaValue : '..................') . ')</div>
            </div>';
        }

        // Render based on active mode
        if ($activeMode === 'qr' && $hasQr) {
            // Per-document QR content takes priority; fallback to template pattern.
            // Key: <namaField>_qr (stored in data_fields JSON / POST / GET)
            $qrData = $pickPdf($namaField . '_qr');
            // Auto-heal stale verify URL supaya PDF juga pakai base URL current
            if ($qrData !== '' && function_exists('doc_verify_resolve_qr_value') && function_exists('doc_verify_ensure_slug')) {
                $__slugPdfTtd = doc_verify_ensure_slug($conn, (int)$doc_id);
                if ($__slugPdfTtd) $qrData = doc_verify_resolve_qr_value($qrData, $__slugPdfTtd);
            }
            if ($qrData === '' && $qrDataTpl !== '') {
                $qrData = preg_replace_callback('/\{([^}]+)\}/', function($pm) use ($pickPdf, $conn) {
                    $key = $pm[1];
                    // Magic variable: {verify_url} → URL verifikasi dokumen
                    if ($key === 'verify_url' || $key === 'verify') {
                        $docId = (int)($_GET['doc_id'] ?? $_POST['_doc_id'] ?? 0);
                        if ($docId > 0 && function_exists('doc_verify_ensure_slug')) {
                            $slug = doc_verify_ensure_slug($conn, $docId);
                            return $slug ? doc_verify_build_url($slug) : '';
                        }
                        return '';
                    }
                    return $pickPdf($key);
                }, $qrDataTpl);
            }
            if (trim($qrData) !== '') {
                try {
                    // Size 250px (up dari 150) + margin 6 supaya scannable saat di-print
                    $qrSrc = generateQrForDompdf($qrData, 250, 6);
                    // Display size 100x100px — cukup besar untuk scan HP dari jarak wajar
                    // max-width/max-height none supaya tidak kena override dari CSS parent
                    $contentHtml = '<img src="' . $qrSrc . '" style="width:100px;height:100px;max-width:none;max-height:none;" alt="QR">';
                } catch (Exception $e) {
                    $contentHtml = '<div style="height:100px;"></div>';
                }
            } else {
                $contentHtml = '<div style="height:100px;"></div>';
            }
        } else {
            $contentHtml = $ttdImg
                ? '<img src="' . h($ttdImg) . '" class="ttd-signature" alt="TTD">'
                : '<div style="height:50px;"></div>';
        }

        return '<div class="' . $ttdClass . '" style="' . $style . '">
            <div class="ttd-label">' . h($label) . '</div>
            <div class="ttd-img">' . $contentHtml . '</div>
            <div class="ttd-name">(' . ($namaValue !== '' ? $namaValue : '..................') . ')</div>
        </div>';
    }, $html);

    // Replace MATERAI placeholders with image / empty area for PDF
    $html = preg_replace_callback('/<div([^>]*class="[^"]*materai-placeholder[^"]*"[^>]*)>\s*<div[^>]*>[\s\S]*?<\/div>\s*<\/div>/s', function($m) use ($dbFields) {
        $tag = $m[1];
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-materai="([^"]+)"/', $tag, $im); $materaiId = $im[1] ?? '';
        $label = '';
        if (preg_match('/data-label="([^"]*)"/', $tag, $lm)) $label = $lm[1];
        preg_match('/data-mode="([^"]+)"/', $tag, $mm); $matMode = $mm[1] ?? 'upload';
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '350';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '500';
        preg_match('/data-width="([^"]+)"/', $tag, $wm); $matW = max(20, intval($wm[1] ?? 100));
        preg_match('/data-height="([^"]+)"/', $tag, $hm); $matH = max(20, intval($hm[1] ?? 140));

        if (!$materaiId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind   = strpos($classes, 'behind') !== false;

        $cls = 'materai-pdf-inline';
        $style = '';
        if ($isFloating) {
            $cls = 'materai-pdf-floating';
            $cls .= $isBehind ? ' materai-behind' : ' materai-front';
            $adjX = max(0, intval($posX));
            $adjY = max(0, intval($posY));
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        $imgKey = '_materai_' . $materaiId . '_image';
        $matImg = '';
        if (isset($dbFields[$imgKey]) && $dbFields[$imgKey] !== '') {
            $rawImg = $dbFields[$imgKey];
            if (function_exists('safeDataImg')) {
                $matImg = safeDataImg($rawImg);
            } else {
                $matImg = preg_match('#^data:image/(png|jpe?g|gif);base64,#', $rawImg) ? $rawImg : '';
            }
        }

        $body = '';
        if ($matImg) {
            // Embedded e-materai image with configured size
            $body = '<img src="' . h($matImg) . '" style="width:' . $matW . 'px;height:' . $matH . 'px;object-fit:contain;" alt="Materai">';
        } else {
            // Empty area (manual stamping or upload mode without image yet)
            $body = '<div style="width:' . $matW . 'px;height:' . $matH . 'px;border:1px dashed #c2410c;"></div>';
        }

        $labelHtml = ($label !== '') ? '<div style="font-size:9pt;margin-bottom:3px;color:#000;text-align:center;">' . h($label) . '</div>' : '';

        return '<div class="materai-pdf ' . $cls . '" style="' . $style . 'text-align:center;">' . $labelHtml . $body . '</div>';
    }, $html);

    // Replace QR placeholders with actual QR code images
    $html = preg_replace_callback('/<span[^>]*class="([^"]*qr-placeholder[^"]*)"[^>]*data-qr="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>.*?<\/span>/s', function($m) use ($dbFields, $posOffsetQR, $conn, $doc_id) {
        $classes = $m[1] ?? '';
        $fieldName = $m[2];
        $width = $m[3] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';

        // Get field value for QR data
        $qrData = $dbFields[$fieldName] ?? '';
        // Auto-heal stale verify URL supaya PDF juga pakai base URL current
        if ($qrData !== '' && function_exists('doc_verify_resolve_qr_value') && function_exists('doc_verify_ensure_slug')) {
            $__slugPdfQR = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slugPdfQR) $qrData = doc_verify_resolve_qr_value($qrData, $__slugPdfQR);
        }

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        if ($qrData) {
            // Generate QR code
            $qrSrc = generateQrForDompdf($qrData, 200, 5);
            $style = "width:" . h($width) . ";height:auto;";
            $imgClass = "qr-img";

            if ($isFloating) {
                $imgClass .= " qr-floating";
                $imgClass .= $isBehind ? " qr-behind" : " qr-front";
                // Apply offset correction (QR has 8px padding in generator)
                $adjX = max(0, intval($posX) - $posOffsetQR);
                $adjY = max(0, intval($posY) - $posOffsetQR);
                $style .= "position:absolute;left:{$adjX}px;top:{$adjY}px;";
            }

            return '<img src="' . $qrSrc . '" class="' . $imgClass . '" style="' . $style . '" alt="QR">';
        }
        return ''; // No QR if no data
    }, $html);

    // ===== TABLEDB row repeating (PDF) =====
    // Same logic as HTML render — process before field replacement
    if (!empty($tableDbQueries) && is_array($tableDbQueries)) {
        $html = processTabledbRows($html, $tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn);
    }

    // ===== CONDITIONAL SECTIONS (#7) =====
    // Strip blocks where condition fails; keep inner content of blocks where it passes.
    $html = processConditionalSections($html, $dbFields);

    // Replace field placeholders with value text for PDF
    $html = preg_replace_callback('/<span[^>]*class=["\']field-placeholder[^"\']*["\']([^>]*)>\{\{([^}:]+)(?::text)?\}\}<\/span>/', function($m) use ($dbFields) {
        $attrs = $m[1];
        $name = $m[2];
        $val = isset($dbFields[$name]) && $dbFields[$name] !== '' ? $dbFields[$name] : '';

        // Parse type and default
        $type = 'text';
        $label = '';
        $default = '';
        if (preg_match('/data-type=["\']([^"\']+)["\']/', $attrs, $tm)) $type = $tm[1];
        if (preg_match('/data-label=["\']([^"\']+)["\']/', $attrs, $lm)) $label = html_entity_decode($lm[1]);
        if (preg_match('/data-default=["\']([^"\']+)["\']/', $attrs, $dm)) $default = html_entity_decode($dm[1]);

        // If no saved value, use resolved default
        if ($val === '' && $default !== '') {
            $val = resolveDefault($default);
        }

        return renderFieldForPdf($name, $type, $val, $label);
    }, $html);

    // Handle bare {{field}}
    $html = preg_replace_callback('/\{\{([^}:]+)(?::text)?\}\}/', function($m) use ($dbFields) {
        $name = $m[1];
        $val = isset($dbFields[$name]) ? $dbFields[$name] : '';
        return renderFieldForPdf($name, 'text', $val, '');
    }, $html);

    // Process <p> tags - mark floating-only for paragraphs with only floating elements
    $html = preg_replace_callback('/<p([^>]*)>([\s\S]*?)<\/p>/i', function($match) {
        $attrs = $match[1];
        $content = $match[2];

        // Check if contains floating elements
        $floatingClasses = ['ttd-item-floating', 'qr-item-floating', 'logo-floating', 'qr-floating'];
        $hasFloating = false;
        foreach ($floatingClasses as $fc) {
            if (strpos($content, $fc) !== false) {
                $hasFloating = true;
                break;
            }
        }

        // If no floating elements, keep unchanged (including empty <p> for spacing)
        if (!$hasFloating) {
            return $match[0];
        }

        // Get text content only (strip all tags)
        $textOnly = strip_tags($content);
        $textOnly = preg_replace('/&nbsp;/i', ' ', $textOnly);
        $textOnly = trim($textOnly);

        // If has floating element but no text content, mark as floating-only
        if (empty($textOnly)) {
            if (strpos($attrs, 'class=') !== false) {
                $attrs = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 floating-only"', $attrs);
            } else {
                $attrs .= ' class="floating-only"';
            }
            return '<p' . $attrs . '>' . $content . '</p>';
        }

        return $match[0]; // Has floating + text, keep unchanged
    }, $html);

    return $html;
}

// Render field value for PDF (no inputs, just text)
function renderFieldForPdf($name, $type, $val, $label) {
    $hVal = h($val);

    switch ($type) {
        case 'checkbox':
            $checked = ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'Ya');
            // DejaVu Sans supports the box glyphs; default DOMPDF fonts (Helvetica/Times) don't
            $icon = '<span style="font-family: DejaVu Sans, sans-serif;">' . ($checked ? '☑' : '☐') . '</span>';
            $labelSuffix = $label !== '' ? ' ' . h($label) : '';
            return '<span class="f-pdf">' . $icon . $labelSuffix . '</span>';

        case 'date':
            if ($val) {
                // Format date nicely
                $timestamp = strtotime($val);
                if ($timestamp) {
                    $hVal = date('d/m/Y', $timestamp);
                }
            }
            return '<span class="f-pdf">' . ($hVal ?: '........') . '</span>';

        case 'number':
        case 'radio':
        case 'select':
        default:
            return '<span class="f-pdf">' . ($hVal ?: '........') . '</span>';
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // important:true → all Tailwind utility classes get !important. Needed karena
        // <style> block existing pakai selectors dengan specificity tinggi (mis. .toolbar button),
        // yang tanpa !important akan menang atas utility classes single-class.
        tailwind.config = { important: true };
    </script>
    <title><?= h($template['nama_template']) ?></title>
    <?php include __DIR__ . '/../_partials/generate_styles.php'; ?>
</head>
<body class="<?= $param_is_locked ? 'edit-off' : 'edit-on' ?>">
    <?php if ($saveMessage): ?>
    <div class="toast <?= $saveSuccess ? 'success' : 'error' ?>"><?= h($saveMessage) ?></div>
    <?php endif; ?>

    <form method="POST" id="mainForm" action="<?= h($currentUrl) ?>">
        <input type="hidden" name="_ajax" value="1">
        <input type="hidden" name="template_id" value="<?= $template_id ?>">
        <input type="hidden" name="_doc_id" id="docIdInput" value="<?= $doc_id ?>">
        <?php
        // Hidden field verify_url — supaya QR pattern {verify_url} bisa di-resolve di JS
        // Nilainya ter-precompute server-side (kalau doc sudah tersimpan → ada slug; kalau belum → kosong)
        $verifyUrlPreComputed = '';
        if ($doc_id > 0) {
            $__slug = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slug) $verifyUrlPreComputed = doc_verify_build_url($__slug);
        }
        ?>
        <input type="hidden" name="verify_url" id="verifyUrlInput" value="<?= h($verifyUrlPreComputed) ?>">
        <?php
        // Verify QR mode state — persist di data_fields JSON via key `_show_verify_qr`
        $__showVerifyQrInit = ($dbFields['_show_verify_qr'] ?? '') === '1'
            || ($_POST['_show_verify_qr'] ?? '') === '1'
            || ($_GET['_show_verify_qr'] ?? '') === '1';
        ?>
        <input type="hidden" name="_show_verify_qr" id="inputShowVerifyQr" value="<?= $__showVerifyQrInit ? '1' : '0' ?>">
        <?php
        // For old format (no placeholders), create hidden inputs here
        if (!$hasTtdPlaceholders):
            foreach ($configTtd as $ttd): ?>
        <input type="hidden" name="<?= h($ttd['id']) ?>" id="ttd_<?= h($ttd['id']) ?>" value="<?= h($ttdValues[$ttd['id']] ?? '') ?>">
        <?php endforeach;
        endif; ?>

        <div class="page">
            <?php // Slot: watermark — preview-mode overlay ('DRAFT'/'COPY'/state-based watermarks)
            // spec: ezdoc-spec/slots/generate.md#watermark ?>
            <?= \Ezdoc\UI\Slot::render('generate:watermark', [
                'doc_id'      => (int)$doc_id,
                'is_locked'   => (bool)$param_is_locked,
                'is_deleted'  => (bool)$param_is_deleted,
                'is_edit'     => (bool)$isEditMode,
            ]) ?>
            <div class="content">
                <?= renderContent($templateHtml, $logos, $logoSizes, $configTtd, $ttdValues, $dbFields, $dynamicTables, $conn, $param_norm, $param_nopen, $param_is_locked, $tableDbQueries, (int)$doc_id) ?>
            </div>

            <?php // Slot: before-signatures — closing phrase, place/date, witness list ahead of TTD wrap
            // spec: ezdoc-spec/slots/generate.md#before-signatures ?>
            <?= \Ezdoc\UI\Slot::render('generate:before-signatures', [
                'doc_id'   => (int)$doc_id,
                'ttd'      => $configTtd,
            ]) ?>

            <?php // Old format: show ttd-wrap section if no placeholders in content
            if (!$hasTtdPlaceholders && !empty($configTtd)): ?>
            <div class="ttd-wrap">
                <?php foreach ($configTtd as $ttd): ?>
                <div class="ttd-item">
                    <div class="ttd-label"><?= h($ttd['label'] ?? t('fallback.signature', [], 'Signature')) ?></div>
                    <div class="ttd-img" id="preview_<?= h($ttd['id']) ?>">
                        <?php if (!empty($ttdValues[$ttd['id']])): ?>
                        <img src="<?= h($ttdValues[$ttd['id']]) ?>" alt="TTD">
                        <?php endif; ?>
                    </div>
                    <div class="ttd-name">
                        <?php $namaField = $ttd['nama_field'] ?? 'nama_' . $ttd['id']; ?>
                        (<span class="f" contenteditable="true" data-field="<?= h($namaField) ?>"><?= v($namaField) ?></span><input type="hidden" name="<?= h($namaField) ?>" value="<?= v($namaField) ?>">)
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php // Slot: after-signatures — signature legend, notaris stamp, distribution list ('Tembusan')
            // spec: ezdoc-spec/slots/generate.md#after-signatures ?>
            <?= \Ezdoc\UI\Slot::render('generate:after-signatures', [
                'doc_id' => (int)$doc_id,
                'ttd'    => $configTtd,
            ]) ?>
        </div>

        <!-- TOOLBAR V1 Style -->
        <div class="toolbar" id="toolbarPanel">
            <button type="button" class="toolbar-toggle" id="toolbarToggleBtn" onclick="toggleToolbar()" title="<?= h(t('title.toggle_toolbar', [], 'Show/Hide Toolbar')) ?>">&#9776;</button>
            <div class="toolbar-body">
                <!-- ═══ HEADER (compact 1-line stack) ═══ -->
                <div class="flex items-center justify-between gap-1 mb-1">
                    <div class="min-w-0 flex-1">
                        <div class="text-[11px] text-gray-200 font-medium truncate leading-tight" title="<?= h($template['nama_template']) ?>"><?= h($template['nama_template']) ?></div>
                        <div class="text-[9px] text-gray-400 leading-tight">
                            <?= h($paperSize) ?> <?= $paperDim['width'] ?>×<?= $paperDim['height'] ?>
                            <?php if ($isEditMode): ?>
                                · <span class="doc-info <?= $param_is_deleted ? 'text-red-400' : ($param_is_locked ? 'text-amber-400' : 'text-emerald-400') ?>">
                                    #<?= $doc_id ?> v<?= $param_version ?>
                                    <?php if ($param_is_deleted): ?><i class="bi bi-trash-fill"></i>
                                    <?php elseif ($param_is_locked): ?><i class="bi bi-lock-fill"></i>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                · <span class="doc-info new text-amber-400"><?= t('toolbar.new', [], 'New') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isEditMode): ?>
                    <button class="!bg-gray-700 !text-gray-300 hover:!bg-gray-600 !rounded-full !w-[18px] !h-[18px] !p-0 !m-0 !text-[10px] !leading-none flex-shrink-0" type="button" onclick="showDocInfo()" title="<?= t('toolbar.document_details', [], 'Document details') ?>">i</button>
                    <?php endif; ?>
                </div>
                <?php if ($param_is_deleted): ?>
                <div class="bg-red-900 text-white p-1.5 rounded-md mb-1.5 text-[10px] text-center border border-dashed border-red-300">
                    <i class="bi bi-trash-fill"></i> <strong><?= t('toolbar.deleted', [], 'DELETED') ?></strong> · <?= h(date('d/m/Y', strtotime($param_deleted_at))) ?>
                    <div class="text-[9px] text-[#fecaca]"><?= t('toolbar.deleted_by', ['name' => h($param_deleted_by ?: '-')], 'By: {name}') ?></div>
                </div>
                <?php endif; ?>
                <div class="border-t border-gray-700 mb-2"></div>

                <?php // Slot: toolbar-metadata — barcode, workflow status, external system ref, badge chips
                // spec: ezdoc-spec/slots/generate.md#toolbar-metadata ?>
                <?= \Ezdoc\UI\Slot::render('generate:toolbar-metadata', [
                    'doc_id'    => (int)$doc_id,
                    'template'  => $template,
                    'is_locked' => (bool)$param_is_locked,
                    'meta'      => $docMeta,
                ]) ?>

                <div class="meta-section">
                    <?php if ($isGeneralDoc): ?>
                        <div class="bg-indigo-950 text-indigo-300 py-1 px-1.5 rounded text-[10px] mb-1.5 leading-tight">
                            <i class="bi bi-info-circle"></i> <?= h(t('toolbar.general_letter', [], 'General Letter')) ?>
                        </div>
                        <input type="hidden" name="_norm" id="inputNorm" value="">
                        <input type="hidden" name="_nopen" id="inputNopen" value="">
                        <label><?= h(t('toolbar.label', [], 'Label')) ?> <span class="text-red-400">*</span></label>
                        <input type="text" name="_label" id="inputLabel" value="<?= h($param_label) ?>" placeholder="<?= h(t('placeholder.default_dash', [], '- (default)')) ?>" <?= $param_is_locked ? 'readonly' : '' ?>>
                    <?php else: ?>
                        <div class="meta-grid">
                            <div>
                                <label><?= h(t('toolbar.norm', [], 'NORM')) ?> <span class="text-red-400">*</span></label>
                                <input type="text" name="_norm" id="inputNorm" value="<?= h($param_norm) ?>" placeholder="<?= h(t('placeholder.norm_hint', [], 'MR No.')) ?>" required <?= $param_is_locked ? 'readonly' : '' ?>>
                            </div>
                            <div>
                                <label><?= h(t('toolbar.nopen', [], 'NOPEN')) ?> <span class="text-red-400">*</span></label>
                                <input type="text" name="_nopen" id="inputNopen" value="<?= h($param_nopen) ?>" placeholder="<?= h(t('placeholder.nopen_hint', [], 'Registration')) ?>" required <?= $param_is_locked ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <label><?= h(t('toolbar.label', [], 'Label')) ?> <span class="text-gray-500 normal-case">(<?= h(t('toolbar.optional', [], 'optional')) ?>)</span></label>
                        <input type="text" name="_label" id="inputLabel" value="<?= h($param_label) ?>" placeholder="<?= h(t('placeholder.default_dash', [], '- (default)')) ?>" <?= $param_is_locked ? 'readonly' : '' ?>>
                    <?php endif; ?>
                    <?php if ($isEditMode): ?>
                    <label><?= h(t('toolbar.version_label', [], 'Version')) ?></label>
                    <select id="versionSelect" onchange="switchVersion(this.value)">
                        <option value="<?= $param_version ?>">v<?= $param_version ?> <?= h(t('toolbar.version_current_suffix', [], '(current)')) ?></option>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- ═══ PRIMARY ROW: Update (2/3) + Print (1/3 icon) ═══ -->
                <div class="grid grid-cols-3 gap-1 mt-1">
                    <button type="button" class="btn-success col-span-2 !m-0 !py-1.5 !text-[12px]" onclick="submitForm()" <?= $param_is_locked ? 'disabled title="' . h(t('title.locked_cannot_update', [], 'Locked - cannot update')) . '"' : '' ?>>
                        <?= $isEditMode ? ($param_is_locked ? h(t('toolbar.locked', [], 'Locked')) : h(t('toolbar.update', [], 'Update'))) : h(t('toolbar.save_new', [], 'Save New')) ?>
                    </button>
                    <button type="button" class="!m-0 !py-1.5 !px-1 !text-[11px]" onclick="window.print()" title="<?= h(t('title.print_shortcut', [], 'Print (Ctrl+P)')) ?>"><i class="bi bi-printer"></i> <?= h(t('toolbar.print', [], 'Print')) ?></button>
                </div>

                <?php // Slot: toolbar-extra-actions — consumer buttons (Export Excel, WhatsApp, Email PDF, e-Sign, Copy Link) ?>
                <?= \Ezdoc\UI\Slot::render('generate:toolbar-extra-actions', [
                    'doc_id'    => (int)$doc_id,
                    'template'  => $template,
                    'is_locked' => (bool)$param_is_locked,
                ]) ?>

                <?php if ($isEditMode && $param_is_deleted && $isSuperadmin): ?>
                    <button class="bg-green-600 mt-2" type="button" onclick="restoreDeletedSlot()" title="<?= h(t('title.restore_slot', [], 'Restore this slot')) ?>">
                        <i class="bi bi-arrow-counterclockwise"></i> <?= h(t('toolbar.restore_slot', [], 'Restore Slot')) ?>
                    </button>
                    <?php if ($urlTrashList !== ''):
                        $__trashHref = str_replace('{template_id}', (string)$template_id, $urlTrashList);
                    ?>
                    <a href="<?= h($__trashHref) ?>" style="background:#374151;color:#fff;padding:6px 10px;border-radius:4px;text-decoration:none;display:inline-block;text-align:center;font-size:13px;margin-top:4px;">
                        <i class="bi bi-arrow-left"></i> <?= h(t('toolbar.trash_list', [], 'Trash List')) ?>
                    </a>
                    <?php endif; ?>
                <?php else: ?>

                    <!-- ═══ Accordion: hanya 1 section terbuka biar hemat ruang ═══ -->
                    <div x-data="{ open: '' }" class="mt-2">

                        <!-- ▸ LAINNYA (icon grid 2-col) -->
                        <div class="border-t border-gray-700 pt-1.5">
                            <button type="button" @click="open = (open === 'more' ? '' : 'more')"
                                    class="!bg-transparent !text-gray-400 hover:!text-gray-100 !py-0.5 !px-1 !text-[10px] !m-0 flex items-center gap-1 w-full uppercase tracking-wider">
                                <i class="bi bi-chevron-right text-[9px]" :class="open === 'more' && 'rotate-90'" style="transition:transform .15s"></i><?= h(t('toolbar.more', [], 'More')) ?>
                            </button>
                            <div x-show="open === 'more'" x-collapse>
                                <div class="icon-grid mt-1.5">
                                    <button type="button" id="btnToggleVerifyQr"
                                            onclick="toggleVerifyQrMode()"
                                            title="<?= h(t('title.toggle_verify_qr', [], 'Replace signature image with verification QR')) ?>"
                                            class="wide"
                                            style="background:<?= $__showVerifyQrInit ? '#0d9488' : '#475569' ?>;color:#fff;">
                                        <i class="bi bi-qr-code"></i>
                                        <span>QR: <span id="btnToggleVerifyQrLabel"><?= $__showVerifyQrInit ? h(t('toolbar.qr_status_on', [], 'ON')) : h(t('toolbar.qr_status_off', [], 'OFF')) ?></span></span>
                                    </button>
                                    <?php if ($isEditMode): ?>
                                        <?php if (!$param_is_locked): ?>
                                            <button class="bg-gray-500" type="button" id="btnDocLock" onclick="toggleDocLock()" title="<?= h(t('title.lock_final', [], 'Lock this version (final)')) ?>"><i class="bi bi-lock"></i><?= h(t('toolbar.lock_final', [], 'Lock Final')) ?></button>
                                        <?php elseif ($isSuperadmin): ?>
                                            <button class="bg-amber-500" type="button" id="btnDocLock" onclick="toggleDocLock()" title="<?= h(t('title.unlock_version', [], 'Unlock this version (superadmin)')) ?>"><i class="bi bi-unlock"></i><?= h(t('toolbar.unlock', [], 'Unlock')) ?></button>
                                        <?php else: ?>
                                            <button class="bg-gray-600 opacity-60 cursor-not-allowed" type="button" disabled title="<?= h(t('title.locked_superadmin_only', [], 'Locked — only superadmin can unlock')) ?>"><i class="bi bi-lock-fill"></i><?= h(t('toolbar.locked', [], 'Locked')) ?></button>
                                        <?php endif; ?>
                                        <button class="bg-violet-500" type="button" onclick="showNewVersionModal()" title="<?= h(t('title.new_version', [], 'Create new version')) ?>"><i class="bi bi-plus-circle"></i><?= h(t('toolbar.new_version', [], 'New Version')) ?></button>
                                    <?php endif; ?>
                                    <button class="!bg-slate-600 wide" type="button" onclick="showShortcutsHelp()" title="<?= h(t('title.shortcuts', [], 'Keyboard shortcuts (Ctrl+/)')) ?>"><i class="bi bi-keyboard"></i><?= h(t('toolbar.shortcuts', [], 'Shortcuts')) ?></button>
                                </div>
                            </div>
                        </div>

                        <?php if ($isSuperadmin): ?>
                        <!-- ▸ ADMIN (icon grid) -->
                        <div class="border-t border-gray-700 mt-1 pt-1.5">
                            <button type="button" @click="open = (open === 'admin' ? '' : 'admin')"
                                    class="!bg-transparent !text-amber-400 hover:!text-amber-200 !py-0.5 !px-1 !text-[10px] !m-0 flex items-center gap-1 w-full uppercase tracking-wider">
                                <i class="bi bi-chevron-right text-[9px]" :class="open === 'admin' && 'rotate-90'" style="transition:transform .15s"></i><i class="bi bi-shield-lock text-[9px]"></i><?= h(t('toolbar.admin', [], 'Admin')) ?>
                            </button>
                            <div x-show="open === 'admin'" x-collapse>
                                <div class="icon-grid mt-1.5">
                                    <button class="!bg-emerald-700 hover:!bg-emerald-600<?= $isEditMode ? '' : ' wide' ?>" type="button" onclick="viewPdfRaw()" title="<?= h(t('title.view_pdf_raw', [], 'View raw PDF output')) ?>"><i class="bi bi-file-earmark-pdf"></i><?= h(t('toolbar.pdf_raw', [], 'PDF Raw')) ?></button>
                                    <?php if ($isEditMode): ?>
                                        <button type="button" onclick="deleteThisVersion()" class="!bg-red-700 hover:!bg-red-600" <?= $param_is_locked ? 'disabled title="' . h(t('title.delete_version_locked', [], 'Locked - unlock first')) . '"' : 'title="' . h(t('title.delete_version', [], 'Delete this version')) . '"' ?>><i class="bi bi-trash"></i><?= h(t('actions.delete', [], 'Delete')) ?></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- Sign Modal - Fullscreen -->
    <div class="modal" id="signModal">
        <div class="modal-box">
            <div class="modal-header" id="signTitle"><?= h(t('fallback.signature', [], 'Signature')) ?></div>
            <div class="modal-body">
                <div class="w-full">
                    <canvas id="signCanvas" width="850" height="400"></canvas>
                    <div class="sign-hint"><?= h(t('modal.sign.hint', [], 'Draw your signature in the area above using your mouse or touchscreen')) ?></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="bg-red-500 text-white" type="button" onclick="clearSign()"><?= h(t('actions.delete', [], 'Delete')) ?></button>
                <button class="bg-green-500 text-white" type="button" onclick="saveSign()"><?= h(t('actions.save', [], 'Save')) ?></button>
                <button class="bg-gray-500 text-white" type="button" onclick="closeSign()"><?= h(t('actions.close', [], 'Close')) ?></button>
            </div>
        </div>
    </div>

    <!-- QR Field Generate Modal — untuk standalone QR field, klik area → modal → OK → isi verify_url -->
    <div class="modal hidden" id="qrFieldModal">
        <div class="modal-box max-w-[520px]">
            <div class="modal-header" style="background:linear-gradient(90deg,#3b82f6 0%,#6366f1 100%);color:#fff;">
                <i class="bi bi-qr-code"></i> <?= h(t('modal.qr_field.header_title', [], 'Fill QR Code')) ?>
            </div>
            <div class="modal-body p-5 block">
                <p class="text-[13px] text-gray-700" style="margin:0 0 12px;">
                    <?= h(t('modal.qr_field.choose_prompt', [], 'Choose QR content for field')) ?> <strong id="qrFieldModalName">-</strong>:
                </p>
                <div class="mb-3">
                    <label class="text-xs font-semibold text-gray-700 block mb-1">
                        <input class="mr-1.5" type="radio" name="qrFieldSource" value="verify" id="qrFieldSourceVerify" checked>
                        <?= h(t('modal.qr_field.use_verify_url', [], 'Use Document Verification URL (Recommended)')) ?>
                    </label>
                    <div class="p-2 bg-green-50 rounded text-[11px] text-green-800 ml-5 break-all" id="qrFieldVerifyBox" style="font-family:monospace;">-</div>
                    <div class="hidden ml-5 mt-1.5 text-amber-700 text-[11px]" id="qrFieldVerifyWarn">
                        <i class="bi bi-exclamation-triangle"></i> <?= h(t('modal.qr_field.save_first_warning', [], 'Save the document first to generate the verification URL.')) ?>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-700 block mb-1">
                        <input class="mr-1.5" type="radio" name="qrFieldSource" value="custom" id="qrFieldSourceCustom">
                        <?= h(t('modal.qr_field.use_custom', [], 'Manual entry (custom)')) ?>
                    </label>
                    <input class="w-[calc(100%_-_20px)] ml-5 p-1.5 text-xs border border-gray-300 rounded" type="text" id="qrFieldCustomInput" placeholder="<?= h(t('modal.qr_field.custom_placeholder', [], 'Type QR content here...')) ?>">
                </div>
            </div>
            <div class="modal-footer text-right">
                <button class="bg-gray-500 text-white" type="button" onclick="closeQrFieldModal()"><?= h(t('actions.cancel', [], 'Cancel')) ?></button>
                <button class="bg-blue-500 text-white" type="button" id="btnQrFieldConfirm" onclick="confirmQrField()"><?= h(t('modal.qr_field.confirm_button', [], 'OK, Fill QR')) ?></button>
            </div>
        </div>
    </div>

    <!-- Verify QR Mode Confirmation Modal -->
    <div class="modal hidden" id="verifyQrConfirmModal">
        <div class="modal-box max-w-[520px]">
            <div class="modal-header" style="background:linear-gradient(90deg,#0d9488 0%,#14b8a6 100%);color:#fff;">
                <i class="bi bi-qr-code"></i> <?= h(t('modal.verify_qr.header_title', [], 'Activate Verification QR Mode')) ?>
            </div>
            <div class="modal-body p-5">
                <div id="verifyQrModalMain">
                    <p style="margin:0 0 12px;"><?= t('modal.verify_qr.main_desc_1', [], 'All <strong>signature images</strong> in this document will be replaced with a <strong>Verification QR</strong>.') ?></p>
                    <p class="text-xs text-gray-500" style="margin:0 0 12px;"><?= h(t('modal.verify_qr.main_desc_2', [], 'Useful for sharing PDF/print — recipients scan the QR → directed to the official verification page to check document authenticity.')) ?></p>
                    <label class="text-xs text-gray-700 font-semibold"><?= h(t('modal.verify_qr.url_label', [], 'Verification URL:')) ?></label>
                    <input class="w-full text-[11px] p-1.5 bg-gray-100 border border-gray-300 rounded mt-1" type="text" readonly id="verifyQrModalUrl" value="">
                    <div class="hidden text-amber-700 text-xs mt-2.5 p-2 bg-amber-100 rounded" id="verifyQrModalWarn">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span id="verifyQrModalWarnMsg"><?= h(t('modal.verify_qr.warn_not_saved', [], 'Document not saved yet. Click "Save & Activate" to save first, then activate.')) ?></span>
                    </div>
                </div>
                <div class="hidden text-center p-6" id="verifyQrModalSaving">
                    <div class="tw-spinner inline-block w-[2rem] h-[2rem] rounded-full" role="status" style="border:0.25em solid #cbd5e1;border-right-color:#3b82f6;animation:tw-spin 0.75s linear infinite;"></div>
                    <div class="mt-2.5 text-[13px] text-gray-500"><?= h(t('modal.verify_qr.saving_text', [], 'Saving document...')) ?></div>
                </div>
            </div>
            <div class="modal-footer text-right">
                <button class="bg-gray-500 text-white" type="button" onclick="closeVerifyQrModal()"><?= h(t('actions.cancel', [], 'Cancel')) ?></button>
                <button class="bg-teal-600 text-white" type="button" id="btnVerifyQrConfirm" onclick="confirmVerifyQrMode()"><?= h(t('modal.verify_qr.confirm_activate', [], 'OK, Activate')) ?></button>
            </div>
        </div>
    </div>

    <?php // Slot: modals-extra — consumer modals (export options, share dialog, e-sign integration, custom prompts)
    // spec: ezdoc-spec/slots/generate.md#modals-extra ?>
    <?= \Ezdoc\UI\Slot::render('generate:modals-extra', [
        'doc_id'   => (int)$doc_id,
        'template' => $template,
    ]) ?>

    <?php // Hook mount points for JS event bridge (before-save / after-save) —
    // consumers listen on window.ezcetak:before-save / window.ezcetak:after-save
    // spec: ezdoc-spec/slots/generate.md#save-hook-pre
    // spec: ezdoc-spec/slots/generate.md#save-hook-post ?>
    <?= \Ezdoc\UI\Slot::render('generate:save-hook-pre', ['doc_id' => (int)$doc_id]) ?>
    <?= \Ezdoc\UI\Slot::render('generate:save-hook-post', ['doc_id' => (int)$doc_id]) ?>

    <script>
        // spec: ezdoc-spec/js/url_bag.md — endpoint URLs injected server-side, JS reads from EZDOC_URLS
        window.EZDOC_URLS = <?= json_encode($ezdocUrls, JSON_UNESCAPED_SLASHES) ?>;

        // i18n dictionary — mirrors EZDOC_URLS above. spec: docs/I18N.md
        window.EZDOC_I18N = <?= json_encode($translator->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        // Translate a dot-notation key against EZDOC_I18N, with {param} interpolation.
        // Mirrors Ezdoc\UI\Config's own dot-path traversal (PHP side) so both stay in sync.
        window.t = function(key, params) {
            params = params || {};
            var ref = window.EZDOC_I18N || {};
            var segs = key.split('.');
            for (var i = 0; i < segs.length; i++) {
                if (ref && typeof ref === 'object' && segs[i] in ref) { ref = ref[segs[i]]; }
                else { console.warn('[ezdoc:i18n] missing key', key); return key; }
            }
            if (typeof ref !== 'string') { console.warn('[ezdoc:i18n] non-string value', key); return key; }
            return ref.replace(/\{(\w+)\}/g, function(m, name) {
                return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : m;
            });
        };
        var t = window.t;

        // Resolve an endpoint URL by bag key. Falls back to same-page (window.location.href) for POST
        // routes (save, docAction) and to '?action=<key>' for generate_qr — preserves legacy behavior
        // when consumer has not overridden the URL bag.
        window._ezdocEndpoint = function(key, fallback) {
            var v = (window.EZDOC_URLS || {})[key];
            if (v && v !== '') return v;
            if (typeof fallback !== 'undefined') return fallback;
            return window.location.href;
        };
        window._ezdocQrUrl = function(data) {
            var base = window._ezdocEndpoint('generateQr', '?action=generate_qr');
            var sep  = base.indexOf('?') >= 0 ? '&' : '?';
            // Ensure dispatcher-recognized signal (action=generate_qr) di URL.
            // App orchestrator route ke ?ezdoc_page=action tapi dispatcher tetap
            // butuh $_GET['action'] === 'generate_qr' untuk route ke generate_qr.php.
            var url = base;
            if (url.indexOf('action=generate_qr') < 0) {
                url += sep + 'action=generate_qr';
                sep = '&';
            }
            return url + sep + 'data=' + encodeURIComponent(data);
        };
    </script>
    <?php include __DIR__ . '/../_partials/generate_scripts.php'; ?>

    <!-- Screen pagination — visual multi-paper cards + JS spacer at boundary.
         Loaded LAST supaya semua content sudah settled (fields, floating elements,
         TTD modal ready). Runs on DOMContentLoaded. -->
    <script>
        <?= \Ezdoc\UI\ScreenPagination::renderJs(
            (float)$paperDim['height'],
            (float)$padTop,
            (float)$padBottom,
            12.0,
            $layoutMode
        ) ?>
    </script>

    <?php include __DIR__ . '/../_partials/dialog_helper.php'; ?>
</body>
</html>
