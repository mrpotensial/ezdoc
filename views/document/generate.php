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
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title><?= h($pickerPageTitle) ?></title>
        <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
        <!-- Alpine.js NOT loaded here — template picker uses zero interactivity, keep bundle lean. -->
    </head>
    <body class="bg-gray-100 min-h-screen">
        <div class="max-w-7xl mx-auto px-4 py-12">
            <div class="flex flex-wrap justify-center">
                <div class="w-full md:w-1/2 px-2">
                    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-200 bg-blue-600 text-white flex items-center justify-between">
                            <span class="font-medium"><?= h($pickerHeader) ?></span>
                            <div class="flex items-center gap-2">
                                <?= \Ezdoc\UI\Slot::render('generate:list-header-extra', ['templates' => $templates]) ?>
                                <?php if ($urlDesigner !== ''): ?>
                                <a href="<?= h($urlDesigner) ?>" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-900 hover:bg-gray-200 transition"><?= h($pickerManageLabel) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="p-4">
                            <?php if (empty($templates)): ?>
                            <p class="text-center text-gray-500 py-4">
                                <?= h($pickerEmptyMsg) ?>
                                <?php if ($urlDesignerCreate !== ''): ?>
                                <a href="<?= h($urlDesignerCreate) ?>" class="text-blue-600 hover:underline"><?= h($pickerCreateLabel) ?></a>
                                <?php endif; ?>
                            </p>
                            <?php else: ?>
                            <div class="flex flex-col rounded overflow-hidden border border-gray-200">
                                <?php
                                // Preserve routing prefix — kalau $urlSelf udah punya `?` (mis. `?ezdoc_page=generate`),
                                // append template_id dengan `&`, else `?`. Tanpa ini, `?template_id=X` bakal wipe
                                // `ezdoc_page=` param dan App fallback ke default page.
                                $__pickerJoiner = strpos($urlSelf, '?') !== false ? '&' : '?';
                                foreach ($templates as $t):
                                ?>
                                <a href="<?= h($urlSelf . $__pickerJoiner . 'template_id=' . $t['id']) ?>" class="block px-4 py-3 border-b border-gray-200 last:border-b-0 hover:bg-gray-50 text-gray-800 transition"><?= h($t['nama_template']) ?></a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Load template — pakai alias supaya kode rendering existing tetap works
$stmt = mysqli_prepare($conn, "
    SELECT id, uuid, version,
           name AS nama_template,
           category,
           scope AS doc_scope,
           content AS template_html,
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

$templateHtml = $template['template_html'] ?: '';
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
            'label' => $lm[1] ?? 'Tanda Tangan',
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
$padLeft = (int)($padding['left'] ?? 20);

// Load document
$dokumen = null;
$dbFields = [];
$dbTtd = [];
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
if (!$dokumen && $canLookup) {
    $delClause = $preview_deleted ? '' : ' AND deleted_at IS NULL';
    // spec: ezdoc-spec/observability/audit.md — diagnostic log LOAD query params
    // untuk debug mismatch dengan SAVED log (v0.9.9 target: replace dengan
    // Repository unit tests).
    @error_log(sprintf(
        '[ezdoc:load] QUERY template_id=%d norm=[%s] nopen=[%s] label=[%s] version=%d scope=%s',
        $template_id, $param_norm, $param_nopen, $param_label, $param_version, $isGeneralDoc ? 'general' : 'patient'
    ));
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
    @error_log(sprintf(
        '[ezdoc:load] RESULT dokumen=%s doc_id=%s',
        $dokumen ? 'FOUND' : 'null', $dokumen['id'] ?? '-'
    ));
    if ($dokumen) {
        $isEditMode = true;
        $doc_id = $dokumen['id'];
        $dbFields = json_decode($dokumen['field_values'] ?: '{}', true) ?: [];
        $dbTtd = json_decode($dokumen['signature_values'] ?: '{}', true) ?: [];
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
            $saveMessage = 'Dokumen berhasil disimpan';
            if (!$isEditMode) { $doc_id = mysqli_insert_id($conn); $isEditMode = true; }
            $dbFields = $fieldData;
            $dbTtd = $ttdData;
            $param_norm = $norm;
            $param_nopen = $nopen;
            $param_label = $label;
            // Level 3: compute + store data hash (best-effort)
            @doc_verify_compute_and_store_hash($conn, (int)$doc_id);
        } else {
            $saveMessage = 'Gagal menyimpan: ' . mysqli_error($conn);
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
        preg_match('/data-label="([^"]+)"/', $tag, $lm); $label = $lm[1] ?? 'Tanda Tangan';
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
                <button type="button" class="ttd-mode-btn ' . ($activeMode === 'image' ? 'active' : '') . '" onclick="switchTtdMode(\'' . h($ttdId) . '\', \'image\')">Gambar</button>
                <button type="button" class="ttd-mode-btn ' . ($activeMode === 'qr' ? 'active' : '') . '" onclick="switchTtdMode(\'' . h($ttdId) . '\', \'qr\')">QR</button>
            </div>';
        }

        // Image area — kalau user tidak allowed sign, disable interaksi + tampil badge lock
        if ($canSignThisTtd) {
            $actionsHtml = '<div class="ttd-actions">
                <button type="button" class="btn-edit" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')">&#9998; Edit</button>
                <button type="button" class="btn-delete" onclick="clearTtd(\'' . h($ttdId) . '\')">&#10005; Hapus</button>
            </div>';
            $imgContent = $ttdImg
                ? '<img src="' . h($ttdImg) . '" class="ttd-signature" alt="TTD">' . $actionsHtml
                : '<div class="ttd-canvas-placeholder" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')"></div>';
        } else {
            // Not allowed — read-only display, no edit button, no clickable canvas
            $rolesHint = $allowedRolesRaw !== '' ? ' (' . h($allowedRolesRaw) . ')' : '';
            $lockBadge = '<div class="ttd-locked-badge" style="position:absolute;top:2px;right:2px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:600;z-index:5;pointer-events:none;" title="Anda tidak berhak sign' . h($rolesHint) . '"><i class="fa fa-lock"></i></div>';
            $imgContent = $ttdImg
                ? '<img src="' . h($ttdImg) . '" class="ttd-signature" alt="TTD">' . $lockBadge
                : '<div class="ttd-canvas-placeholder ttd-locked" style="cursor:not-allowed;background:#f3f4f6;position:relative;" title="Anda tidak berhak sign sebagai ' . h($label) . h($rolesHint) . '">' . $lockBadge . '</div>';
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
                <button type="button" class="btn-edit-qr-content" onclick="editQrContent(\'' . h($ttdId) . '\', \'' . h($patternForJs) . '\')" title="Edit isi QR" style="display:block;margin:4px auto 0;font-size:10px;padding:2px 8px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;">&#9998; Edit QR</button>
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
                ? '<small style="color:#f59e0b;font-size:10px;">Simpan dokumen dulu</small>'
                : '<small style="color:#888;font-size:10px;">Loading QR...</small>')
            . '</div>'
            . '<div style="font-size:9px;color:#666;margin-top:2px;">Scan untuk verifikasi</div>'
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
            $imgInner = '<div class="materai-empty-box" style="' . $sizeStyle . '" title="Area materai (tempel manual)"></div>';
        } else {
            // Upload mode
            if ($matImg) {
                $imgInner = '<img src="' . h($matImg) . '" class="materai-image" alt="Materai" style="max-width:' . $matW . 'px;max-height:' . $matH . 'px;">';
                if (!$param_is_locked) {
                    $imgInner .= '<div class="materai-actions">
                        <label for="' . $fileInputId . '" class="btn-edit" style="cursor:pointer;">&#9998; Ganti</label>
                        <button type="button" class="btn-delete" onclick="clearMaterai(\'' . h($materaiId) . '\')">&#10005; Hapus</button>
                    </div>';
                }
            } else {
                if (!$param_is_locked) {
                    // Use <label for=""> so click reliably opens hidden file input
                    $imgInner = '<label for="' . $fileInputId . '" class="materai-upload-box" style="' . $sizeStyle . 'cursor:pointer;">
                        <strong>UPLOAD<br>e-MATERAI</strong>
                    </label>';
                } else {
                    $imgInner = '<div class="materai-empty-box" style="' . $sizeStyle . '" title="Belum di-upload"></div>';
                }
            }
        }

        $hidden  = '<input type="hidden" name="' . h($imgKey) . '" id="materai_img_' . h($materaiId) . '" value="' . h($matImg) . '">';
        $hidden .= '<input type="hidden" name="' . h($uploadKey) . '" id="materai_upload_' . h($materaiId) . '" value="' . h($dbFields[$uploadKey] ?? '') . '">';

        $fileInput = '';
        $serialInput = '';
        if ($matMode === 'upload' && !$param_is_locked) {
            $fileInput = '<input type="file" id="' . $fileInputId . '" accept="image/png,image/jpeg" style="position:absolute;left:-9999px;opacity:0;width:0;height:0;" onchange="handleMateraiUpload(this, \'' . h($materaiId) . '\')">';
            $serialInput = '<input type="text" class="materai-serial-input" name="' . h($serialKey) . '" id="materai_serial_' . h($materaiId) . '" value="' . $matSerial . '" placeholder="No. Seri (opsional)" maxlength="30">';
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
            $qrImgHtml = '<img src="' . $qrSrc . '" class="qr-img" style="width:100%;height:auto;cursor:pointer;" alt="QR" id="qrimg_' . h($fieldName) . '" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" title="Klik untuk edit isi QR">';
        } else {
            // Kosong → placeholder clickable. Klik → modal muncul dengan default verify_url
            $qrImgHtml = '<div class="qr-canvas-placeholder" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" style="cursor:pointer;" title="Klik untuk generate QR (default: URL verifikasi)"></div>';
        }

        // Klik container QR → buka modal (onclick di parent, jadi bekerja bahkan setelah updateQrPreview replace innerHTML)
        return '<div class="' . $qrClass . '" style="' . $style . '" data-qr-field="' . h($fieldName) . '">
            <div class="qr-preview" id="qrpreview_' . h($fieldName) . '" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" style="cursor:pointer;" title="Klik untuk isi/edit QR">' . $qrImgHtml . '</div>
            <div class="qr-input" style="display:none;">
                <input type="text" class="qr-field" id="qrinput_' . h($fieldName) . '" name="' . h($fieldName) . '"
                    value="' . h($qrDataRaw) . '" placeholder="Data QR..." onchange="updateQrPreview(\'' . h($fieldName) . '\')">
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
    $reqMark = !empty($validation['required']) ? '<span class="field-required-mark" title="Wajib diisi">*</span>' : '';

    switch ($type) {
        case 'number':
            return '<span class="f-wrap"' . $vAttrs . '><span class="f field-number" contenteditable="true" inputmode="numeric" data-field="' . $hName . '">' . ($hVal ?: '') . '</span><input type="hidden" name="' . $hName . '" value="' . $hVal . '">' . $reqMark . '</span>';

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
            $html .= '<option value="">-- Pilih --</option>';
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
        @page {
            size: ' . $paperDim['width'] . 'mm ' . $paperDim['height'] . 'mm;
            margin: 0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Times New Roman", "DejaVu Sans", serif;
            font-size: 12pt;
            margin: 0;
            padding: 0;
        }
        .page {
            width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
            min-height: ' . ($paperDim['height'] - $padTop - $padBottom) . 'mm;
            margin: ' . $padTop . 'mm ' . $padRight . 'mm ' . $padBottom . 'mm ' . $padLeft . 'mm;
            position: relative;
        }
        .content {
            line-height: 1.6;
        }
        .content p {
            margin: 8px 0;
            min-height: 1.2em;
        }
        .content p.floating-only { min-height: 0; margin: 0; line-height: 0; }
        .content table { border-collapse: collapse; width: 100%; }
        /* Opt-in fixed layout: add class "tbl-fixed" on a table to force equal columns (useful for header-logo rows) */
        .content table.tbl-fixed { table-layout: fixed; }
        .content td, .content th {
            border: 1px solid #ccc;
            padding: 6px;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .content table[border="0"] td, .content table[border="0"] th { border: none; }
        /* Logos & images in PDF: never exceed container */
        .content img { max-width: 100%; height: auto; }
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

        /* TTD */
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
        .ttd-label { font-size: 11pt; margin-bottom: 5px; }
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
            $label = h($ttd['label'] ?? 'Tanda Tangan');
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

    // Render PDF — prefer $ctx->pdf if consumer wired one, fallback to legacy generatePDF() helper.
    // spec: ezdoc-spec/services/pdf_renderer.md
    $paperMm     = [$paperDim['width'], $paperDim['height']];
    $orientation = 'portrait';
    if (isset($ctx->pdf) && is_object($ctx->pdf) && method_exists($ctx->pdf, 'stream')) {
        $ctx->pdf->stream($pdfHtml, $filename, $paperMm, $orientation);
    } elseif (function_exists('generatePDF')) {
        generatePDF($pdfHtml, $filename, true, $paperMm, $orientation);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body><h1>PDF renderer not configured</h1><p>Wire <code>$ctx->pdf</code> or define <code>generatePDF()</code>.</p></body>';
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
        preg_match('/data-label="([^"]+)"/', $tag, $lm); $label = $lm[1] ?? 'Tanda Tangan';
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
                    $contentHtml = '<img src="' . $qrSrc . '" style="width:100px;height:100px;max-width:none;max-height:none;" alt="QR Verifikasi">'
                        . '<div style="font-size:9px;color:#666;margin-top:2px;">Scan untuk verifikasi</div>';
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
    <style>
        * { box-sizing: border-box; }
        body { font-family: "Times New Roman", serif; background: #6b7280; margin: 0; padding: 30px; font-size: 12pt; }
        .page {
            width: <?= $paperDim['width'] ?>mm;
            min-height: <?= $paperDim['height'] ?>mm;
            margin: 0 auto;
            padding: <?= $padTop ?>mm <?= $padRight ?>mm <?= $padBottom ?>mm <?= $padLeft ?>mm;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            position: relative;
        }
        @media screen and (max-width: 900px) {
            body { padding: 10px; }
            .page { width: 100%; padding: 15px; }
        }

        /* Content styles from editor */
        .content { line-height: 1.6; }
        .content p { margin: 8px 0; min-height: 1.2em; }
        /* Collapse paragraph that only contains floating/absolute elements */
        .content p.floating-only { min-height: 0; margin: 0; line-height: 0; }
        .content table { border-collapse: collapse; width: 100%; }
        /* Opt-in fixed layout (matches PDF rule) */
        .content table.tbl-fixed { table-layout: fixed; }
        .content td, .content th { border: 1px solid #ccc; padding: 6px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .content table[border="0"] td, .content table[border="0"] th { border: none; }

        /* Field (contenteditable) - auto-adjusts for 1 or multi line */
        .f-wrap { display: inline; }
        .f {
            font-family: inherit;
            font-size: inherit;
            font-weight: inherit;
            font-style: inherit;
            color: inherit;
            line-height: inherit;
            border-bottom: 1px dotted #333;
            /* background: rgba(219, 234, 254, 0.3); */
            padding: 1px 4px;
            margin: 0;
            outline: none;
            display: inline;
            min-width: 40px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .f:focus {
            background: rgba(219, 234, 254, 0.7);
            border-bottom-color: #3b82f6;
        }
        .f:empty::before {
            content: '........';
            color: #9ca3af;
        }
        .edit-off .f {
            border-bottom: none;
            background: transparent;
            pointer-events: none;
        }
        .edit-off .f:empty::before {
            content: '';
        }

        /* Field types */
        .field-date, .field-select {
            font-family: inherit;
            font-size: inherit;
            border: none;
            border-bottom: 1px dotted #333;
            background: rgba(219, 234, 254, 0.3);
            padding: 2px 6px;
            outline: none;
        }
        .field-number {
            /* Number uses contenteditable like text, just different background */
            background: rgba(254, 243, 199, 0.3);
        }
        .field-date { width: 140px; }
        .field-select {
            padding: 3px 6px;
            border-radius: 3px;
            border: 1px solid #d1d5db;
            background: #fff;
        }
        .field-checkbox-wrap {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }
        .field-checkbox {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .field-radio-wrap {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .field-radio-label {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
            font-size: inherit;
        }
        .field-radio-label input {
            width: 14px;
            height: 14px;
            cursor: pointer;
        }
        .edit-off .field-date,
        .edit-off .field-select {
            border: none;
            background: transparent;
            pointer-events: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .edit-off .field-number {
            background: transparent;
        }
        .edit-off .field-checkbox,
        .edit-off .field-radio-label input {
            pointer-events: none;
        }
        @media print {
            .field-date {
                border: none !important;
                background: transparent !important;
            }
            .field-number {
                background: transparent !important;
            }
            .field-select {
                border: none !important;
                background: transparent !important;
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
            }
        }

        /* Logo */
        .logo-img { height: auto; vertical-align: middle; }
        .logo-floating { position: absolute; }
        .logo-behind { z-index: -1; }
        .logo-front { z-index: 100; }
        .logo-empty {
            display: inline-block; padding: 8px 12px; background: #fef3c7;
            border: 1px dashed #f59e0b; color: #92400e; font-size: 11px; border-radius: 4px;
        }

        /* QR Code */
        .qr-item-inline {
            display: inline-block;
            text-align: center;
            vertical-align: top;
            margin: 5px;
        }
        .qr-item-floating {
            position: absolute;
            text-align: center;
        }
        .qr-behind { z-index: -1; }
        .qr-front { z-index: 100; }
        .qr-preview { min-height: 50px; display: flex; align-items: center; justify-content: center; }
        .qr-img { max-width: 100%; height: auto; }
        .qr-canvas-placeholder {
            width: 80px; height: 80px;
            border: 2px dashed #6366f1;
            background: #eef2ff;
            cursor: pointer;
            margin: 0 auto;
            display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
        }
        .qr-canvas-placeholder::before { content: 'QR'; color: #6366f1; font-size: 14px; font-weight: bold; }
        .qr-canvas-placeholder:hover { background: #e0e7ff; }
        .qr-input { margin-top: 5px; }
        .qr-field {
            width: 100%; max-width: 150px;
            font-size: 10px; padding: 4px 6px;
            border: 1px solid #d1d5db; border-radius: 4px;
            text-align: center;
        }
        .qr-field:focus { outline: none; border-color: #6366f1; }
        .edit-off .qr-input { display: none; }
        .edit-off .qr-canvas-placeholder { display: none; }
        @media print {
            .qr-input { display: none !important; }
            .qr-canvas-placeholder { display: none !important; }
        }

        /* TTD Placeholder Items */
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
        .ttd-img { min-height: 50px; display: flex; align-items: center; justify-content: center; }
        .ttd-signature { max-height: 60px; max-width: 120px; }
        .ttd-canvas-placeholder {
            width: 100px; height: 50px;
            border: 1px dashed #10b981;
            background: #ecfdf5;
            cursor: pointer;
            margin: 0 auto;
        }
        .ttd-canvas-placeholder:hover { background: #d1fae5; }
        .ttd-name { font-size: 11pt; margin-top: 3px; }
        .ttd-name .f { min-width: 80px; }

        /* TTD Hover Actions */
        .ttd-img { position: relative; }
        .ttd-img .ttd-actions {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            display: none;
            gap: 5px;
            background: rgba(0,0,0,0.7);
            padding: 5px 8px;
            border-radius: 6px;
        }
        .ttd-img:hover .ttd-actions { display: flex; }
        .ttd-img .ttd-actions button {
            padding: 4px 8px;
            font-size: 11px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .ttd-img .ttd-actions .btn-edit { background: #3b82f6; color: #fff; }
        .ttd-img .ttd-actions .btn-edit:hover { background: #2563eb; }
        .ttd-img .ttd-actions .btn-delete { background: #ef4444; color: #fff; }
        .ttd-img .ttd-actions .btn-delete:hover { background: #dc2626; }
        .edit-off .ttd-actions { display: none !important; }

        /* TTD Mode Toggle */
        .ttd-mode-toggle { display: flex; gap: 2px; margin-bottom: 4px; justify-content: center; }
        .ttd-mode-btn { font-size: 10px; padding: 1px 8px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; border-radius: 3px; }
        .ttd-mode-btn.active { background: #10b981; color: #fff; border-color: #10b981; }
        .ttd-mode-btn:hover { background: #ecfdf5; }
        .ttd-mode-btn.active:hover { background: #059669; }
        .ttd-area-qr { text-align: center; min-height: 50px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; }
        .ttd-qr-preview {
            width: 110px !important;
            height: 110px !important;
            min-width: 110px !important;
            min-height: 110px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto !important;
            flex-shrink: 0 !important;
        }
        .ttd-qr-preview img {
            width: 100px !important;
            height: 100px !important;
            max-width: 100px !important;
            max-height: 100px !important;
            min-width: 100px !important;
            min-height: 100px !important;
            object-fit: contain !important;
            display: block !important;
            aspect-ratio: 1 / 1 !important;
        }
        /* Verify QR area — reuse dimensi QR biasa, tambah styling khas verify (warna teal) */
        .ttd-area-verify-qr { text-align: center; min-height: 50px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; }
        .ttd-verify-qr-preview {
            width: 110px !important;
            height: 110px !important;
            min-width: 110px !important;
            min-height: 110px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto !important;
            flex-shrink: 0 !important;
        }
        .ttd-verify-qr-preview img {
            width: 100px !important;
            height: 100px !important;
            max-width: 100px !important;
            max-height: 100px !important;
            min-width: 100px !important;
            min-height: 100px !important;
            object-fit: contain !important;
            display: block !important;
            aspect-ratio: 1 / 1 !important;
        }
        /* Toggle Verify QR button — visual indicator saat ON */
        #btnToggleVerifyQr { transition: background 0.2s; }
        .ttd-qr-content-input {
            width: 100%;
            max-width: 160px;
            font-size: 10px;
            padding: 2px 5px;
            border: 1px dashed #9ca3af;
            border-radius: 3px;
            background: rgba(243, 244, 246, 0.5);
            outline: none;
            box-sizing: border-box;
        }
        .ttd-qr-content-input:focus { border-color: #10b981; background: #fff; }
        .edit-off .ttd-qr-content-input { display: none !important; }
        .edit-off .ttd-mode-toggle { display: none !important; }

        /* Materai Section */
        .materai-wrap { display: inline-block; text-align: center; vertical-align: top; }
        .materai-item-floating { position: absolute; }
        .materai-behind { z-index: -1; }
        .materai-front { z-index: 100; }
        .materai-label { font-size: 9pt; margin-bottom: 3px; color: #c2410c; font-weight: bold; }
        /* Size on .materai-img is set inline (per placeholder data-width/height) */
        .materai-img { position: relative; margin: 0 auto; display: flex; align-items: center; justify-content: center; }
        .materai-img .materai-image { max-width: 100%; max-height: 100%; }
        .materai-empty-box { border: 1px dashed #c2410c; background: #fff7ed; }
        .materai-upload-box {
            border: 2px dashed #c2410c; background: #fff7ed; color: #c2410c;
            font-size: 10px; line-height: 1.3;
            display: flex; align-items: center; justify-content: center; text-align: center;
            cursor: pointer;
        }
        .materai-upload-box:hover { background: #fed7aa; }
        .materai-actions {
            position: absolute; bottom: 2px; right: 2px; display: none;
            background: rgba(0,0,0,0.65); border-radius: 4px; padding: 2px;
        }
        .materai-img:hover .materai-actions { display: flex; gap: 2px; }
        .materai-actions button {
            font-size: 9px; padding: 2px 5px; border: none; cursor: pointer; border-radius: 3px;
        }
        .materai-actions .btn-edit { background: #3b82f6; color: #fff; }
        .materai-actions .btn-delete { background: #ef4444; color: #fff; }
        .materai-serial-input {
            display: block; margin: 4px auto 0; width: 95%; max-width: 110px;
            font-size: 9pt; padding: 1px 4px; border: 1px dashed #c2410c;
            background: rgba(255,247,237,0.5); border-radius: 3px; outline: none;
        }
        .materai-serial-input:focus { background: #fff; }
        .edit-off .materai-actions { display: none !important; }
        .edit-off .materai-upload-box { cursor: default; pointer-events: none; }
        .edit-off .materai-serial-input { border: none; background: transparent; }

        /* TTD Section — untuk struktur lama .ttd-wrap > .ttd-item (bukan placeholder-based) */
        .ttd-wrap { margin-top: 40px; display: flex; justify-content: center; flex-wrap: wrap; gap: 25px; }
        .ttd-item { flex: 0 0 calc(33.33% - 25px); min-width: 130px; text-align: center; }
        .ttd-wrap .ttd-img { height: 70px; display: flex; align-items: center; justify-content: center; }
        .ttd-wrap .ttd-img img { max-height: 65px; max-width: 100%; }
        .ttd-wrap .ttd-name { margin-top: 5px; }
        .ttd-wrap .ttd-name .f { text-align: center; }

        /* Dynamic Tables */
        .dyntable-rendered { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        .dyntable-rendered th, .dyntable-rendered td { padding: 4px 8px; vertical-align: top; }
        .dyntable-rendered th { font-weight: bold; }
        .dyntable-rendered thead { display: table-header-group; }
        .dyntable-rendered tr { page-break-inside: avoid; }
        .dyntable-empty { color: #999; font-style: italic; text-align: center; padding: 10px; }
        @media print {
            .dyntable-rendered thead { display: table-header-group; }
            .dyntable-rendered tr { page-break-inside: avoid; break-inside: avoid; }
        }

        /* TOOLBAR - V1 Style (Right Panel) */
        .toolbar {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #222;
            padding: 10px;
            border-radius: 8px;
            z-index: 1000;
            max-height: 90vh;
            overflow-y: auto;
            width: 190px;
            transition: width 0.2s ease, height 0.2s ease, padding 0.2s ease, max-height 0.2s ease, background 0.2s ease;
            box-sizing: border-box;
        }
        .toolbar-toggle {
            display: block;
            width: 100%;
            padding: 4px 8px;
            border: none;
            background: #333;
            color: #bbb;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            text-align: right;
            margin-bottom: 8px;
            height: 22px;
        }
        .toolbar-toggle:hover { background: #444; color: #fff; }
        .toolbar.collapsed {
            width: 44px;
            height: 44px;
            min-height: 0;
            max-height: 44px;
            padding: 0;
            overflow: hidden;
            background: transparent;
        }
        .toolbar.collapsed .toolbar-body { display: none; }
        .toolbar.collapsed .toolbar-toggle {
            display: block;
            width: 44px;
            height: 44px;
            padding: 0;
            margin: 0;
            border-radius: 8px;
            line-height: 44px;
            font-size: 20px;
            text-align: center;
            background: #16a34a;
            color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }
        .toolbar.collapsed .toolbar-toggle:hover { background: #15803d; }
        .toolbar button:not(.toolbar-toggle) {
            display: block;
            width: 100%;
            margin: 5px 0;
            padding: 8px 10px;
            color: #fff;
            background: #444;
            border: none;
            cursor: pointer;
            border-radius: 6px;
            font-size: 13px;
        }
        .toolbar button:not(.toolbar-toggle):hover { background: #555; }
        .toolbar button.btn-success { background: #16a34a; }
        .toolbar button.btn-success:hover { background: #15803d; }
        /* Dirty state — pulse amber to remind user to save */
        .toolbar button.btn-dirty { background: #f59e0b !important; animation: dirtyPulse 1.6s ease-in-out infinite; }
        .toolbar button.btn-dirty:hover { background: #d97706 !important; }
        @keyframes dirtyPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            50%      { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
        }
        /* Conditional Section (#7) — visual hint in edit mode only */
        .edit-on .conditional-section {
            border-left: 3px solid #06b6d4;
            background: rgba(236, 254, 255, 0.4);
            padding: 4px 8px;
            margin: 4px 0;
        }
        /* Hide marker in print + locked view (final document) */
        .edit-off .conditional-section,
        .conditional-section { border: none; background: transparent; padding: 0; margin: inherit; }
        @media print {
            .conditional-section { border: none !important; background: transparent !important; padding: 0 !important; }
            .conditional-section[data-cond-result="0"] { display: none !important; }
        }

        /* Field validation (#6) */
        .field-required-mark { color: #dc2626; font-weight: bold; margin-left: 2px; user-select: none; }
        .f-wrap.field-invalid > .f, .f-wrap.field-invalid > input[type="date"], .f-wrap.field-invalid > select {
            background: #fee2e2 !important;
            border-bottom-color: #dc2626 !important;
        }
        .f-wrap.field-invalid::after {
            content: attr(data-v-error-display);
            display: block;
            font-size: 10px;
            color: #dc2626;
            background: #fef2f2;
            padding: 1px 4px;
            border-radius: 3px;
            margin-top: 2px;
        }
        /* Hide validation marks/errors in print/PDF & locked/edit-off */
        .edit-off .field-required-mark { display: none; }
        @media print {
            .field-required-mark { display: none !important; }
            .f-wrap.field-invalid::after { display: none !important; }
            .f-wrap.field-invalid > * { background: transparent !important; border-color: inherit !important; }
        }

        /* kbd styling for keyboard shortcuts modal */
        kbd {
            display: inline-block;
            padding: 1px 6px;
            font-size: 11px;
            font-family: ui-monospace, Menlo, Consolas, monospace;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            box-shadow: 0 1px 0 #d1d5db;
            color: #111;
        }
        .toolbar .template-name {
            color: #aaa;
            font-size: 11px;
            text-align: center;
            margin-bottom: 5px;
        }
        .toolbar .doc-info {
            color: #4ade80;
            font-size: 11px;
            text-align: center;
            padding-bottom: 8px;
            margin-bottom: 8px;
            border-bottom: 1px solid #444;
        }
        .toolbar .doc-info.new { color: #fbbf24; }
        .toolbar .meta-section {
            background: #333;
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .toolbar .meta-section label {
            display: block;
            color: #aaa;
            font-size: 10px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        .toolbar .meta-section input {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #555;
            border-radius: 4px;
            background: #444;
            color: #fff;
            font-size: 12px;
            margin-bottom: 6px;
            box-sizing: border-box;
        }
        .toolbar .meta-section input:focus {
            outline: none;
            border-color: #4ade80;
        }

        @media screen and (max-width: 1024px) {
            .toolbar { top: auto; bottom: 10px; right: 10px; max-height: 50vh; width: 170px; }
            .toolbar button { font-size: 12px; }
        }

        /* Toast */
        .toast {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            padding: 12px 24px; border-radius: 8px; color: #fff; z-index: 2000;
            animation: fadeOut 3s forwards;
        }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }
        @keyframes fadeOut { 0%,80% { opacity: 1; } 100% { opacity: 0; } }

        /* Modal - Fullscreen TTD */
        .modal {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85);
            z-index: 3000; align-items: center; justify-content: center;
        }
        .modal.show { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 12px;
            width: 95vw;
            max-width: 900px;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }
        .modal-body {
            flex: 1;
            padding: 20px;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
        }
        #signCanvas {
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
            cursor: crosshair;
            touch-action: none;
            background: #fff;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            text-align: right;
            flex-shrink: 0;
            background: #f9fafb;
            border-radius: 0 0 12px 12px;
        }
        .modal-footer button {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-left: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .modal-footer button:hover { opacity: 0.9; }
        .sign-hint {
            text-align: center;
            color: #6b7280;
            font-size: 13px;
            margin-top: 10px;
        }

        /* Print */
        @page {
            size: <?= $paperDim['width'] ?>mm <?= $paperDim['height'] ?>mm;
            margin: 0;
        }
        @media print {
            /* Preserve background colors & images (browsers strip them by default on print) */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            body { background: #fff; padding: 0; margin: 0; font-size: 12pt; }
            .page {
                width: <?= $paperDim['width'] ?>mm;
                min-height: <?= $paperDim['height'] ?>mm;
                margin: 0;
                padding: <?= $padTop ?>mm <?= $padRight ?>mm <?= $padBottom ?>mm <?= $padLeft ?>mm;
                box-shadow: none;
                position: relative;
            }
            .toolbar, .modal, .toast { display: none !important; }
            .f { border-bottom: none !important; background: transparent !important; }
            .f:empty::before { display: none; }
            .ttd-actions { display: none !important; }
            .ttd-mode-toggle { display: none !important; }
            .materai-actions { display: none !important; }
            .materai-upload-box { display: none !important; }
            .materai-serial-input { border: none !important; background: transparent !important; }
            .ttd-qr-content-input { display: none !important; }
            .ttd-canvas-placeholder { display: none !important; }
        }
    </style>
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
                    <div class="ttd-label"><?= h($ttd['label'] ?? 'Tanda Tangan') ?></div>
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
            <button type="button" class="toolbar-toggle" id="toolbarToggleBtn" onclick="toggleToolbar()" title="Tampilkan/Sembunyikan Toolbar">&#9776;</button>
            <div class="toolbar-body">
                <div class="template-name"><?= h($template['nama_template']) ?></div>
                <div class="paper-info text-violet-500 text-[10px] text-center mb-[5px]">
                    <?= h($paperSize) ?> (<?= $paperDim['width'] ?>×<?= $paperDim['height'] ?>mm)
                </div>
                <?php if ($param_is_deleted): ?>
                <div class="bg-red-900 text-white p-2 rounded-md mb-2 text-[11px] text-center border border-dashed border-red-300">
                    <i class="bi bi-trash-fill"></i> <strong>DOKUMEN TERHAPUS</strong><br>
                    <span class="text-[10px] text-[#fecaca]">
                        Mode preview (read-only)<br>
                        Hapus pada: <?= h(date('d/m/Y H:i', strtotime($param_deleted_at))) ?><br>
                        Oleh: <?= h($param_deleted_by ?: '-') ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="doc-info <?= $isEditMode ? '' : 'new' ?>" id="docInfoText">
                    <?php if ($isEditMode): ?>
                        ID: <?= $doc_id ?> · v<?= $param_version ?>
                        <?php if ($param_is_deleted): ?>
                            <span class="text-red-400"><i class="bi bi-trash-fill"></i> Deleted</span>
                        <?php elseif ($param_is_locked): ?>
                            <span class="text-amber-500"><i class="bi bi-lock-fill"></i> Locked</span>
                        <?php else: ?>
                            (Edit)
                        <?php endif; ?>
                        <button class="ml-1 py-0 px-1.5 bg-gray-700 border border-gray-500 text-gray-300 rounded-full cursor-pointer text-[10px] w-[18px] h-[18px] leading-[14px]" type="button" onclick="showDocInfo()" title="Detail dokumen">i</button>
                    <?php else: ?>
                        Dokumen Baru
                    <?php endif; ?>
                </div>

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
                        <!-- Template scope=general: tidak butuh NORM/NOPEN. Kirim hidden supaya form tetap valid -->
                        <div class="bg-indigo-50 text-indigo-700 py-1.5 px-2 rounded text-[11px] mb-1.5">
                            <i class="bi bi-info-circle"></i> Surat Umum — tidak butuh data pasien
                        </div>
                        <input type="hidden" name="_norm" id="inputNorm" value="">
                        <input type="hidden" name="_nopen" id="inputNopen" value="">
                        <label>Label Dokumen <small class="text-gray-400">(pembeda antar dokumen)</small></label>
                        <input type="text" name="_label" id="inputLabel" value="<?= h($param_label) ?>" placeholder="- (default)" <?= $param_is_locked ? 'readonly' : '' ?>>
                    <?php else: ?>
                        <label>No Rekam Medis *</label>
                        <input type="text" name="_norm" id="inputNorm" value="<?= h($param_norm) ?>" placeholder="No RM" required <?= $param_is_locked ? 'readonly' : '' ?>>
                        <label>No Pendaftaran *</label>
                        <input type="text" name="_nopen" id="inputNopen" value="<?= h($param_nopen) ?>" placeholder="No Pendaftaran" required <?= $param_is_locked ? 'readonly' : '' ?>>
                        <label>Label Dokumen <small class="text-gray-400">(pembeda jika nopen sama)</small></label>
                        <input type="text" name="_label" id="inputLabel" value="<?= h($param_label) ?>" placeholder="- (default)" <?= $param_is_locked ? 'readonly' : '' ?>>
                    <?php endif; ?>
                </div>

                <!-- Version picker (only shown when doc exists) -->
                <?php if ($isEditMode): ?>
                <div class="meta-section" id="versionSection">
                    <label>Versi Dokumen</label>
                    <select class="w-full" id="versionSelect" onchange="switchVersion(this.value)">
                        <option value="<?= $param_version ?>">v<?= $param_version ?> (current)</option>
                    </select>
                </div>
                <?php endif; ?>

                <button type="button" class="btn-success" onclick="submitForm()" <?= $param_is_locked ? 'disabled title="Locked - tidak bisa update"' : '' ?>>
                    <?= $isEditMode ? ($param_is_locked ? '🔒 Locked' : 'Update') : 'Simpan Baru' ?>
                </button>
                <button type="button" id="btnToggleVerifyQr"
                        onclick="toggleVerifyQrMode()"
                        title="Ganti TTD gambar dengan QR verifikasi (untuk share dokumen yang bisa di-verify oleh penerima)"
                        style="background:<?= $__showVerifyQrInit ? '#0d9488' : '#64748b' ?>;color:#fff;">
                    <i class="bi bi-qr-code"></i>
                    <span id="btnToggleVerifyQrLabel"><?= $__showVerifyQrInit ? 'Mode Verify QR: ON' : 'Mode Verify QR: OFF' ?></span>
                </button>
                <button type="button" onclick="window.print()" title="Print (Ctrl+P)">Print</button>
                <?php // Slot: toolbar-extra-actions — consumer buttons (Export Excel, WhatsApp, Email PDF, e-Sign, Copy Link)
                // spec: ezdoc-spec/slots/generate.md#toolbar-extra-actions ?>
                <?= \Ezdoc\UI\Slot::render('generate:toolbar-extra-actions', [
                    'doc_id'    => (int)$doc_id,
                    'template'  => $template,
                    'is_locked' => (bool)$param_is_locked,
                ]) ?>
                <button class="bg-slate-600 text-[10px] py-1 px-2" type="button" onclick="showShortcutsHelp()" title="Lihat keyboard shortcuts (Ctrl+/)"><i class="bi bi-keyboard"></i> Shortcuts</button>
                <?php if ($isSuperadmin): ?>
                    <button class="bg-emerald-600" type="button" onclick="viewPdfRaw()" title="Lihat hasil PDF mentah (superadmin)"><i class="bi bi-file-earmark-pdf"></i> Lihat PDF</button>
                <?php endif; ?>
                <?php if ($isEditMode && $param_is_deleted && $isSuperadmin): ?>
                    <!-- Deleted document preview: show only Restore action -->
                    <button class="bg-green-600" type="button" onclick="restoreDeletedSlot()" title="Pulihkan slot ini">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore Slot
                    </button>
                    <?php
                    // Trash-list URL — config-driven, {template_id} placeholder replaced.
                    // spec: ezdoc-spec/config/urls.md#trash_list
                    if ($urlTrashList !== ''):
                        $__trashHref = str_replace('{template_id}', (string)$template_id, $urlTrashList);
                    ?>
                    <a href="<?= h($__trashHref) ?>" style="background:#374151;color:#fff;padding:6px 10px;border-radius:4px;text-decoration:none;display:inline-block;text-align:center;font-size:13px;margin-top:4px;">
                        <i class="bi bi-arrow-left"></i> Trash List
                    </a>
                    <?php endif; ?>
                <?php elseif ($isEditMode): ?>
                    <?php if (!$param_is_locked): ?>
                        <button class="bg-gray-500" type="button" id="btnDocLock" onclick="toggleDocLock()" title="Kunci versi ini (final) — setelah locked hanya superadmin yang bisa unlock">
                            <i class="bi bi-lock"></i> Lock Final
                        </button>
                    <?php elseif ($isSuperadmin): ?>
                        <button class="bg-amber-500" type="button" id="btnDocLock" onclick="toggleDocLock()" title="Unlock versi ini (superadmin)">
                            <i class="bi bi-unlock"></i> Unlock (Admin)
                        </button>
                    <?php else: ?>
                        <button class="bg-gray-600 opacity-60 cursor-not-allowed" type="button" disabled title="Locked — unlock hanya oleh superadmin. Untuk revisi, buat versi baru.">
                            <i class="bi bi-lock-fill"></i> Locked
                        </button>
                    <?php endif; ?>
                    <button class="bg-violet-500" type="button" onclick="showNewVersionModal()"><i class="bi bi-plus-circle"></i> Versi Baru</button>
                    <?php if ($isSuperadmin): ?>
                    <button type="button" onclick="deleteThisVersion()" style="background:#dc2626;" <?= $param_is_locked ? 'disabled title="Locked - unlock dulu"' : '' ?>><i class="bi bi-trash"></i> Hapus Versi</button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php // "Change template" button intentionally omitted from library default.
                // Consumers who want it can inject via generate:toolbar-extra-actions slot.
                ?>
            </div>
        </div>
    </form>

    <!-- Sign Modal - Fullscreen -->
    <div class="modal" id="signModal">
        <div class="modal-box">
            <div class="modal-header" id="signTitle">Tanda Tangan</div>
            <div class="modal-body">
                <div class="w-full">
                    <canvas id="signCanvas" width="850" height="400"></canvas>
                    <div class="sign-hint">Gambar tanda tangan di area di atas menggunakan mouse atau sentuhan layar</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="bg-red-500 text-white" type="button" onclick="clearSign()">Hapus</button>
                <button class="bg-green-500 text-white" type="button" onclick="saveSign()">Simpan</button>
                <button class="bg-gray-500 text-white" type="button" onclick="closeSign()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- QR Field Generate Modal — untuk standalone QR field, klik area → modal → OK → isi verify_url -->
    <div class="modal hidden" id="qrFieldModal">
        <div class="modal-box max-w-[520px]">
            <div class="modal-header" style="background:linear-gradient(90deg,#3b82f6 0%,#6366f1 100%);color:#fff;">
                <i class="bi bi-qr-code"></i> Isi QR Code
            </div>
            <div class="modal-body p-5 block">
                <p class="text-[13px] text-gray-700" style="margin:0 0 12px;">
                    Pilih isi QR untuk field <strong id="qrFieldModalName">-</strong>:
                </p>
                <div class="mb-3">
                    <label class="text-xs font-semibold text-gray-700 block mb-1">
                        <input class="mr-1.5" type="radio" name="qrFieldSource" value="verify" id="qrFieldSourceVerify" checked>
                        Pakai URL Verifikasi Dokumen (Recommended)
                    </label>
                    <div class="p-2 bg-green-50 rounded text-[11px] text-green-800 ml-5 break-all" id="qrFieldVerifyBox" style="font-family:monospace;">-</div>
                    <div class="hidden ml-5 mt-1.5 text-amber-700 text-[11px]" id="qrFieldVerifyWarn">
                        <i class="bi bi-exclamation-triangle"></i> Simpan dokumen dulu untuk generate URL verifikasi.
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-700 block mb-1">
                        <input class="mr-1.5" type="radio" name="qrFieldSource" value="custom" id="qrFieldSourceCustom">
                        Isi manual (custom)
                    </label>
                    <input class="w-[calc(100%_-_20px)] ml-5 p-1.5 text-xs border border-gray-300 rounded" type="text" id="qrFieldCustomInput" placeholder="Tulis isi QR di sini...">
                </div>
            </div>
            <div class="modal-footer text-right">
                <button class="bg-gray-500 text-white" type="button" onclick="closeQrFieldModal()">Batal</button>
                <button class="bg-blue-500 text-white" type="button" id="btnQrFieldConfirm" onclick="confirmQrField()">OK, Isi QR</button>
            </div>
        </div>
    </div>

    <!-- Verify QR Mode Confirmation Modal -->
    <div class="modal hidden" id="verifyQrConfirmModal">
        <div class="modal-box max-w-[520px]">
            <div class="modal-header" style="background:linear-gradient(90deg,#0d9488 0%,#14b8a6 100%);color:#fff;">
                <i class="bi bi-qr-code"></i> Aktifkan Mode QR Verifikasi
            </div>
            <div class="modal-body p-5">
                <div id="verifyQrModalMain">
                    <p style="margin:0 0 12px;">Semua <strong>TTD gambar</strong> di dokumen ini akan diganti dengan <strong>QR Verifikasi</strong>.</p>
                    <p class="text-xs text-gray-500" style="margin:0 0 12px;">Berguna untuk share PDF/print — penerima scan QR → diarahkan ke halaman verifikasi resmi untuk cek keaslian dokumen.</p>
                    <label class="text-xs text-gray-700 font-semibold">URL Verifikasi:</label>
                    <input class="w-full text-[11px] p-1.5 bg-gray-100 border border-gray-300 rounded mt-1" type="text" readonly id="verifyQrModalUrl" value="">
                    <div class="hidden text-amber-700 text-xs mt-2.5 p-2 bg-amber-100 rounded" id="verifyQrModalWarn">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span id="verifyQrModalWarnMsg">Dokumen belum di-save. Klik "Simpan & Aktifkan" untuk simpan dulu baru aktifkan.</span>
                    </div>
                </div>
                <div class="hidden text-center p-6" id="verifyQrModalSaving">
                    <div class="tw-spinner inline-block w-[2rem] h-[2rem] rounded-full" role="status" style="border:0.25em solid #cbd5e1;border-right-color:#3b82f6;animation:tw-spin 0.75s linear infinite;"></div>
                    <div class="mt-2.5 text-[13px] text-gray-500">Menyimpan dokumen...</div>
                </div>
            </div>
            <div class="modal-footer text-right">
                <button class="bg-gray-500 text-white" type="button" onclick="closeVerifyQrModal()">Batal</button>
                <button class="bg-teal-600 text-white" type="button" id="btnVerifyQrConfirm" onclick="confirmVerifyQrMode()">OK, Aktifkan</button>
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
            return base + sep + 'data=' + encodeURIComponent(data);
        };
    </script>
    <script>
        const templateId = <?= $template_id ?>;
        const isEditMode = <?= $isEditMode ? 'true' : 'false' ?>;
        // editMode is driven by the document lock state (inverse of is_locked)
        const editMode = <?= $param_is_locked ? 'false' : 'true' ?>;

        // ===== CONDITIONAL SECTIONS (#7) =====
        // Evaluator for simple expressions like:
        //   jenis_kelamin=P
        //   umur>=17
        //   jenis_kelamin=P AND umur>=17
        //   status!=lajang OR has_anak=1
        // Operators: = != > < >= <=
        // Logical: AND, OR (no parentheses)
        function evalCondExpr(expr) {
            if (!expr) return true;
            // Split by OR first (lower precedence)
            const orParts = expr.split(/\s+OR\s+/i);
            for (const orPart of orParts) {
                // Each OR part is AND-joined
                const andParts = orPart.split(/\s+AND\s+/i);
                let andOk = true;
                for (const cond of andParts) {
                    if (!evalSingleCond(cond.trim())) { andOk = false; break; }
                }
                if (andOk) return true; // any OR branch satisfied → true
            }
            return false;
        }

        function evalSingleCond(cond) {
            // Match operator (longest first)
            const ops = ['>=', '<=', '!=', '=', '>', '<'];
            let op = '', leftRaw = '', rightRaw = '';
            for (const o of ops) {
                const idx = cond.indexOf(o);
                if (idx > 0) {
                    op = o;
                    leftRaw = cond.substring(0, idx).trim();
                    rightRaw = cond.substring(idx + o.length).trim();
                    break;
                }
            }
            if (!op) return false;

            // Resolve left operand from form (field name)
            const fieldName = leftRaw;
            let leftVal = '';
            const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
            if (input) {
                if (input.type === 'checkbox') leftVal = input.checked ? '1' : '0';
                else leftVal = input.value || '';
            } else {
                const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                if (editable) leftVal = (editable.innerText || '').trim();
            }

            // Try numeric comparison if both sides parse as numbers
            const lNum = parseFloat(leftVal);
            const rNum = parseFloat(rightRaw);
            const numeric = !isNaN(lNum) && !isNaN(rNum);

            switch (op) {
                case '=':  return numeric ? (lNum === rNum) : (leftVal === rightRaw);
                case '!=': return numeric ? (lNum !== rNum) : (leftVal !== rightRaw);
                case '>':  return numeric ? (lNum >  rNum) : (leftVal >  rightRaw);
                case '<':  return numeric ? (lNum <  rNum) : (leftVal <  rightRaw);
                case '>=': return numeric ? (lNum >= rNum) : (leftVal >= rightRaw);
                case '<=': return numeric ? (lNum <= rNum) : (leftVal <= rightRaw);
            }
            return false;
        }

        // Apply conditional sections — toggle display
        function applyConditionalSections() {
            document.querySelectorAll('.conditional-section[data-cond]').forEach(el => {
                const expr = el.getAttribute('data-cond') || '';
                const ok = evalCondExpr(expr);
                el.style.display = ok ? '' : 'none';
                el.dataset.condResult = ok ? '1' : '0';
            });
        }

        // Evaluate on load + every input change (debounced)
        document.addEventListener('DOMContentLoaded', function() {
            applyConditionalSections();
            // Hook into existing field changes
            let _condTimer = null;
            const trigger = () => {
                clearTimeout(_condTimer);
                _condTimer = setTimeout(applyConditionalSections, 150);
            };
            document.querySelectorAll('.f[data-field], input[name], select[name]').forEach(el => {
                el.addEventListener('input', trigger);
                el.addEventListener('change', trigger);
            });
        });

        // ===== DIRTY STATE TRACKING (#22 unsaved changes indicator) =====
        let isDirty = false;
        const ORIG_TITLE = document.title;

        function markDirty() {
            if (!editMode) return; // locked docs are not editable
            if (isDirty) return;
            isDirty = true;
            // Title bar prefix asterisk
            if (!document.title.startsWith('* ')) document.title = '* ' + ORIG_TITLE;
            // Save button visual: change to amber/orange to signal pending save
            const btn = document.querySelector('.btn-success');
            if (btn) {
                btn.classList.add('btn-dirty');
                btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
                if (!btn.textContent.startsWith('● ')) btn.textContent = '● ' + btn.textContent;
            }
        }

        function markClean() {
            isDirty = false;
            document.title = ORIG_TITLE;
            const btn = document.querySelector('.btn-success');
            if (btn) {
                btn.classList.remove('btn-dirty');
                if (btn.textContent.startsWith('● ')) {
                    btn.textContent = btn.textContent.substring(2);
                }
            }
        }

        // Bind change tracking to all inputs/textareas/contenteditable in the form
        // Excluded: hidden inputs (auto-updated), file inputs (handled by upload handler manually)
        function bindDirtyTracking() {
            const form = document.getElementById('mainForm');
            if (!form) return;

            // Standard form controls
            form.querySelectorAll('input, textarea, select').forEach(el => {
                if (el.type === 'hidden') return;       // auto-set programmatically
                if (el.id && el.id.startsWith('ttd_'))   return; // hidden TTD inputs
                if (el.id && el.id.startsWith('materai_img_')) return;
                if (el.id && el.id.startsWith('materai_upload_')) return;
                el.addEventListener('input', markDirty);
                el.addEventListener('change', markDirty);
            });
            // Contenteditable
            form.querySelectorAll('[contenteditable="true"]').forEach(el => {
                el.addEventListener('input', markDirty);
            });
        }

        // Trigger dirty mark from external events (TTD canvas save, materai upload, mode switch)
        function triggerDirty() { markDirty(); }

        // Beforeunload warning when dirty
        window.addEventListener('beforeunload', function(e) {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = ''; // browser default warning message
                return '';
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            bindDirtyTracking();
        });
        let currentTtdId = null;
        let drawing = false;

        const modal = document.getElementById('signModal');
        const canvas = document.getElementById('signCanvas');
        const ctx = canvas.getContext('2d');

        ctx.strokeStyle = '#000';
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        // When document is locked: force all inputs/contenteditable to readonly state
        if (!editMode) {
            document.addEventListener('DOMContentLoaded', function() {
                // Disable contenteditable spans (.f)
                document.querySelectorAll('.f[contenteditable]').forEach(el => {
                    el.setAttribute('contenteditable', 'false');
                });
                // Set readonly on text/date/number inputs inside the content area + QR inputs
                const page = document.querySelector('.page');
                if (page) {
                    page.querySelectorAll('input[type="text"], input[type="date"], input[type="number"], input.qr-field, input.ttd-qr-content-input, textarea').forEach(el => {
                        el.readOnly = true;
                    });
                    // Disable select, checkbox, radio (readonly doesn't work on these)
                    page.querySelectorAll('select, input[type="checkbox"], input[type="radio"]').forEach(el => {
                        el.disabled = true;
                    });
                }
                // Also guard the toolbar meta inputs (norm, nopen, label) - they already have PHP readonly but double-check
                ['inputNorm', 'inputNopen', 'inputLabel'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.readOnly = true;
                });
            });
        }

        // Update QR preview when input changes
        async function updateQrPreview(fieldName) {
            const input = document.getElementById('qrinput_' + fieldName);
            const preview = document.getElementById('qrpreview_' + fieldName);
            if (!input || !preview) return;

            let data = input.value.trim();
            if (!data) {
                preview.innerHTML = '<div class="qr-canvas-placeholder" onclick="document.getElementById(\'qrinput_' + fieldName + '\').focus()"></div>';
                return;
            }

            // Resolve marker `{verify_url}` client-side ke URL current supaya QR preview
            // akurat. Server-side render tetap resolve marker saat cetak/PDF.
            if (data === '{verify_url}' || data === '{verify}') {
                const vu = document.getElementById('verifyUrlInput')?.value || '';
                if (!vu) {
                    preview.innerHTML = '<div class="p-2.5 text-amber-500 text-[10px]">Simpan dokumen dulu untuk generate verify URL</div>';
                    return;
                }
                data = vu;
            }

            // Show loading
            preview.innerHTML = '<div class="p-5 text-indigo-500 text-[11px]">Generating...</div>';

            try {
                const resp = await fetch(_ezdocQrUrl(data));
                const result = await resp.json();
                if (result.success) {
                    preview.innerHTML = '<img src="' + result.qr + '" class="qr-img" class="w-full h-auto" alt="QR">';
                } else {
                    preview.innerHTML = '<div class="p-2.5 text-red-600 text-[10px]">Error: ' + result.message + '</div>';
                }
            } catch (e) {
                preview.innerHTML = '<div class="p-2.5 text-red-600 text-[10px]">Error generating QR</div>';
            }
        }

        // AJAX Save - no reload
        // ===== Field Validation (#6) =====
        // Validates all .f-wrap elements with data-v-* attributes.
        // Returns true if all valid; otherwise marks invalid + returns false.
        function validateAllFields() {
            // Clear previous invalid markers
            document.querySelectorAll('.f-wrap.field-invalid').forEach(w => {
                w.classList.remove('field-invalid');
                w.removeAttribute('data-v-error-display');
            });

            let firstInvalid = null;
            let allValid = true;

            document.querySelectorAll('.f-wrap[data-v-required], .f-wrap[data-v-min], .f-wrap[data-v-max], .f-wrap[data-v-pattern]').forEach(wrap => {
                const required = wrap.getAttribute('data-v-required') === '1';
                const min = wrap.getAttribute('data-v-min');
                const max = wrap.getAttribute('data-v-max');
                const pattern = wrap.getAttribute('data-v-pattern');
                const customMsg = wrap.getAttribute('data-v-error-msg') || '';

                // Find value (could be in .f contenteditable, input[type=date], select, hidden input)
                let val = '';
                const editable = wrap.querySelector('.f[data-field]');
                if (editable) val = (editable.innerText || '').trim();
                else {
                    const ctrl = wrap.querySelector('input[type="date"], input[type="text"], input[type="number"], select, input[type="hidden"]');
                    if (ctrl) val = (ctrl.value || '').trim();
                }

                let err = '';
                if (required && val === '') {
                    err = customMsg || 'Wajib diisi';
                } else if (val !== '') {
                    // Number type vs text length
                    const ctrlNum = wrap.querySelector('.field-number, input[type="number"]');
                    const isNumber = !!ctrlNum;
                    if (min !== null && min !== '' && min !== undefined) {
                        if (isNumber) {
                            if (parseFloat(val) < parseFloat(min)) err = customMsg || ('Minimal nilai ' + min);
                        } else {
                            if (val.length < parseInt(min)) err = customMsg || ('Minimal ' + min + ' karakter');
                        }
                    }
                    if (!err && max !== null && max !== '' && max !== undefined) {
                        if (isNumber) {
                            if (parseFloat(val) > parseFloat(max)) err = customMsg || ('Maksimal nilai ' + max);
                        } else {
                            if (val.length > parseInt(max)) err = customMsg || ('Maksimal ' + max + ' karakter');
                        }
                    }
                    if (!err && pattern) {
                        try {
                            const re = new RegExp(pattern);
                            if (!re.test(val)) err = customMsg || 'Format tidak sesuai';
                        } catch (e) { /* invalid regex — skip */ }
                    }
                }

                if (err) {
                    wrap.classList.add('field-invalid');
                    wrap.setAttribute('data-v-error-display', err);
                    if (!firstInvalid) firstInvalid = wrap;
                    allValid = false;
                }
            });

            if (!allValid && firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const target = firstInvalid.querySelector('.f, input, select');
                if (target && target.focus) setTimeout(() => target.focus(), 300);
            }
            return allValid;
        }

        async function submitForm() {
            const norm = document.getElementById('inputNorm').value.trim();
            const nopen = document.getElementById('inputNopen').value.trim();
            let label = (document.getElementById('inputLabel')?.value || '').trim();
            if (label === '') label = '-';
            if (!norm || !nopen) {
                alert('No RM dan No Pendaftaran wajib diisi!');
                return;
            }

            // Validate fields with data-v-* rules (#6)
            if (!validateAllFields()) {
                showToast('Ada field yang belum valid. Periksa highlight merah.', 'error');
                return;
            }

            const btn = document.querySelector('.btn-success');
            const origText = btn.textContent;
            btn.textContent = 'Menyimpan...';
            btn.disabled = true;

            const formData = new FormData(document.getElementById('mainForm'));

            // spec: ezdoc-spec/slots/generate.md#save-hook-pre — dispatch cancelable pre-save event.
            // Consumers may listen on window 'ezcetak:before-save' and call event.preventDefault()
            // to abort the save, or mutate formData via event.detail.formData in place.
            try {
                var _pre = new CustomEvent('ezcetak:before-save', {
                    cancelable: true,
                    detail: { formData: formData, docId: (document.getElementById('docIdInput') || {}).value || '' }
                });
                var _proceed = window.dispatchEvent(_pre);
                if (!_proceed) {
                    btn.textContent = origText;
                    btn.disabled = false;
                    return;
                }
            } catch (e) { /* CustomEvent unsupported — skip hook */ }

            try {
                const resp = await fetch(_ezdocEndpoint('save'), { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.success) {
                    showToast(data.message, 'success');
                    markClean(); // reset dirty state after successful save
                    // spec: ezdoc-spec/slots/generate.md#save-hook-post — dispatch after-save event
                    // for analytics, external sync (audit trail, notification, webhook), or auto-print.
                    try {
                        window.dispatchEvent(new CustomEvent('ezcetak:after-save', {
                            detail: {
                                doc_id:     data.doc_id,
                                verify_url: data.verify_url,
                                isEdit:     !!data.isEdit,
                                message:    data.message
                            }
                        }));
                    } catch (e) { /* skip */ }
                    // Build URL with label if non-default. Preserve routing prefix
                    // (ezdoc_page=generate dll) supaya tidak fallback ke default_page.
                    const savedLabel = data.label || label;
                    const _tParams = _preservedParams();
                    _tParams.set('template_id', templateId);
                    _tParams.set('norm', norm);
                    _tParams.set('nopen', nopen);
                    if (savedLabel && savedLabel !== '-') {
                        _tParams.set('label', savedLabel);
                    }
                    let targetUrl = '?' + _tParams.toString();

                    // Update hidden verify_url supaya QR yang pakai {verify_url} bisa auto-regenerate
                    if (data.verify_url) {
                        const vu = document.getElementById('verifyUrlInput');
                        if (vu) vu.value = data.verify_url;
                        // Trigger regenerate untuk semua QR yang lagi aktif
                        document.querySelectorAll('.ttd-qr-content-input').forEach(inp => {
                            const idMatch = (inp.id || '').match(/^ttd_qr_content_(.+)$/);
                            if (idMatch && typeof generateTtdQr === 'function') generateTtdQr(idMatch[1]);
                        });
                    }

                    if (data.doc_id && !data.isEdit) {
                        // New doc: redirect so page reloads with correct URL (triggers load by id/label)
                        setTimeout(() => { window.location.href = targetUrl; }, 500);
                    } else {
                        // Existing doc: update UI + URL (history.replaceState so reload uses correct label)
                        document.getElementById('docIdInput').value = data.doc_id;
                        document.querySelector('.doc-info').textContent = 'ID: ' + data.doc_id + ' (Edit)';
                        document.querySelector('.doc-info').classList.remove('new');
                        btn.textContent = 'Update';
                        btn.disabled = false;
                        try { history.replaceState(null, '', targetUrl); } catch (e) {}
                    }
                } else {
                    showToast(data.message, 'error');
                    btn.textContent = origText;
                    btn.disabled = false;
                }
            } catch (e) {
                showToast('Gagal menyimpan: ' + e.message, 'error');
                btn.textContent = origText;
                btn.disabled = false;
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // Verify QR Mode — toggle global untuk ganti TTD gambar dengan QR verifikasi
        // ═══════════════════════════════════════════════════════════════════════
        //
        // Approach: save state via submit form, lalu reload halaman dengan flag baru.
        // Server-render langsung apply mode ini. Lebih reliable dari DOM manipulation.
        //
        // Flow:
        //   OFF → ON: modal → OK → save form (persist ke DB) → reload
        //             (kalau doc belum save & butuh NORM/Nopen → warn di modal)
        //   ON → OFF: save form dengan flag=0 → reload
        //
        // State: `_show_verify_qr` (0/1) di data_fields JSON. Support juga GET/POST override.

        function toggleVerifyQrMode() {
            const stateInput = document.getElementById('inputShowVerifyQr');
            if (!stateInput) return;
            const currentOn = stateInput.value === '1';

            if (currentOn) {
                // ON → OFF: langsung apply + save + reload
                stateInput.value = '0';
                saveAndReloadForVerifyQr(false);
                return;
            }

            // OFF → ON: buka modal konfirmasi
            const vu = document.getElementById('verifyUrlInput')?.value || '';
            const urlDisplay = document.getElementById('verifyQrModalUrl');
            const warnBox = document.getElementById('verifyQrModalWarn');
            const confirmBtn = document.getElementById('btnVerifyQrConfirm');
            const mainBox = document.getElementById('verifyQrModalMain');
            const savingBox = document.getElementById('verifyQrModalSaving');

            if (mainBox) mainBox.style.display = '';
            if (savingBox) savingBox.style.display = 'none';

            if (vu) {
                urlDisplay.value = vu;
                warnBox.style.display = 'none';
                confirmBtn.textContent = 'OK, Aktifkan';
                confirmBtn.disabled = false;
                confirmBtn.dataset.needSave = '0';
            } else {
                urlDisplay.value = '(akan digenerate setelah dokumen di-save)';
                warnBox.style.display = '';
                confirmBtn.textContent = 'Simpan & Aktifkan';
                confirmBtn.dataset.needSave = '1';
                const norm = (document.getElementById('inputNorm')?.value || '').trim();
                const nopen = (document.getElementById('inputNopen')?.value || '').trim();
                const isGeneral = <?= (isset($isGeneralDoc) && $isGeneralDoc) ? 'true' : 'false' ?>;
                if (!isGeneral && (!norm || !nopen)) {
                    document.getElementById('verifyQrModalWarnMsg').textContent =
                        'NORM & Nopen wajib diisi dulu. Tutup modal ini → isi di sidebar kanan → klik toggle lagi.';
                    confirmBtn.disabled = true;
                } else {
                    confirmBtn.disabled = false;
                }
            }

            const modal = document.getElementById('verifyQrConfirmModal');
            // Tailwind `hidden` !important — must remove class BEFORE inline display works.
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeVerifyQrModal() {
            const modal = document.getElementById('verifyQrConfirmModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
        }

        async function confirmVerifyQrMode() {
            document.getElementById('inputShowVerifyQr').value = '1';
            const mainBox = document.getElementById('verifyQrModalMain');
            const savingBox = document.getElementById('verifyQrModalSaving');
            if (mainBox) mainBox.style.display = 'none';
            if (savingBox) savingBox.style.display = '';
            const confirmBtn = document.getElementById('btnVerifyQrConfirm');
            if (confirmBtn) confirmBtn.disabled = true;
            await saveAndReloadForVerifyQr(true);
        }

        // Generate QR verifikasi untuk TTD tertentu.
        // Server render area .ttd-area-verify-qr dengan preview "Loading QR..." — fungsi ini
        // replace dengan img QR yang di-generate dari verifyUrl (sama untuk semua TTD).
        function generateVerifyQr(ttdId) {
            const preview = document.getElementById('ttd_verify_qr_preview_' + ttdId);
            if (!preview) return;
            const verifyUrl = document.getElementById('verifyUrlInput')?.value || '';
            if (!verifyUrl) {
                preview.innerHTML = '<small class="text-amber-500 text-[10px]">Simpan dokumen dulu</small>';
                return;
            }
            preview.innerHTML = '<small class="text-gray-400 text-[10px]">Loading QR...</small>';
            fetch(_ezdocQrUrl(verifyUrl))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        preview.innerHTML = `<img src="${data.qr}" class="w-[100px] h-[100px] max-w-[none] max-h-[none] object-contain block my-0 mx-auto" alt="QR Verifikasi" title="${verifyUrl.replace(/"/g, '&quot;')}">`;
                    } else {
                        preview.innerHTML = `<small class="text-red-700 text-[10px]">QR gagal: ${data.message || 'error'}</small>`;
                    }
                })
                .catch(err => {
                    preview.innerHTML = `<small class="text-red-700 text-[10px]">QR gagal: ${err.message}</small>`;
                });
        }

        // Auto-generate verify QR saat page load kalau mode ON.
        // Server-render sudah include "Loading QR..." placeholder di area yang visible.
        // Wrap di DOMContentLoaded biar dijalankan setelah DOM siap.
        function initVerifyQrOnLoad() {
            const stateInput = document.getElementById('inputShowVerifyQr');
            if (!stateInput || stateInput.value !== '1') return;
            document.querySelectorAll('[id^="ttd_area_verify_qr_"]').forEach(el => {
                if (el.style.display !== 'none') {
                    const ttdId = el.id.replace('ttd_area_verify_qr_', '');
                    generateVerifyQr(ttdId);
                }
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initVerifyQrOnLoad);
        } else {
            initVerifyQrOnLoad();
        }

        // Save form via fetch (persist state), lalu reload URL dengan `_show_verify_qr=X`.
        // Server-render akan handle semua visual change (TTD → verify QR atau balik).
        async function saveAndReloadForVerifyQr(targetOn) {
            const norm = (document.getElementById('inputNorm')?.value || '').trim();
            const nopen = (document.getElementById('inputNopen')?.value || '').trim();
            const isGeneral = <?= (isset($isGeneralDoc) && $isGeneralDoc) ? 'true' : 'false' ?>;
            if (!isGeneral && (!norm || !nopen)) {
                closeVerifyQrModal();
                showToast('NORM & Nopen wajib diisi dulu untuk save', 'error');
                return;
            }
            try {
                const formData = new FormData(document.getElementById('mainForm'));
                const resp = await fetch(_ezdocEndpoint('save'), { method: 'POST', body: formData });
                const data = await resp.json();
                if (!data.success) {
                    closeVerifyQrModal();
                    showToast('Gagal simpan: ' + (data.message || 'error'), 'error');
                    return;
                }
                // Build URL untuk reload — pastikan doc_id + _show_verify_qr masuk
                const url = new URL(window.location.href);
                url.searchParams.set('_show_verify_qr', targetOn ? '1' : '0');
                if (data.doc_id) {
                    // Pastikan URL punya template_id, norm, nopen, label seperti flow submitForm biasa
                    url.searchParams.set('template_id', String(templateId));
                    if (!isGeneral) {
                        url.searchParams.set('norm', norm);
                        url.searchParams.set('nopen', nopen);
                    }
                    const label = data.label || (document.getElementById('inputLabel')?.value || '').trim();
                    if (label && label !== '-') url.searchParams.set('label', label);
                }
                window.location.href = url.toString();
            } catch (e) {
                closeVerifyQrModal();
                showToast('Gagal simpan: ' + e.message, 'error');
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // QR Field Modal — untuk standalone QR (klik area QR → modal → OK → isi)
        // ═══════════════════════════════════════════════════════════════════════

        let _qrFieldModalCurrentField = null;

        function openQrFieldModal(fieldName) {
            _qrFieldModalCurrentField = fieldName;
            const modal = document.getElementById('qrFieldModal');
            const nameEl = document.getElementById('qrFieldModalName');
            const verifyBox = document.getElementById('qrFieldVerifyBox');
            const verifyWarn = document.getElementById('qrFieldVerifyWarn');
            const customInput = document.getElementById('qrFieldCustomInput');
            const radioVerify = document.getElementById('qrFieldSourceVerify');
            const radioCustom = document.getElementById('qrFieldSourceCustom');
            const confirmBtn = document.getElementById('btnQrFieldConfirm');
            if (!modal) return;

            if (nameEl) nameEl.textContent = fieldName;

            // Isi current value (kalau sudah ada) — untuk custom input
            const currentVal = (document.getElementById('qrinput_' + fieldName)?.value || '').trim();
            if (customInput) customInput.value = currentVal;

            // Verify URL preview
            const vu = document.getElementById('verifyUrlInput')?.value || '';
            if (vu) {
                if (verifyBox) verifyBox.textContent = vu;
                if (verifyWarn) verifyWarn.style.display = 'none';
                if (radioVerify) radioVerify.disabled = false;
                if (confirmBtn) confirmBtn.disabled = false;
            } else {
                if (verifyBox) verifyBox.textContent = '(dokumen belum di-save)';
                if (verifyWarn) verifyWarn.style.display = '';
                if (radioVerify) radioVerify.disabled = true;
                // Force pilih custom mode kalau verify URL belum ada
                if (radioCustom) radioCustom.checked = true;
                if (confirmBtn) confirmBtn.disabled = false;
            }

            // Kalau current val cocok dengan verify_url, radio verify checked. Else custom.
            if (vu && currentVal === vu) {
                if (radioVerify) radioVerify.checked = true;
            } else if (currentVal !== '') {
                if (radioCustom) radioCustom.checked = true;
            } else {
                // Default: verify kalau ada, custom kalau tidak
                if (vu && radioVerify) radioVerify.checked = true;
                else if (radioCustom) radioCustom.checked = true;
            }

            // Tailwind `hidden` utility uses !important, jadi harus di-remove
            // via classList (inline style.display='flex' tidak menang lawan !important).
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeQrFieldModal() {
            const modal = document.getElementById('qrFieldModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
            _qrFieldModalCurrentField = null;
        }

        function confirmQrField() {
            const fieldName = _qrFieldModalCurrentField;
            if (!fieldName) { closeQrFieldModal(); return; }
            const radioVerify = document.getElementById('qrFieldSourceVerify');
            const customInput = document.getElementById('qrFieldCustomInput');
            let newValue = '';
            if (radioVerify && radioVerify.checked) {
                // Simpan MARKER `{verify_url}` supaya kalau base URL berubah (mis. dev → production),
                // server-side render auto-resolve ke URL current. Jangan simpan URL resolved
                // (yang bisa jadi stale kalau config berubah).
                if (!(document.getElementById('verifyUrlInput')?.value || '')) {
                    showToast('URL Verifikasi belum tersedia. Simpan dokumen dulu.', 'error');
                    return;
                }
                newValue = '{verify_url}';
            } else {
                newValue = (customInput?.value || '').trim();
                if (!newValue) {
                    showToast('Isi QR tidak boleh kosong', 'error');
                    return;
                }
            }
            const input = document.getElementById('qrinput_' + fieldName);
            if (input) {
                input.value = newValue;
                if (typeof markDirty === 'function') markDirty();
                if (typeof updateQrPreview === 'function') updateQrPreview(fieldName);
            }
            closeQrFieldModal();
            showToast('QR terisi. Klik Simpan untuk persist.', 'success');
        }

        function showToast(message, type) {
            // Remove existing toast
            document.querySelectorAll('.toast').forEach(t => t.remove());

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // Build URLSearchParams yang preserve routing prefix (mis. ezdoc_page=generate).
        // Kalau library dipakai standalone (tanpa App orchestrator), current URL tidak
        // punya ezdoc_page → params tetap kosong = backward compat.
        function _preservedParams() {
            const cur = new URLSearchParams(window.location.search);
            const p = new URLSearchParams();
            // Preserve routing prefix keys — jangan bawa param dokumen supaya tidak
            // stale saat pindah context.
            ['ezdoc_page', 'ezdoc_asset'].forEach(k => {
                if (cur.has(k)) p.set(k, cur.get(k));
            });
            return p;
        }

        function createNew() {
            const params = _preservedParams();
            params.set('template_id', templateId);
            window.location.href = '?' + params.toString();
        }

        // Open PDF view in new tab (superadmin only)
        function viewPdfRaw() {
            const params = _preservedParams();
            params.set('template_id', templateId);
            if (CURRENT_DOC_ID) params.set('doc_id', CURRENT_DOC_ID);
            else {
                if (CURRENT_NORM) params.set('norm', CURRENT_NORM);
                if (CURRENT_NOPEN) params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL && CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                if (CURRENT_VERSION) params.set('version', CURRENT_VERSION);
            }
            params.set('view', 'pdf');
            window.open('?' + params.toString(), '_blank');
        }

        // Show document info modal
        function showDocInfo() {
            const meta = <?= json_encode($docMeta, JSON_UNESCAPED_UNICODE) ?>;
            if (!meta.id) return;

            const fmtDate = (s) => {
                if (!s) return '-';
                const d = new Date(s.replace(' ', 'T'));
                if (isNaN(d)) return s;
                const pad = (n) => String(n).padStart(2, '0');
                return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
            };

            const createdBy = meta.created_by_name
                ? `${meta.created_by_name} (ID: ${meta.created_by})`
                : (meta.created_by ? `ID: ${meta.created_by}` : '-');

            // Build materai info rows from current rendered placeholders + hidden inputs
            let materaiRows = '';
            const materaiWraps = document.querySelectorAll('.materai-wrap');
            if (materaiWraps.length > 0) {
                materaiRows = '<tr><td colspan="2" class="py-2.5 px-0"><hr class="m-0 border-0" style="border-top:1px solid #e5e7eb;"></td></tr>' +
                    '<tr><td colspan="2" class="py-1.5 px-0 text-orange-700 font-bold"><i class="bi bi-stamp"></i> Materai</td></tr>';
                materaiWraps.forEach(wrap => {
                    const mid = wrap.getAttribute('data-materai-id') || '';
                    const mode = wrap.getAttribute('data-materai-mode') || 'upload';
                    const lblEl = wrap.querySelector('.materai-label');
                    const lbl = lblEl ? lblEl.textContent.trim() : mid;
                    const imgVal = (document.getElementById('materai_img_' + mid) || {}).value || '';
                    const serialVal = (document.getElementById('materai_serial_' + mid) || {}).value || '';
                    const uploadAt = (document.getElementById('materai_upload_' + mid) || {}).value || '';
                    let statusBadge;
                    if (mode === 'kosong') {
                        statusBadge = '<span class="text-gray-500 text-[11px]">Tempel manual</span>';
                    } else {
                        statusBadge = imgVal
                            ? '<span class="text-green-600 text-[11px]">✓ Terisi</span>'
                            : '<span class="text-red-600 text-[11px]">⚠ Belum upload</span>';
                    }
                    materaiRows += `<tr>
                        <td class="py-1 px-0 text-gray-500 text-xs">${escapeHtmlSimple(lbl)}</td>
                        <td class="py-1 px-0 text-xs">
                            ${statusBadge}
                            ${serialVal ? `<div class="text-[10px] text-gray-500">No. Seri: ${escapeHtmlSimple(serialVal)}</div>` : ''}
                            ${uploadAt ? `<div class="text-[10px] text-gray-400">Upload: ${fmtDate(uploadAt)}</div>` : ''}
                        </td>
                    </tr>`;
                });
            }

            let modal = document.getElementById('docInfoModal');
            if (modal) modal.remove();
            modal = document.createElement('div');
            modal.id = 'docInfoModal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:4000;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
            modal.innerHTML = `
                <div class="bg-white rounded-[10px] w-[90%] max-w-[420px] p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h5 class="m-0"><i class="bi bi-info-circle"></i> Detail Dokumen</h5>
                        <button onclick="document.getElementById('docInfoModal').remove()" class="border-0 bg-transparent text-xl cursor-pointer text-gray-500">&times;</button>
                    </div>
                    <table class="w-full text-[13px]" style="border-collapse:collapse;">
                        <tr><td class="py-1.5 px-0 text-gray-500 w-[40%]">Document ID</td><td class="py-1.5 px-0"><code>${meta.id}</code></td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">Versi</td><td class="py-1.5 px-0">v${CURRENT_VERSION} ${<?= $param_is_locked ? 'true' : 'false' ?> ? '🔒 Locked' : '(Editable)'}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">No RM</td><td class="py-1.5 px-0">${escapeHtmlSimple(CURRENT_NORM || '-')}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">No Pendaftaran</td><td class="py-1.5 px-0">${escapeHtmlSimple(CURRENT_NOPEN || '-')}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">Label</td><td class="py-1.5 px-0">${escapeHtmlSimple(CURRENT_LABEL || '-')}</td></tr>
                        <tr><td colspan="2" class="py-2.5 px-0"><hr class="m-0 border-0" style="border-top:1px solid #e5e7eb;"></td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">Dibuat oleh</td><td class="py-1.5 px-0">${escapeHtmlSimple(createdBy)}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">Dibuat pada</td><td class="py-1.5 px-0">${fmtDate(meta.created_at)}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">Terakhir diupdate</td><td class="py-1.5 px-0">${fmtDate(meta.updated_at)}</td></tr>
                        ${materaiRows}
                    </table>
                    <div class="text-right mt-4">
                        <button onclick="document.getElementById('docInfoModal').remove()" class="py-1.5 px-4 border-0 bg-blue-500 text-white rounded-md cursor-pointer">Tutup</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) modal.remove();
            });
        }

        function escapeHtmlSimple(s) {
            return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        // ===== Document versioning =====
        const CURRENT_DOC_ID = <?= (int)$doc_id ?>;
        const CURRENT_VERSION = <?= (int)$param_version ?>;
        const CURRENT_NORM = <?= json_encode($param_norm) ?>;
        const CURRENT_NOPEN = <?= json_encode($param_nopen) ?>;
        const CURRENT_LABEL = <?= json_encode($param_label) ?>;

        // Load all versions for the current slot & populate dropdown
        async function refreshVersionList() {
            if (!CURRENT_NORM || !CURRENT_NOPEN) return;
            const fd = new FormData();
            fd.append('_doc_action', 'list_versions');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            const sel = document.getElementById('versionSelect');
            if (!sel || !data.success) return;
            sel.innerHTML = '';
            data.versions.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.version;
                const lockIcon = v.is_locked ? ' 🔒' : '';
                const currMark = v.version === CURRENT_VERSION ? ' (current)' : '';
                opt.textContent = `v${v.version}${lockIcon}${currMark}`;
                if (v.version === CURRENT_VERSION) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        function switchVersion(version) {
            if (parseInt(version) === CURRENT_VERSION) return;
            const params = new URLSearchParams();
            params.set('template_id', templateId);
            params.set('norm', CURRENT_NORM);
            params.set('nopen', CURRENT_NOPEN);
            if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
            params.set('version', version);
            window.location.href = '?' + params.toString();
        }

        async function toggleDocLock() {
            if (!CURRENT_DOC_ID) return;
            const currentLocked = <?= (int)$param_is_locked ?>;
            const newLocked = currentLocked ? 0 : 1;

            // Pre-lock check: warn if any 'upload' mode materai is empty
            if (newLocked) {
                const missingLabels = [];
                document.querySelectorAll('.materai-wrap[data-materai-mode="upload"]').forEach(wrap => {
                    const mid = wrap.getAttribute('data-materai-id') || '';
                    const imgInput = document.getElementById('materai_img_' + mid);
                    if (!imgInput || !imgInput.value || imgInput.value.length < 30) {
                        const lblEl = wrap.querySelector('.materai-label');
                        missingLabels.push(lblEl ? lblEl.textContent.trim() : mid);
                    }
                });
                if (missingLabels.length > 0) {
                    const warn = 'Materai berikut belum di-upload:\n  - ' + missingLabels.join('\n  - ') + '\n\nTetap lock versi ini?';
                    if (!confirm(warn)) return;
                }
            }

            const msg = newLocked
                ? 'Lock versi ini sebagai FINAL?\n\nSetelah locked, versi ini tidak bisa diedit. Hanya superadmin yang bisa unlock. Untuk revisi, buat versi baru.'
                : 'Unlock versi ini? (akses superadmin)';
            if (!confirm(msg)) return;
            const fd = new FormData();
            fd.append('_doc_action', 'toggle_doc_lock');
            fd.append('doc_id', CURRENT_DOC_ID);
            fd.append('locked', newLocked);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                location.reload();
            } else {
                alert('Gagal: ' + (data.message || 'error'));
            }
        }

        async function deleteThisVersion() {
            if (!CURRENT_DOC_ID) return;
            if (!confirm(`Hapus versi v${CURRENT_VERSION}? Aksi ini tidak bisa di-undo.`)) return;
            const fd = new FormData();
            fd.append('_doc_action', 'delete_version');
            fd.append('doc_id', CURRENT_DOC_ID);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                // Reload latest version after delete
                const params = new URLSearchParams();
                params.set('template_id', templateId);
                params.set('norm', CURRENT_NORM);
                params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                window.location.href = '?' + params.toString();
            } else {
                alert('Gagal: ' + (data.message || 'error'));
            }
        }

        // Restore soft-deleted slot from preview mode (superadmin only)
        async function restoreDeletedSlot() {
            if (!confirm('Pulihkan seluruh slot dokumen ini? Semua versi yang ter-soft-delete akan kembali aktif.')) return;
            const fd = new FormData();
            fd.append('_doc_action', 'restore_slot');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                alert('Berhasil di-restore (' + (data.affected || 0) + ' versi)');
                // Reload sebagai dokumen aktif (tanpa preview_deleted)
                const params = new URLSearchParams();
                params.set('template_id', templateId);
                params.set('norm', CURRENT_NORM);
                params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                window.location.href = '?' + params.toString();
            } else {
                alert('Gagal: ' + (data.message || 'error'));
            }
        }

        // Modal: buat versi baru (prompt copy-from-version / blank)
        async function showNewVersionModal() {
            // Load all versions for picker
            const fd = new FormData();
            fd.append('_doc_action', 'list_versions');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            const versions = data.success ? data.versions : [];

            // Build modal dynamically
            let modal = document.getElementById('newVersionModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'newVersionModal';
                modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:4000;display:flex;align-items:center;justify-content:center;';
                document.body.appendChild(modal);
            }
            const optsHtml = versions.map(v =>
                `<option value="${v.version}">v${v.version}${v.is_locked ? ' 🔒' : ''}</option>`
            ).join('');

            modal.innerHTML = `
                <div class="bg-white rounded-[10px] w-[90%] max-w-[400px] p-5" style="font-family:sans-serif;">
                    <h5 class="mt-0 mr-0 mb-3 ml-0">Buat Versi Baru</h5>
                    <p class="text-gray-500 text-[13px] mb-3">Pilih sumber data untuk versi baru:</p>

                    <label class="block mb-2 cursor-pointer">
                        <input type="radio" name="newVerSource" value="blank" checked>
                        Kosong (start dari awal)
                    </label>
                    <label class="block mb-2 cursor-pointer">
                        <input type="radio" name="newVerSource" value="copy">
                        Copy dari versi:
                        <select id="copyVerSelect" class="ml-1.5 py-0.5 px-1.5">
                            ${optsHtml}
                        </select>
                    </label>

                    <div class="flex gap-2 mt-4 justify-end">
                        <button onclick="document.getElementById('newVersionModal').remove()" class="py-1.5 px-4 border border-gray-300 bg-white rounded-md cursor-pointer">Batal</button>
                        <button onclick="doCreateNewVersion()" class="py-1.5 px-4 border-0 bg-violet-500 text-white rounded-md cursor-pointer">Buat Versi Baru</button>
                    </div>
                </div>
            `;
        }

        async function doCreateNewVersion() {
            const source = document.querySelector('input[name="newVerSource"]:checked')?.value || 'blank';
            const sourceVersion = source === 'copy' ? parseInt(document.getElementById('copyVerSelect').value || '0') : 0;

            const fd = new FormData();
            fd.append('_doc_action', 'new_version');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            fd.append('source_version', sourceVersion);

            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('newVersionModal')?.remove();
                // Redirect to new version
                const params = new URLSearchParams();
                params.set('template_id', templateId);
                params.set('norm', CURRENT_NORM);
                params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                params.set('version', data.version);
                window.location.href = '?' + params.toString();
            } else {
                alert('Gagal: ' + (data.message || 'error'));
            }
        }

        // Auto-populate version dropdown on load
        if (CURRENT_DOC_ID) {
            refreshVersionList();
        }

        // Toolbar show/hide
        function toggleToolbar() {
            const tb = document.getElementById('toolbarPanel');
            const btn = document.getElementById('toolbarToggleBtn');
            const isCollapsed = tb.classList.toggle('collapsed');
            btn.innerHTML = isCollapsed ? '&#9776;' : '&minus; Sembunyikan';
            btn.title = isCollapsed ? 'Tampilkan Toolbar' : 'Sembunyikan Toolbar';
            try { localStorage.setItem('surat_toolbar_collapsed', isCollapsed ? '1' : '0'); } catch (e) {}
        }

        // Apply saved toolbar state on load (default: collapsed on narrow screens)
        (function() {
            let saved = null;
            try { saved = localStorage.getItem('surat_toolbar_collapsed'); } catch (e) {}
            const shouldCollapse = saved === null ? (window.innerWidth < 1024) : saved === '1';
            const tb = document.getElementById('toolbarPanel');
            const btn = document.getElementById('toolbarToggleBtn');
            if (shouldCollapse) {
                if (tb) tb.classList.add('collapsed');
                if (btn) { btn.innerHTML = '&#9776;'; btn.title = 'Tampilkan Toolbar'; }
            } else {
                if (btn) { btn.innerHTML = '&minus; Sembunyikan'; btn.title = 'Sembunyikan Toolbar'; }
            }
        })();

        function openSign(id, label) {
            if (!editMode) {
                alert('Dokumen ini locked. Tidak bisa tanda tangan. Untuk revisi, buat versi baru.');
                return;
            }
            currentTtdId = id;
            document.getElementById('signTitle').textContent = label || 'Tanda Tangan';
            modal.classList.add('show');
            clearSign();
            const existing = document.getElementById('ttd_' + id)?.value;
            if (existing) {
                const img = new Image();
                img.onload = () => {
                    // Scale and center existing signature on larger canvas
                    const scale = Math.min(canvas.width / img.width, canvas.height / img.height, 2);
                    const w = img.width * scale;
                    const h = img.height * scale;
                    const x = (canvas.width - w) / 2;
                    const y = (canvas.height - h) / 2;
                    ctx.drawImage(img, x, y, w, h);
                };
                img.src = existing;
            }
        }

        function closeSign() { modal.classList.remove('show'); }
        function clearSign() { ctx.clearRect(0, 0, canvas.width, canvas.height); }

        // Generate action buttons HTML
        function getActionsHtml(ttdId, label) {
            return `<div class="ttd-actions">
                <button type="button" class="btn-edit" onclick="openSign('${ttdId}', '${label}')">&#9998; Edit</button>
                <button type="button" class="btn-delete" onclick="clearTtd('${ttdId}')">&#10005; Hapus</button>
            </div>`;
        }

        function saveSign() {
            if (currentTtdId) {
                const data = canvas.toDataURL('image/png');
                const hiddenInput = document.getElementById('ttd_' + currentTtdId);
                hiddenInput.value = data;

                const preview = document.getElementById('preview_' + currentTtdId);
                if (preview) {
                    const labelEl = preview.parentElement.querySelector('.ttd-label');
                    const label = labelEl ? labelEl.textContent : 'Tanda Tangan';
                    // Check if using new area structure
                    const imgArea = preview.querySelector('.ttd-area-image');
                    const target = imgArea || preview;
                    target.innerHTML = `<img src="${data}" class="ttd-signature" alt="TTD">${getActionsHtml(currentTtdId, label)}`;
                }
                if (typeof triggerDirty === 'function') triggerDirty();
            }
            closeSign();
        }

        // Clear/delete TTD signature
        // ===== Materai handlers =====
        function handleMateraiUpload(input, materaiId) {
            if (!editMode) { alert('Dokumen locked, tidak bisa upload.'); input.value = ''; return; }
            const file = input.files && input.files[0];
            if (!file) return;

            // Validate type & size (max 2MB)
            if (!/^image\/(png|jpe?g|gif)$/i.test(file.type)) {
                alert('Format harus PNG / JPG.'); input.value = ''; return;
            }
            if (file.size > 2 * 1024 * 1024) {
                alert('Ukuran file maksimal 2MB.'); input.value = ''; return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const dataUrl = e.target.result;
                if (!/^data:image\/(png|jpe?g|gif);base64,/.test(dataUrl)) {
                    alert('File tidak valid.'); return;
                }
                const hiddenImg = document.getElementById('materai_img_' + materaiId);
                if (hiddenImg) hiddenImg.value = dataUrl;
                const hiddenUpload = document.getElementById('materai_upload_' + materaiId);
                if (hiddenUpload) {
                    const now = new Date();
                    const pad = n => String(n).padStart(2, '0');
                    hiddenUpload.value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
                }
                // Update preview
                const preview = document.getElementById('materai_preview_' + materaiId);
                if (preview) {
                    preview.innerHTML = `<img src="${dataUrl}" class="materai-image" alt="Materai">
                        <div class="materai-actions">
                            <button type="button" class="btn-edit" onclick="document.getElementById('materai_file_${materaiId}').click()">&#9998; Ganti</button>
                            <button type="button" class="btn-delete" onclick="clearMaterai('${materaiId}')">&#10005; Hapus</button>
                        </div>`;
                }
                if (typeof triggerDirty === 'function') triggerDirty();
            };
            reader.readAsDataURL(file);
            input.value = ''; // allow same-file re-upload later
        }

        function clearMaterai(materaiId) {
            if (!editMode) return;
            if (!confirm('Hapus e-Materai ini?')) return;
            const hiddenImg = document.getElementById('materai_img_' + materaiId);
            if (hiddenImg) hiddenImg.value = '';
            const hiddenUpload = document.getElementById('materai_upload_' + materaiId);
            if (hiddenUpload) hiddenUpload.value = '';
            if (typeof triggerDirty === 'function') triggerDirty();
            const preview = document.getElementById('materai_preview_' + materaiId);
            if (preview) {
                preview.innerHTML = `<div class="materai-upload-box" onclick="document.getElementById('materai_file_${materaiId}').click()">
                    <strong>UPLOAD<br>e-MATERAI</strong>
                </div>`;
            }
        }

        function clearTtd(ttdId) {
            if (!confirm('Hapus tanda tangan ini?')) return;

            const hiddenInput = document.getElementById('ttd_' + ttdId);
            if (hiddenInput) hiddenInput.value = '';

            const preview = document.getElementById('preview_' + ttdId);
            if (preview) {
                const imgArea = preview.querySelector('.ttd-area-image');
                if (imgArea) {
                    const labelEl = preview.parentElement.querySelector('.ttd-label');
                    const label = labelEl ? labelEl.textContent : 'Tanda Tangan';
                    imgArea.innerHTML = `<div class="ttd-canvas-placeholder" onclick="openSign('${ttdId}', '${label}')"></div>`;
                } else {
                    const labelEl = preview.parentElement.querySelector('.ttd-label');
                    const label = labelEl ? labelEl.textContent : 'Tanda Tangan';
                    preview.innerHTML = `<div class="ttd-canvas-placeholder" onclick="openSign('${ttdId}', '${label}')"></div>`;
                }
            }
            if (typeof triggerDirty === 'function') triggerDirty();
        }

        // Switch TTD mode between image and QR
        function switchTtdMode(ttdId, mode) {
            const modeInput = document.getElementById('ttd_mode_' + ttdId);
            if (modeInput) modeInput.value = mode;
            if (typeof triggerDirty === 'function') triggerDirty();

            const imgArea = document.getElementById('ttd_area_image_' + ttdId);
            const qrArea = document.getElementById('ttd_area_qr_' + ttdId);

            if (imgArea) imgArea.style.display = mode === 'image' ? '' : 'none';
            if (qrArea) {
                qrArea.style.display = mode === 'qr' ? '' : 'none';
                if (mode === 'qr') generateTtdQr(ttdId);
            }

            // Update toggle buttons
            const container = imgArea?.closest('.ttd-item-inline, .ttd-item-floating');
            if (container) {
                container.querySelectorAll('.ttd-mode-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.textContent.trim() === (mode === 'image' ? 'Gambar' : 'QR'));
                });
            }
        }

        // Generate QR code for TTD
        function generateTtdQr(ttdId) {
            const preview = document.getElementById('ttd_qr_preview_' + ttdId);
            if (!preview) return;

            // Priority 1: user-typed per-document content
            let qrData = (document.getElementById('ttd_qr_content_' + ttdId)?.value || '').trim();

            // Priority 2: resolve template pattern with current form field values
            if (!qrData) {
                const qrDataTpl = document.getElementById('ttd_qr_data_' + ttdId)?.value || '';
                if (!qrDataTpl) {
                    preview.innerHTML = '<small class="text-gray-400 text-[10px]">Isi konten QR atau atur pattern di template</small>';
                    return;
                }
                qrData = qrDataTpl.replace(/\{([^}]+)\}/g, (_, fieldName) => {
                    const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
                    if (input) return input.value || '';
                    const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                    if (editable) return editable.textContent || '';
                    return '';
                }).trim();
            }

            if (!qrData) {
                preview.innerHTML = '<small class="text-gray-400 text-[10px]">Isi field terlebih dulu</small>';
                return;
            }

            preview.innerHTML = '<small class="text-gray-400 text-[10px]">Generating...</small>';

            fetch(_ezdocQrUrl(qrData))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        preview.innerHTML = `<img src="${data.qr}" class="w-[100px] h-[100px] max-w-[none] max-h-[none] object-contain block" alt="QR TTD" title="${qrData.replace(/"/g, '&quot;')}">`;
                    } else {
                        preview.innerHTML = `<small class="text-red-700 text-[10px]">QR gagal: ${data.message || 'error'}</small>`;
                    }
                })
                .catch(err => {
                    preview.innerHTML = `<small class="text-red-700 text-[10px]">QR gagal: ${err.message}</small>`;
                });
        }

        // Buka prompt untuk edit isi QR (input-nya disembunyikan supaya tidak keliatan mengganggu)
        function editQrContent(ttdId, resolvedPattern) {
            const input = document.getElementById('ttd_qr_content_' + ttdId);
            if (!input) return;
            const current = input.value || '';
            const patternHint = resolvedPattern ? '\n\nTemplate default: ' + resolvedPattern + '\n(Kosongkan untuk pakai template default)' : '';
            const newVal = prompt('Isi konten QR untuk TTD ini:' + patternHint, current);
            if (newVal === null) return; // user batal
            input.value = newVal.trim();
            markDirty && markDirty();
            generateTtdQr(ttdId);
        }

        // Auto-generate QR for TTD on page load + bind field changes
        (function initTtdQr() {
            const areas = document.querySelectorAll('[id^="ttd_area_qr_"]');
            if (!areas.length) return;

            // Generate QR for all visible QR areas
            areas.forEach(el => {
                if (el.style.display !== 'none') {
                    const ttdId = el.id.replace('ttd_area_qr_', '');
                    generateTtdQr(ttdId);
                }
            });

            // Collect all field names referenced across all QR templates
            const referencedFields = new Set();
            const ttdToFields = {};
            document.querySelectorAll('[id^="ttd_qr_data_"]').forEach(el => {
                const ttdId = el.id.replace('ttd_qr_data_', '');
                const tpl = el.value || '';
                const fields = [...tpl.matchAll(/\{([^}]+)\}/g)].map(m => m[1]);
                ttdToFields[ttdId] = fields;
                fields.forEach(f => referencedFields.add(f));
            });

            // Debounce regeneration
            let regenTimer = null;
            const scheduleRegen = () => {
                clearTimeout(regenTimer);
                regenTimer = setTimeout(() => {
                    Object.keys(ttdToFields).forEach(ttdId => {
                        const area = document.getElementById('ttd_area_qr_' + ttdId);
                        if (area && area.style.display !== 'none') generateTtdQr(ttdId);
                    });
                }, 400);
            };

            // Bind change events to all referenced fields
            referencedFields.forEach(fieldName => {
                const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
                if (input) {
                    input.addEventListener('input', scheduleRegen);
                    input.addEventListener('change', scheduleRegen);
                }
                const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                if (editable) editable.addEventListener('input', scheduleRegen);
            });
        })();

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
            const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
            return [x * (canvas.width / rect.width), y * (canvas.height / rect.height)];
        }

        canvas.addEventListener('mousedown', e => { drawing = true; ctx.beginPath(); ctx.moveTo(...getPos(e)); });
        canvas.addEventListener('mousemove', e => { if (drawing) { ctx.lineTo(...getPos(e)); ctx.stroke(); } });
        canvas.addEventListener('mouseup', () => drawing = false);
        canvas.addEventListener('mouseleave', () => drawing = false);
        canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; ctx.beginPath(); ctx.moveTo(...getPos(e)); });
        canvas.addEventListener('touchmove', e => { e.preventDefault(); if (drawing) { ctx.lineTo(...getPos(e)); ctx.stroke(); } });
        canvas.addEventListener('touchend', () => drawing = false);

        // ===== Keyboard shortcuts (#21) =====
        // Admin capability flag — resolved server-side via RoleProvider (see $adminRoleSlug config).
        // spec: ezdoc-spec/context/role_provider.md#capabilities
        const IS_ADMIN_JS      = <?= $isSuperadmin ? 'true' : 'false' ?>;
        // Legacy alias — kept for backward compat with existing JS references (do not use in new code)
        const IS_SUPERADMIN_JS = IS_ADMIN_JS;

        function showShortcutsHelp() {
            // Toggle if already open
            const existing = document.getElementById('shortcutsHelpModal');
            if (existing) { existing.remove(); return; }
            const modal = document.createElement('div');
            modal.id = 'shortcutsHelpModal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:5000;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
            modal.innerHTML = `
                <div class="bg-white rounded-[10px] w-[90%] max-w-[480px] p-5">
                    <div class="flex justify-between items-center mb-3.5">
                        <h5 class="m-0"><i class="bi bi-keyboard"></i> Keyboard Shortcuts</h5>
                        <button onclick="document.getElementById('shortcutsHelpModal').remove()" class="border-0 bg-transparent text-xl cursor-pointer text-gray-500">&times;</button>
                    </div>
                    <table class="w-full text-[13px]" style="border-collapse:collapse;">
                        <tr><td class="py-[5px] px-0 text-gray-500 w-[40%]"><kbd>Ctrl</kbd> + <kbd>S</kbd></td><td class="py-[5px] px-0">Simpan dokumen</td></tr>
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>P</kbd></td><td class="py-[5px] px-0">Print (browser)</td></tr>
                        ${IS_SUPERADMIN_JS ? '<tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>P</kbd></td><td class="py-[5px] px-0">Lihat PDF (admin)</td></tr>' : ''}
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>L</kbd></td><td class="py-[5px] px-0">Fokus ke input Label</td></tr>
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Esc</kbd></td><td class="py-[5px] px-0">Tutup modal aktif / canvas TTD</td></tr>
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>/</kbd></td><td class="py-[5px] px-0">Tampilkan help ini</td></tr>
                    </table>
                    <div class="text-right mt-4">
                        <button onclick="document.getElementById('shortcutsHelpModal').remove()" class="py-1.5 px-4 border-0 bg-blue-500 text-white rounded-md cursor-pointer">Tutup</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
        }

        document.addEventListener('keydown', function(e) {
            const mod = e.ctrlKey || e.metaKey;

            // Ctrl+S — Save
            if (mod && !e.shiftKey && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                if (typeof submitForm === 'function') submitForm();
                return;
            }

            // Ctrl+Shift+P — View PDF (superadmin only)
            if (mod && e.shiftKey && (e.key === 'p' || e.key === 'P')) {
                if (IS_SUPERADMIN_JS && typeof viewPdfRaw === 'function') {
                    e.preventDefault();
                    viewPdfRaw();
                }
                return;
            }

            // Ctrl+P — Print (let default also work, but ensure)
            if (mod && !e.shiftKey && (e.key === 'p' || e.key === 'P')) {
                // Don't preventDefault — let browser print dialog open natively
                return;
            }

            // Ctrl+L — focus label input
            if (mod && (e.key === 'l' || e.key === 'L')) {
                const lbl = document.getElementById('inputLabel');
                if (lbl) {
                    e.preventDefault();
                    lbl.focus();
                    lbl.select && lbl.select();
                }
                return;
            }

            // Ctrl+/ — show shortcuts help
            if (mod && e.key === '/') {
                e.preventDefault();
                showShortcutsHelp();
                return;
            }

            // Esc — close active modal / sign canvas
            if (e.key === 'Escape') {
                // Sign canvas modal
                const signModal = document.getElementById('signModal');
                if (signModal && signModal.classList.contains('show')) {
                    if (typeof closeSign === 'function') closeSign();
                    return;
                }
                // Doc info modal
                const docInfo = document.getElementById('docInfoModal');
                if (docInfo) { docInfo.remove(); return; }
                // New version modal
                const newVer = document.getElementById('newVersionModal');
                if (newVer) { newVer.remove(); return; }
                // Shortcuts help
                const sc = document.getElementById('shortcutsHelpModal');
                if (sc) { sc.remove(); return; }
            }
        });

        // Sync contenteditable fields to hidden inputs
        function syncFields() {
            document.querySelectorAll('.f[data-field]').forEach(el => {
                const hiddenInput = el.nextElementSibling;
                if (hiddenInput && hiddenInput.type === 'hidden') {
                    hiddenInput.value = el.innerText || '';
                }
            });
        }

        // Propagate a value to ALL elements sharing the same field name.
        // Handles contenteditable (.f), hidden inputs, text/date/select, checkbox, radio, qr-content-input.
        // `source` is excluded (to avoid cursor jump / infinite loop).
        function propagateFieldValue(fieldName, value, source) {
            if (!fieldName) return;
            // Contenteditable spans with matching data-field
            document.querySelectorAll(`.f[data-field="${CSS.escape(fieldName)}"]`).forEach(el => {
                if (el === source) return;
                if ((el.innerText || '') !== value) el.innerText = value;
            });
            // All inputs/selects with matching name (hidden, text, date, select, number, qr content)
            document.querySelectorAll(`[name="${CSS.escape(fieldName)}"]`).forEach(el => {
                if (el === source) return;
                if (el.type === 'checkbox') {
                    el.checked = (value === '1' || value === 'true' || value === 'yes');
                } else if (el.type === 'radio') {
                    el.checked = (el.value === value);
                } else if (el.value !== value) {
                    el.value = value;
                }
            });
        }

        // Bind events to all contenteditable fields
        document.querySelectorAll('.f[data-field]').forEach(el => {
            // Sync on input - update hidden sibling + all duplicates
            el.addEventListener('input', function() {
                const val = this.innerText || '';
                const hiddenInput = this.nextElementSibling;
                if (hiddenInput && hiddenInput.type === 'hidden') {
                    hiddenInput.value = val;
                }
                propagateFieldValue(this.dataset.field, val, this);
            });

            // Handle paste - clean up formatting (plain text only)
            el.addEventListener('paste', function(e) {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text/plain');
                document.execCommand('insertText', false, text);
            });

            // Enter to move to next field or blur, Shift+Enter for line break
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    // Find next field
                    const allFields = Array.from(document.querySelectorAll('.f[data-field]'));
                    const idx = allFields.indexOf(this);
                    if (idx < allFields.length - 1) {
                        allFields[idx + 1].focus();
                    } else {
                        this.blur();
                    }
                }
            });
        });

        // Live-sync for non-contenteditable inputs (date, select, checkbox, radio, qr-content-input)
        document.querySelectorAll('input[name], select[name]').forEach(el => {
            // Skip hidden inputs (they're updated via contenteditable propagation) and TTD image data input
            if (el.type === 'hidden') return;
            if (el.id && el.id.startsWith('ttd_')) return; // ttd_* hidden inputs (signature data)

            const handler = function() {
                let val;
                if (el.type === 'checkbox') {
                    val = el.checked ? (el.value || '1') : '';
                } else {
                    val = el.value || '';
                }
                propagateFieldValue(el.name, val, el);
            };
            el.addEventListener('input', handler);
            el.addEventListener('change', handler);
        });

        // Sync before form submit
        const originalSubmitForm = submitForm;
        submitForm = async function() {
            syncFields();
            return originalSubmitForm();
        };
    </script>
</body>
</html>
