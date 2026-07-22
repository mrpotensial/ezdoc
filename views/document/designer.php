<?php
/**
 * ezdoc/views/document/designer.php — full-featured WYSIWYG template designer.
 * Ported dari reference implementation (monolith consumer app).
 *
 * Expected vars in scope:
 *   @var \Ezdoc\Context      $ctx      DB + role provider
 *   @var \Ezdoc\UI\Config    $config   App config (URLs, brand)
 *   @var \Ezdoc\UI\Theme     $theme    Branding + asset URLs (optional)
 *   @var string              $action   'list' | 'edit' | 'create'
 *   @var int                 $id       Template id (if edit)
 *   @var array|null          $template Template row (if edit) OR null
 *   @var array|null          $templates Template list (if action=list)
 *   @var array|null          $existingCategories Autocomplete categories
 *
 * Cross-framework portability: this view is DUMB — no business logic.
 * All server operations via fetch to actions/*.php REST endpoints,
 * with endpoint URLs resolved via `$config` (see $urlSave, $urlCopy, etc.).
 * Consumer library user boleh swap Context to PDO/Doctrine backend.
 *
 * spec: ezdoc-spec/views/designer.md
 */

// ═══════════════════════════════════════════════════════════════
// Bootstrap fallback — support standalone invocation dari consumer's page/ dir
// (kalau consumer wire manual, $ctx sudah ter-inject sebelum include ini)
// ═══════════════════════════════════════════════════════════════
if (!isset($ctx) || !($ctx instanceof \Ezdoc\Context)) {
    // Legacy monolith path: bootstrap + consumer db file sudah loaded upstream.
    if (!class_exists(\Ezdoc\Context::class)) {
        // Try relative bootstrap (page/ → ../ezdoc/bootstrap.php equivalent)
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
// $GLOBALS promotion is REQUIRED (not just convenience) — this view can be
// include()'d from Ezdoc\Http\Router::renderView(), a METHOD, in which case
// top-level vars here live in method-local scope, not global scope. The
// global t() helper below reads $GLOBALS directly so it works regardless of
// which path included this file. Same class of bug fixed for $dbFields in
// commit 1c4a12a (generate.php) — see docs/I18N.md.
if (!isset($translator) || !($translator instanceof \Ezdoc\UI\Translator)) {
    $translator = \Ezdoc\UI\Translator::forView('designer', (string) $config->get('app.locale', 'id'));
}
$GLOBALS['translator'] = $translator;

// Consumer-app globals → abstracted via Context DI
$conn              = $ctx->db;
$author_id         = $ctx->roleProvider->currentUserId();
$author_role_array = $ctx->roleProvider->currentUserRoles();

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

// Route params — allow caller override; fallback to GET (backward compat).
$action = isset($action) ? (string)$action : ($_GET['action'] ?? 'list');
$id     = isset($id)     ? (int)$id       : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$message = '';
$messageType = '';

if (isset($_GET['msg'])) {
    $message = $_GET['msg'] === 'saved' ? t('list.msg_saved', [], 'Template saved successfully') : ($_GET['msg'] === 'deleted' ? t('list.msg_deleted', [], 'Template deleted successfully') : '');
    $messageType = 'success';
}

// ═══════════════════════════════════════════════════════════════
// Config-driven endpoint URLs — swap-able per consumer
// ═══════════════════════════════════════════════════════════════
$urlSave           = (string) $config->get('urls.actions.template.save',            'actions/template/save_template.php');
$urlCopy           = (string) $config->get('urls.actions.template.copy',            'actions/template/copy_template.php');
$urlDelete         = (string) $config->get('urls.actions.template.delete',          'actions/template/delete_template.php');
$urlToggle         = (string) $config->get('urls.actions.template.toggle_lock',     'actions/template/toggle_template_lock.php');
$urlAnalyze        = (string) $config->get('urls.actions.template.analyze_query',   'actions/template/analyze_query.php');
$urlCategories     = (string) $config->get('urls.actions.template.list_categories', 'actions/template/list_categories.php');
$urlFieldUsage     = (string) $config->get('urls.actions.template.field_usage',     'actions/template/field_usage_all.php');
$urlRenameField    = (string) $config->get('urls.actions.template.rename_field',    'actions/template/rename_field.php');
$urlCleanupOrphans = (string) $config->get('urls.actions.template.cleanup_orphans', 'actions/template/cleanup_orphans.php');
$urlListVars       = (string) $config->get('urls.actions.default_vars.list_vars',   'actions/default_vars/list_vars.php');
$urlAddVar         = (string) $config->get('urls.actions.default_vars.add_var',     'actions/default_vars/add_var.php');
$urlDeleteVar      = (string) $config->get('urls.actions.default_vars.delete_var',  'actions/default_vars/delete_var.php');
$urlVerifyPreview  = (string) $config->get('urls.verify_preview',                   '');
$urlPrint          = (string) $config->get('urls.print',                            '');
$urlList           = (string) $config->get('urls.list',                             '?action=list');
$urlCreate         = (string) $config->get('urls.create',                           '?action=create');
$urlEditPattern    = (string) $config->get('urls.edit',                             '?action=edit&id={id}');
$urlBackLink       = (string) $config->get('urls.back',                             '');

// Copy control — config-driven titles + optional back-link visibility
// Consumer boleh override untuk branding, atau default English yg generic.
$designerPageTitle = (string) $config->get('designer.page_title', 'Template Designer');
$designerListTitle = (string) $config->get('designer.list_title', 'Templates');
$designerShowBack  = $urlBackLink !== '';
// Back button di editor mode (create/edit) natural pointing ke templates list.
// Priority: urls.back explicit → urls.designer (App default = ?ezdoc_page=designer)
// → urls.list (self action=list) fallback ultima.
// NOTE: App-orchestrated context, urls.list maps ke DOCUMENTS list not templates —
// jadi kita prefer urls.designer supaya "back" tetap di designer scope.
$urlDesignerNav    = (string) $config->get('urls.designer', '');
if ($urlBackLink !== '') {
    $urlEditorBack = $urlBackLink;
} elseif ($urlDesignerNav !== '') {
    $urlEditorBack = $urlDesignerNav;
} else {
    $urlEditorBack = $urlList;
}

// Consolidated URL bag for JS (data-attribute injection)
$ezdocUrls = [
    'save'            => $urlSave,
    'copy'            => $urlCopy,
    'delete'          => $urlDelete,
    'toggle'          => $urlToggle,
    'analyze'         => $urlAnalyze,
    'categories'      => $urlCategories,
    'fieldUsage'      => $urlFieldUsage,
    'renameField'     => $urlRenameField,
    'cleanupOrphans'  => $urlCleanupOrphans,
    'listVars'        => $urlListVars,
    'addVar'          => $urlAddVar,
    'deleteVar'       => $urlDeleteVar,
    'verifyPreview'   => $urlVerifyPreview,
    'print'           => $urlPrint,
    'edit'            => $urlEditPattern,
];

// ═══════════════════════════════════════════════════════════════
// Repository lookups — abstracted from raw mysqli calls
// spec: ezdoc-spec/schemas/template.json
// ═══════════════════════════════════════════════════════════════
$template = null;
$floatingElementsJson = '[]'; // default: empty array (create mode + no floating)
if ($action === 'edit' && $id > 0) {
    // SELECT dengan alias — preserve legacy column names untuk rendering code.
    // NOTE: TemplateRepository returns typed VO; view code accesses assoc-array shape,
    // so we keep prepared mysqli here for backward-compat with 4400+ lines of view logic.
    $stmt = mysqli_prepare($conn, "
        SELECT id, uuid, version, is_current,
               name AS nama_template,
               category,
               scope AS doc_scope,
               content AS template_html,
               floating_elements,
               signature_config AS config_ttd,
               layout_config AS config_header,
               verify_config,
               access_config,
               is_locked, is_active, owner_id,
               created_at, updated_at
        FROM ezdoc_templates WHERE id=?
    ");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $template = mysqli_fetch_assoc($result);
    if (!$template) { header("Location: " . $urlList); exit; }

    // v0.9.12 Phase 2 (sidecar-native) — separate floating from editor DOM.
    //
    // Load flow:
    //   1. Kalau floating_elements JSON column populated (v0.9.12+ save), use it directly
    //   2. Kalau NULL (legacy row), extract dari HTML markers ke JSON on-the-fly (auto-migrate)
    //   3. Strip markers dari template_html — editor NEVER sees floating markers
    //
    // Result: floating elements di JS state array (window.floatingElements),
    // editor content bebas dari floating markers. Save flow submits array as
    // floating_elements_json.
    $floatingElementsJson = '[]';
    if (!empty($template['floating_elements'])) {
        // Column populated → use JSON directly
        $floatingElementsJson = (string) $template['floating_elements'];
        // Belt-and-suspenders: also strip any stray markers dari template_html
        // (defensive kalau dual-write leg tidak clean HTML properly)
        $extracted = \Ezdoc\Template\FloatingExtractor::extract((string) $template['template_html']);
        $template['template_html'] = $extracted['html'];
    } else {
        // Legacy row (JSON NULL) — extract dari HTML markers, auto-migrate
        $extracted = \Ezdoc\Template\FloatingExtractor::extract((string) $template['template_html']);
        if (!empty($extracted['floating'])) {
            $floatingElementsJson = \Ezdoc\Template\FloatingExtractor::toJson($extracted['floating']);
            $template['template_html'] = $extracted['html'];
        }
    }
}

if (!isset($templates) || !is_array($templates)) {
    $templates = [];
    if ($action === 'list') {
        // Only current version templates yang tampil di list (versioning)
        $result = mysqli_query($conn, "
            SELECT id, name AS nama_template, category, is_locked, created_at, updated_at
            FROM ezdoc_templates
            WHERE is_current = 1 AND deleted_at IS NULL
            ORDER BY updated_at DESC
        ");
        while ($row = mysqli_fetch_assoc($result)) $templates[] = $row;
    }
}

// Fetch existing categories for autocomplete datalist (used in editor + list filter)
if (!isset($existingCategories) || !is_array($existingCategories)) {
    $existingCategories = [];
    $catRes = mysqli_query($conn, "SELECT DISTINCT category FROM ezdoc_templates WHERE category != '' ORDER BY category ASC");
    if ($catRes) {
        while ($cr = mysqli_fetch_assoc($catRes)) $existingCategories[] = $cr['category'];
    }
}

// Fragment mode: list mode wrapped oleh layout.php (dapat primary nav).
// Editor mode selalu full page (dompdf + TinyMCE punya assumption own layout).
$__ezdoc_isFragment = !empty($__ezdoc_fragment);
?>
<?php if (!$__ezdoc_isFragment): ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($designerPageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#5f61e6' } } }
        }
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.13.5/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
    <style>
        /* PRESERVE: dompdf renders this, Tailwind CDN not applied — do NOT rename or Tailwind-ify */
        /* CSS variables + global reset (Tailwind can't handle @variables + html/body selectors) */
        :root { --primary: #5f61e6; }
        html, body { height: 100%; margin: 0; }
        body { background: #64748b; }

        /* PRESERVE: Rendered into TinyMCE editor content → dompdf PDF (Tailwind CDN doesn't apply there) */
        .field-tag {
            display: inline; background: #dbeafe; color: #1e40af;
            padding: 1px 6px; border-radius: 4px; font-family: monospace; font-size: 12px;
        }

        /* PRESERVE: JS-toggle state contract — JS sets className to 'save-indicator saving' / 'save-indicator saved' */
        .save-indicator { font-size: 12px; color: #fff; }
        .save-indicator.saving { color: #fbbf24; }
        .save-indicator.saved { color: #4ade80; }

        /* PRESERVE: TinyMCE Shadow-DOM overrides — need !important to beat internal CSS */
        .tox-tinymce { border: none !important; }
        .tox-editor-container { display: flex !important; flex-direction: column !important; }
        .tox-edit-area { flex: 1 !important; }
        .tox-edit-area__iframe { width: 100% !important; }
        .tox-tinymce-aux { position: fixed !important; z-index: 99999 !important; }

        /* PRESERVE: Alpine state-based selectors — Alpine :class binding toggles .is-collapsed */
        .panel-header .collapse-icon { transition: transform 0.2s; }
        .panel-header.is-collapsed .collapse-icon { transform: rotate(-90deg); }

        /* Sticky panel headers — macOS Preferences / Notion / Apple Mail pattern.
           Header stays visible + clickable saat scroll di dalam section-nya.
           Multi panels stack naturally (as user scrolls, current header stays at
           top until next section's header pushes it out).

           Contract:
           - position:sticky pada .panel-header + solid bg supaya list items yg
             lewat di baliknya tidak bocor
           - z-10 di atas card content (card default z:auto)
           - top:0 relative to nearest scrolling ancestor (.sidebar-scroll)
           - backdrop-blur untuk hint iOS-native saat card ada di belakang
           - Subtle border-bottom untuk visual separator when stuck */
        .panel-header {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: saturate(180%) blur(8px);
            -webkit-backdrop-filter: saturate(180%) blur(8px);
            /* Prevent header dari overlap dgn scrollbar */
            padding-right: max(0.75rem, env(safe-area-inset-right, 0));
        }
        /* Kalau expanded (content di bawah header terlihat), tambahkan subtle
           bottom shadow untuk depth. .is-collapsed = tidak butuh (nothing below). */
        .panel-header:not(.is-collapsed) {
            box-shadow: 0 1px 0 0 rgba(0, 0, 0, 0.05);
        }

        /* PRESERVE: JS-toggled class for filter (JS adds/removes at runtime) */
        .panel-list-item-hidden { display: none !important; }

        /* Click-to-focus flash — ring pulse + subtle indigo tint, 1.4s ease-out.
           Industry pattern: VS Code Peek highlight + Figma layer flash. */
        @keyframes ezdocFlashFocus {
            0%   { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.55); background-color: rgba(238, 242, 255, 0.75); }
            60%  { box-shadow: 0 0 0 6px rgba(99, 102, 241, 0); background-color: rgba(238, 242, 255, 0.45); }
            100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); background-color: transparent; }
        }
        .panel-list-item.ezdoc-flash-focus { animation: ezdocFlashFocus 1.4s ease-out; }

        /* Active card — persistent state: user sedang edit input di card, atau
           card baru saja dipilih via click placeholder di editor. Industry
           pattern: VS Code Outline focused row, Figma layer active, Notion
           block-focus. Distinct dari flash (temporal, 1.4s) — is-active retain
           sampai user pindah ke card lain / klik luar sidebar. */
        .panel-list-item.is-active {
            background-color: rgba(238, 242, 255, 0.85); /* indigo-50 tint */
            border-color: rgba(99, 102, 241, 0.55) !important; /* indigo-500 */
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25),
                        0 4px 12px rgba(15, 23, 42, 0.08);
            transform: translateY(-1px);
            transition: box-shadow 160ms ease-out, transform 160ms ease-out,
                        background-color 160ms ease-out;
        }
        /* Boost left accent stripe for active card */
        .panel-list-item.is-active::before {
            width: 4px !important;
            background-color: rgb(99, 102, 241) !important; /* solid indigo-500 */
        }

        /* Field card details — hide default triangle marker (Safari + Firefox) */
        details.group summary::-webkit-details-marker { display: none; }
        details.group summary::marker { display: none; }

        /* Clean minimalist scrollbar untuk sidebar (Notion/Linear pattern) */
        .sidebar-scroll {
            scrollbar-width: thin;                    /* Firefox */
            scrollbar-color: #d1d5db transparent;    /* Firefox */
        }
        .sidebar-scroll::-webkit-scrollbar { width: 8px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: transparent;
            border-radius: 4px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        .sidebar-scroll:hover::-webkit-scrollbar-thumb { background: #d1d5db; background-clip: padding-box; }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; background-clip: padding-box; }
    </style>
</head>
<body>
<?php endif; /* !$__ezdoc_isFragment */ ?>
    <div class="fixed top-5 right-5 z-[9999]" id="toastContainer"></div>

    <?php if ($action === 'list'): ?>
    <?php
    // v0.9.11 view separation — template list view extracted ke standalone file
    // untuk industry-standard MVC one-view-per-action pattern (Laravel/Filament/
    // Symfony convention). Inherits parent scope for shared vars.
    require __DIR__ . '/template_list.php';
    ?>
    <?php else: ?>
    <!-- EDITOR MODE -->
    <div class="h-screen overflow-hidden p-0 w-full" id="ezdocDesignerRoot" data-ezdoc-urls='<?= h(json_encode($ezdocUrls, JSON_UNESCAPED_SLASHES)) ?>' data-ezdoc-i18n='<?= h(json_encode($translator->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
        <div class="h-full flex flex-wrap">
            <!-- Main Editor Area -->
            <div class="h-full flex flex-col w-full md:w-2/3 lg:w-3/4">
                <!-- Top Bar -->
                <div class="bg-gray-900 text-white p-2 flex justify-between items-center shrink-0">
                    <div class="flex items-center flex-wrap gap-1.5">
                        <a href="<?= h($urlEditorBack) ?>" class="inline-flex items-center rounded-md border border-white/30 bg-white/5 p-1.5 text-white hover:bg-white/10 focus:outline-none focus:ring-1 focus:ring-white/40" title="<?= h(t('title.back_to_list', [], 'Back to template list')) ?>">
                            <i class="bi bi-arrow-left"></i>
                        </a>
                        <input type="text" id="namaTemplate" class="rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2.5 py-1.5 text-gray-900 w-[220px]" value="<?= h($template['nama_template'] ?? '') ?>" placeholder="<?= h(t('placeholder.template_name', [], 'Template Name *')) ?>">
                        <input type="text" id="templateCategory" list="categoryList" class="rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2.5 py-1.5 text-gray-900 w-[160px]" value="<?= h($template['category'] ?? '') ?>" placeholder="<?= h(t('placeholder.category_optional', [], 'Category (optional)')) ?>" title="<?= h(t('title.category_folder', [], 'Category/folder for grouping templates')) ?>" maxlength="100">
                        <datalist id="categoryList">
                            <?php foreach ($existingCategories as $cat): ?>
                            <option value="<?= h($cat) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <select id="templateDocScope" class="rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2.5 py-1.5 text-gray-900 w-[180px]" title="<?= h(t('title.template_type', [], 'Template type — General for non-patient letters (orders, memos, tasks)')) ?>">
                            <?php $__scope = $template['doc_scope'] ?? 'patient'; ?>
                            <option value="patient" <?= $__scope === 'patient' ? 'selected' : '' ?>><?= h(t('editor.scope_patient', [], 'Patient Letter (requires NORM)')) ?></option>
                            <option value="general" <?= $__scope === 'general' ? 'selected' : '' ?>><?= h(t('editor.scope_general', [], 'General Letter (no NORM)')) ?></option>
                        </select>
                        <span class="save-indicator ml-1" id="saveIndicator"></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <?php if (!empty($template['is_locked'])): ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-200 mr-1" title="<?= h(t('title.template_locked', [], 'Template locked: destructive changes will be warned')) ?>"><i class="bi bi-lock-fill mr-1"></i><?= h(t('list.badge_locked', [], 'Locked')) ?></span>
                        <?php endif; ?>
                        <?= \Ezdoc\UI\Slot::render('designer:toolbar-extra', ['template' => $template]) ?>
                        <button type="button" class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-1 focus:ring-white/40" style="background-color: var(--ezdoc-primary);" onclick="saveTemplate()">
                            <i class="bi bi-check-lg"></i><?= h(t('actions.save', [], 'Save')) ?>
                        </button>
                        <a href="<?= h($urlPrint . (strpos($urlPrint,'?') !== false ? '&' : '?') . 'template_id=' . ($template['id'] ?? 0)) ?>" id="btnPreview" class="inline-flex items-center gap-1 rounded-md border border-white/30 bg-white/5 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/10 focus:outline-none focus:ring-1 focus:ring-white/40" target="_blank">
                            <i class="bi bi-printer"></i><?= h(t('toolbar.preview', [], 'Preview')) ?>
                        </a>
                        <button type="button" class="inline-flex items-center gap-1 rounded-md border border-white/30 bg-white/5 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/10 focus:outline-none focus:ring-1 focus:ring-white/40" onclick="showParamsSummary()">
                            <i class="bi bi-link-45deg"></i><?= h(t('toolbar.url_params', [], 'URL Params')) ?>
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-md border border-white/30 bg-white/5 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/10 focus:outline-none focus:ring-1 focus:ring-white/40" onclick="showFieldInspector()">
                            <i class="bi bi-search"></i><?= h(t('toolbar.inspect_fields', [], 'Inspect Fields')) ?>
                        </button>
                    </div>
                </div>

                <input type="hidden" id="templateId" value="<?= $template['id'] ?? 0 ?>">

                <!-- Editor Wrapper — paper visualization pindah ke iframe body CSS
                     (Google Docs pattern). Wrapper cuma flex container, TinyMCE
                     iframe handle paper card + gray backdrop consistent di normal
                     + fullscreen mode. -->
                <div class="flex-1 overflow-hidden" id="editorWrapper">
                    <div class="w-full h-full" id="editorContainer">
                        <textarea id="editor"><?= h($template['template_html'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="w-full md:w-1/3 lg:w-1/4 bg-white h-screen overflow-y-auto border-l border-gray-200 sidebar-scroll">
                <div class="px-3">
                    <?= \Ezdoc\UI\Slot::render('designer:sidebar-header', ['template' => $template]) ?>
                </div>
                <!-- Halaman Panel — Kertas + Orientasi + Margin combined (collapsed default after first setup) -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-file-earmark"></i><?= h(t('page.panel_title', [], 'Page')) ?></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="px-3 pb-3 space-y-3">
                            <!-- Subsection: Ukuran -->
                            <div class="space-y-1.5">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400"><?= h(t('page.size_label', [], 'Size')) ?></div>
                                <select class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="paperSize" onchange="updatePageSize()">
                                    <option value="A4">A4 (210 x 297 mm)</option>
                                    <option value="A5">A5 (148 x 210 mm)</option>
                                    <option value="Letter">Letter (216 x 279 mm)</option>
                                    <option value="Legal">Legal (216 x 356 mm)</option>
                                    <option value="F4">F4/Folio (215 x 330 mm)</option>
                                    <option value="Custom"><?= h(t('page.custom_option', [], 'Custom...')) ?></option>
                                </select>
                                <div id="customSizePanel" class="grid grid-cols-2 gap-1.5" style="display:none;">
                                    <div>
                                        <label class="block text-[10px] text-gray-500 mb-0.5"><?= h(t('page.width_mm', [], 'Width (mm)')) ?></label>
                                        <input type="number" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="customWidth" value="210" min="50" max="500" oninput="updatePageSize()">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-gray-500 mb-0.5"><?= h(t('page.height_mm', [], 'Height (mm)')) ?></label>
                                        <input type="number" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="customHeight" value="297" min="50" max="500" oninput="updatePageSize()">
                                    </div>
                                </div>
                                <div class="inline-flex w-full rounded-md overflow-hidden border border-gray-300" role="group">
                                    <input type="radio" class="hidden peer/portrait" name="orientation" id="orientPortrait" value="portrait" checked onchange="updatePageSize()">
                                    <label class="flex-1 text-center text-xs px-2 py-1.5 cursor-pointer bg-white hover:bg-gray-50 peer-checked/portrait:bg-gray-800 peer-checked/portrait:text-white" for="orientPortrait"><?= h(t('page.orientation_portrait', [], 'Portrait')) ?></label>
                                    <input type="radio" class="hidden peer/landscape" name="orientation" id="orientLandscape" value="landscape" onchange="updatePageSize()">
                                    <label class="flex-1 text-center text-xs px-2 py-1.5 cursor-pointer bg-white hover:bg-gray-50 peer-checked/landscape:bg-gray-800 peer-checked/landscape:text-white border-l border-gray-300" for="orientLandscape"><?= h(t('page.orientation_landscape', [], 'Landscape')) ?></label>
                                </div>
                            </div>

                            <!-- Subsection: Layout Mode -->
                            <div class="space-y-1.5">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400"><?= h(t('page.layout_mode_label', [], 'Layout Mode')) ?></div>
                                <select class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="layoutMode" onchange="updatePageSize()">
                                    <option value="paged"><?= h(t('page.layout_paged', [], 'Paged (multi-page cards)')) ?></option>
                                    <option value="continuous"><?= h(t('page.layout_continuous', [], 'Continuous (no page breaks)')) ?></option>
                                </select>
                            </div>

                            <!-- Subsection: Margin -->
                            <div class="space-y-1.5">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400"><?= h(t('page.margin_label', [], 'Margin (mm)')) ?></div>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <div>
                                        <label class="block text-[10px] text-gray-500 mb-0.5"><?= h(t('page.margin_top', [], 'Top')) ?></label>
                                        <input type="number" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="padTop" value="20" min="0" max="100" oninput="updatePageSize()">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-gray-500 mb-0.5"><?= h(t('page.margin_bottom', [], 'Bottom')) ?></label>
                                        <input type="number" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="padBottom" value="20" min="0" max="100" oninput="updatePageSize()">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-gray-500 mb-0.5"><?= h(t('page.margin_left', [], 'Left')) ?></label>
                                        <input type="number" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="padLeft" value="20" min="0" max="100" oninput="updatePageSize()">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-gray-500 mb-0.5"><?= h(t('page.margin_right', [], 'Right')) ?></label>
                                        <input type="number" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="padRight" value="20" min="0" max="100" oninput="updatePageSize()">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Field Panel (Collapsible) -->
                <div class="border-b border-gray-200" x-data="{ open: true }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-input-cursor-text"></i>Fields <span id="fieldCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="relative mb-1">
                                <input type="search" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="fieldSearch" placeholder="<?= h(t('field.search_placeholder', [], 'Search field (name/label/type)...')) ?>" oninput="filterPanel('fieldList', this.value)">
                                <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 border-0 bg-transparent text-gray-400 hover:text-red-500 text-base leading-none cursor-pointer px-1" onclick="clearPanelSearch('fieldSearch','fieldList')" title="<?= h(t('field.clear_search_title', [], 'Clear')) ?>">×</button>
                            </div>
                            <div id="fieldList"></div>
                            <div id="fieldEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('field.empty_hint', [], 'Click "+ Field" in the editor')) ?>
                            </div>
                            <div id="fieldNoMatch" class="text-center text-gray-500 text-xs py-2" style="display:none;">
                                <em><?= h(t('field.no_match', [], 'No field matches the search.')) ?></em>
                            </div>
                            <button type="button" class="w-full mt-1 inline-flex items-center justify-center px-2 py-1 rounded text-xs border border-gray-500 text-gray-700 hover:bg-gray-50" onclick="openVarManager()">
                                <i class="bi bi-gear mr-1"></i><?= h(t('field.manage_vars_button', [], 'Manage Default Variables')) ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Logo Panel (Collapsible) -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-image"></i>Logo <span id="logoCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div id="logoList"></div>
                            <div id="logoEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('logo.empty_hint', [], 'Click "+ Logo" in the editor')) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Floating Elements Panel (v0.9.12 Phase 2 — sidecar-native) -->
                <div class="border-b border-gray-200" x-data="{ open: true }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-layers"></i><?= h(t('floating.panel_title', [], 'Floating Elements')) ?> <span id="floatingCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div id="floatingList"></div>
                            <div id="floatingEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('floating.empty_hint', [], 'Insert floating logo/TTD/QR/materai from toolbar → appears here (not in editor)')) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TTD Panel (Collapsible) -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-pen"></i><?= h(t('fallback.signature', [], 'Signature')) ?> <span id="ttdCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="relative mb-1">
                                <input type="search" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="ttdSearch" placeholder="<?= h(t('ttd.search_placeholder', [], 'Search signature...')) ?>" oninput="filterPanel('ttdList', this.value)">
                                <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 border-0 bg-transparent text-gray-400 hover:text-red-500 text-base leading-none cursor-pointer px-1" onclick="clearPanelSearch('ttdSearch','ttdList')" title="<?= h(t('field.clear_search_title', [], 'Clear')) ?>">×</button>
                            </div>
                            <div id="ttdList"></div>
                            <div id="ttdEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('ttd.empty_hint', [], 'Click "+ Signature" in the editor')) ?>
                            </div>
                            <div id="ttdNoMatch" class="text-center text-gray-500 text-xs py-2" style="display:none;">
                                <em><?= h(t('ttd.no_match', [], 'No signature matches.')) ?></em>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Materai Panel (Collapsible) -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-stamp"></i><?= h(t('materai.panel_title', [], 'Stamp Duty')) ?> <span id="materaiCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="relative mb-1">
                                <input type="search" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1" id="materaiSearch" placeholder="<?= h(t('materai.search_placeholder', [], 'Search stamp duty...')) ?>" oninput="filterPanel('materaiList', this.value)">
                                <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 border-0 bg-transparent text-gray-400 hover:text-red-500 text-base leading-none cursor-pointer px-1" onclick="clearPanelSearch('materaiSearch','materaiList')" title="<?= h(t('field.clear_search_title', [], 'Clear')) ?>">×</button>
                            </div>
                            <div id="materaiList"></div>
                            <div id="materaiEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('materai.empty_hint', [], 'Click "+ Stamp Duty" in the editor')) ?>
                            </div>
                            <div id="materaiNoMatch" class="text-center text-gray-500 text-xs py-2" style="display:none;">
                                <em><?= h(t('materai.no_match', [], 'No stamp duty matches.')) ?></em>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Query DB Panel (Collapsible) -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-database"></i>Query DB <span id="tabledbCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <button type="button" class="w-full mb-2 inline-flex items-center justify-center px-2 py-1 rounded text-xs border border-cyan-500 text-cyan-600 hover:bg-cyan-50" onclick="openTabledbModal()"><i class="bi bi-plus-lg mr-1"></i><?= h(t('tabledb.add_button', [], 'Add Query')) ?></button>
                            <div id="tabledbList"></div>
                            <div id="tabledbEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('tabledb.empty_hint', [], 'No query yet.')) ?><br>
                                <span class="text-xs"><?= h(t('tabledb.empty_detail', [], 'Add a query → the {{tabledb.x.column}} variable can be used in a regular TinyMCE table for repeating rows.')) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- QR Panel (Collapsible) -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-qr-code"></i>QR Code <span id="qrCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div id="qrList"></div>
                            <div id="qrEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('qr.empty_hint', [], 'Click "+ QR" in the editor')) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kondisi Panel (Collapsible) — conditional sections -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-diamond-half"></i><?= h(t('cond.panel_title', [], 'Condition')) ?> <span id="condCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div id="condList"></div>
                            <div id="condEmpty" class="text-center text-gray-500 text-xs py-2">
                                <?= h(t('cond.empty_hint', [], 'Click "Condition" in the editor toolbar')) ?>
                            </div>
                            <div class="mt-1 text-[10px] text-gray-500 leading-tight">
                                <i class="bi bi-info-circle"></i> <?= h(t('cond.operator_hint', [], 'Operator:')) ?> <code>= != &gt; &lt; &gt;= &lt;=</code> · <?= h(t('cond.combine_hint', [], 'Combine:')) ?> <code>AND</code> <code>OR</code>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Konfigurasi Verifikasi Publik (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }" x-init="$watch('open', v => { if(v) window.dispatchEvent(new CustomEvent('verify-panel-shown')) })">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-shield-check"></i><?= h(t('verify.panel_title', [], 'Verify Config')) ?> <span id="verifyFieldCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse id="verifyConfigCollapse">
                        <div class="p-2 pt-0">
                            <div class="text-gray-500 mb-2 text-[11px]">
                                <?= h(t('verify.intro_hint', [], 'Fields shown on the public verification page (when the QR is scanned). Leave empty to use the default (NORM + patient name + common fields).')) ?>
                            </div>

                            <!-- Toggle: tampilkan data pasien -->
                            <div class="flex items-center gap-2 mb-2 text-xs">
                                <input class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" type="checkbox" id="verifyShowPatient" onchange="onVerifyConfigChange()">
                                <label class="cursor-pointer" for="verifyShowPatient" title="<?= h(t('verify.show_patient_title', [], 'Show NORM + Patient Name + Nopen block')) ?>">
                                    <?= h(t('verify.show_patient_label', [], 'Show Patient Data')) ?>
                                </label>
                            </div>

                            <hr class="my-2">

                            <!-- Field custom list -->
                            <div class="text-xs mb-1"><strong><?= h(t('verify.custom_fields_label', [], 'Custom Fields in Verification:')) ?></strong></div>
                            <div id="verifyFieldsList"></div>
                            <div id="verifyFieldsEmpty" class="text-center text-gray-500 py-2 text-[11px]">
                                <em><?= h(t('verify.no_custom_fields', [], 'No custom fields yet. Use the + button below, or leave empty to use the default whitelist.')) ?></em>
                            </div>
                            <div class="grid gap-1 mt-1">
                                <button type="button" class="inline-flex items-center justify-center px-2 py-1 rounded border border-blue-600 text-blue-600 hover:bg-blue-50 text-[11px]" onclick="addVerifyField()">
                                    <i class="bi bi-plus-lg"></i> <?= h(t('verify.add_field_button', [], 'Add Field')) ?>
                                </button>
                                <button type="button" class="inline-flex items-center justify-center px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 text-[11px]" onclick="previewVerifikasi()">
                                    <i class="bi bi-eye"></i> <?= h(t('verify.preview_button', [], 'Preview Verification Page')) ?>
                                </button>
                            </div>

                            <div class="text-gray-500 mt-2 text-[10px]">
                                <i class="bi bi-info-circle"></i> <?= h(t('verify.order_hint', [], 'These fields will appear in the order listed above')) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Konfigurasi Akses Template (Collapsible) - RBAC per template -->
                <div class="border-b border-gray-200" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer hover:bg-gray-50 flex justify-between items-center px-3 py-2 select-none" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-medium text-gray-700 flex items-center gap-1.5"><i class="bi bi-lock-fill"></i><?= h(t('access.panel_title', [], 'Access Config')) ?> <span id="accessConfigCount" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon text-gray-400 text-xs"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="p-2 rounded mb-2 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-[11px]">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong><?= h(t('access.important_label', [], 'Important')) ?></strong>: <?= h(t('access.warning_text', [], 'per-action RBAC is independent. If you set Create but leave Edit empty, users can still update documents (v2 backward compat). To lock all actions, fill in all of them, or use the')) ?>
                                <strong>"<?= h(t('access.copy_to_all_button', [], 'Copy to All Actions')) ?>"</strong> <?= h(t('access.warning_text_suffix', [], 'button below.')) ?>
                            </div>

                            <!-- Mode enforcement -->
                            <div class="mb-2">
                                <label class="font-bold text-[11px]"><?= h(t('access.mode_label', [], 'Enforcement Mode:')) ?></label>
                                <select class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessMode" onchange="onAccessConfigChange()">
                                    <option value="strict"><?= h(t('access.mode_strict', [], 'Strict — reject with error')) ?></option>
                                    <option value="permissive"><?= h(t('access.mode_permissive', [], 'Permissive — allow but log audit')) ?></option>
                                </select>
                            </div>

                            <!-- Status Akses Anda (real-time preview based on current inputs) -->
                            <div class="mb-2 p-2 border border-gray-300 rounded bg-green-50 text-[11px]">
                                <div class="flex justify-between items-center mb-1">
                                    <strong class="text-emerald-800"><i class="bi bi-person-check"></i> <?= h(t('access.your_access_status', [], 'Your Access Status')) ?></strong>
                                    <button type="button" class="inline-flex items-center px-1 py-0 rounded border border-gray-500 text-gray-700 hover:bg-gray-50 text-[10px]"
                                            onclick="refreshAccessPreview()" title="<?= h(t('access.refresh_title', [], 'Refresh check based on the inputs above')) ?>">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                                <div class="text-[10px] text-gray-500">
                                    <?= h(t('access.user_id_label', [], 'User ID:')) ?> <code><?= (int)($author_id ?? 0) ?></code>
                                    &middot; <?= h(t('access.roles_label', [], 'Roles:')) ?> <code><?= h(implode(', ', is_array($author_role_array ?? null) ? $author_role_array : [])) ?></code>
                                </div>
                                <div id="accessPreviewResult" class="mt-1"></div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="flex gap-1 mb-2 flex-wrap">
                                <button type="button" class="inline-flex items-center px-2 py-0 rounded border border-blue-600 text-blue-600 hover:bg-blue-50 text-[10px]" onclick="copyAccessCreateToAll()" title="<?= h(t('access.copy_to_all_title', [], 'Copy roles+users from CREATE to EDIT, LOCK & DELETE — so all actions have the same restriction')) ?>">
                                    <i class="bi bi-arrow-down-square"></i> <?= h(t('access.copy_to_all_button', [], 'Copy to All Actions')) ?>
                                </button>
                                <button type="button" class="inline-flex items-center px-2 py-0 rounded border border-red-600 text-red-600 hover:bg-red-50 text-[10px]" onclick="clearAccessConfig()" title="<?= h(t('access.reset_title', [], 'Reset all fields to empty (= allow all)')) ?>">
                                    <i class="bi bi-x-circle"></i> <?= h(t('access.reset_button', [], 'Reset')) ?>
                                </button>
                                <button type="button" class="inline-flex items-center px-2 py-0 rounded border border-yellow-500 text-yellow-600 hover:bg-yellow-50 text-[10px]" onclick="fillAccessSuperadminOnly()" title="<?= h(t('access.superadmin_only_title', [], "Fill all actions with the 'superadmin' role only")) ?>">
                                    <i class="bi bi-shield-lock"></i> <?= h(t('access.superadmin_only_button', [], 'Lock to Superadmin')) ?>
                                </button>
                            </div>

                            <hr class="my-2">

                            <!-- Per-action config: create -->
                            <div class="mb-2">
                                <label class="font-bold text-blue-600 text-[11px]">
                                    <i class="bi bi-plus-circle"></i> <?= h(t('access.can_create_label', [], 'Can CREATE document:')) ?>
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessCreateRoles"
                                       placeholder="<?= h(t('access.roles_placeholder', ['example' => 'dokter,perawat'], 'Roles: {example} (empty = all)')) ?>"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessCreateUsers"
                                       placeholder="<?= h(t('access.users_placeholder', [], 'User IDs: 42,99 (empty = all)')) ?>"
                                       oninput="onAccessConfigChange()">
                            </div>

                            <!-- Per-action config: edit -->
                            <div class="mb-2">
                                <label class="font-bold text-yellow-600 text-[11px]">
                                    <i class="bi bi-pencil"></i> <?= h(t('access.can_edit_label', [], 'Can EDIT document:')) ?>
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessEditRoles"
                                       placeholder="<?= h(t('access.roles_placeholder', ['example' => 'dokter,perawat'], 'Roles: {example} (empty = all)')) ?>"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessEditUsers"
                                       placeholder="<?= h(t('access.users_placeholder', [], 'User IDs: 42,99 (empty = all)')) ?>"
                                       oninput="onAccessConfigChange()">
                            </div>

                            <!-- Per-action config: lock -->
                            <div class="mb-2">
                                <label class="font-bold text-red-600 text-[11px]">
                                    <i class="bi bi-lock"></i> <?= h(t('access.can_lock_label', [], 'Can LOCK document:')) ?>
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessLockRoles"
                                       placeholder="<?= h(t('access.lock_roles_placeholder', ['example' => 'kepala_bidang'], 'Roles: {example} (empty = all)')) ?>"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessLockUsers"
                                       placeholder="<?= h(t('access.users_placeholder_short', [], 'User IDs (empty = all)')) ?>"
                                       oninput="onAccessConfigChange()">
                            </div>

                            <!-- Per-action config: delete (default: superadmin-only kalau kosong, beda dgn create/edit/lock!) -->
                            <div class="mb-2">
                                <label class="font-bold text-[11px] text-[#7f1d1d]">
                                    <i class="bi bi-trash"></i> <?= h(t('access.can_delete_label', [], 'Can DELETE document:')) ?>
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessDeleteRoles"
                                       placeholder="<?= h(t('access.delete_roles_placeholder', [], 'Roles (empty = superadmin only)')) ?>"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessDeleteUsers"
                                       placeholder="<?= h(t('access.delete_users_placeholder', [], 'User IDs (empty = superadmin only)')) ?>"
                                       oninput="onAccessConfigChange()">
                                <div class="mt-1 text-[10px] text-[#7f1d1d]">
                                    <i class="bi bi-exclamation-circle"></i> <?= h(t('access.delete_warning', [], 'Delete = destructive. Default empty = superadmin only (different from create/edit/lock).')) ?>
                                </div>
                            </div>

                            <div class="text-gray-500 mt-2 text-[10px]">
                                <i class="bi bi-info-circle"></i> <?= h(t('access.format_hint', [], 'Format: comma-separated. Role name must match hasRole(). User ID is id_pegawai. Superadmin can always do anything.')) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?= \Ezdoc\UI\Slot::render('designer:sidebar-panel-extra', ['template' => $template]) ?>

                <!-- Editor toolbar customization hook (metadata slot; actual TinyMCE toolbar via config) -->
                <div style="display:none;"><?= \Ezdoc\UI\Slot::render('designer:editor-toolbar-extra', ['template' => $template]) ?></div>

                <!-- Modal Preview Verifikasi (Alpine) -->
                <div x-data x-show="$store.modals.verifyPreview" x-transition
                     class="fixed inset-0 flex items-center justify-center p-4 z-[20000]"
                     style="display:none;">
                    <div class="fixed inset-0 bg-black/50" @click="$store.modals.verifyPreview = false"></div>
                    <div class="relative bg-white rounded-xl w-full mx-4 verifyPreview-dialog border-0 overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,0.5)]" id="verifyPreviewModal">
                        <!-- Header dengan indikator MODE PREVIEW menonjol -->
                        <div class="py-2 px-3 border-0 flex justify-between items-center text-white bg-gradient-to-r from-amber-500 to-orange-500">
                            <h6 class="mb-0 flex items-center gap-2 flex-wrap text-sm">
                                <i class="bi bi-eye"></i>
                                <span><?= h(t('verify.preview_modal_title', [], 'Verification Page Preview')) ?></span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white text-yellow-600 text-[10px] font-bold tracking-wider"><?= h(t('verify.preview_mode_badge', [], 'PREVIEW MODE')) ?></span>
                            </h6>
                            <button type="button" class="text-white opacity-80 hover:opacity-100 text-2xl leading-none" @click="$store.modals.verifyPreview = false" aria-label="Close">&times;</button>
                        </div>
                        <!-- Body: iframe langsung tanpa banner injected -->
                        <div class="p-0 h-[78vh] bg-gray-100 overflow-hidden relative">
                            <div id="verifyPreviewLoading" class="absolute inset-0 flex items-center justify-center text-gray-500 bg-white z-[5]">
                                <div class="text-center">
                                    <div class="inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" role="status"></div>
                                    <div class="mt-2 text-xs"><?= h(t('verify.rendering_preview', [], 'Rendering preview...')) ?></div>
                                </div>
                            </div>
                            <iframe id="verifyPreviewIframe" class="w-full h-full border-0 block bg-gray-100" title="<?= h(t('verify.preview_iframe_title', [], 'Verification Preview')) ?>" sandbox="allow-same-origin"></iframe>
                        </div>
                        <div class="py-2 px-3 bg-gray-100 border-0 flex justify-between items-center">
                            <small class="text-gray-500 text-[11px]">
                                <i class="bi bi-info-circle"></i> <?= h(t('verify.mock_value_hint', [], 'Field values = mock/sample based on the fields in the template. Real: from the document field_values.')) ?>
                            </small>
                            <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.verifyPreview = false"><?= h(t('actions.close', [], 'Close')) ?></button>
                        </div>
                    </div>
                </div>
                <style>
                    /* Modal preview verifikasi — lebar cukup untuk landing page card (480px card + padding) */
                    .verifyPreview-dialog {
                        max-width: 720px !important;
                        width: 95vw;
                    }
                    @media (max-width: 800px) {
                        .verifyPreview-dialog { max-width: 96vw !important; }
                    }
                </style>

                <!-- Help -->
                <div class="border border-slate-200 rounded-lg p-3 mb-2.5 bg-gray-100">
                    <small class="text-gray-500 text-xs">
                        <strong><?= h(t('help.shortcut_label', [], 'Shortcut:')) ?></strong> <?= h(t('help.shortcut_save', [], 'Ctrl+S to save')) ?><br>
                        <strong><?= h(t('help.field_label', [], '+ Field:')) ?></strong> Text, Number, Date, Checkbox, Radio, Select<br>
                        <span class="field-tag">+ Logo</span> <?= h(t('help.for_logo', [], 'for logo')) ?><br>
                        <span class="field-tag">+ Tabel</span> <?= h(t('help.for_table', [], 'for table')) ?><br>
                        <span class="field-tag">+ Tabel DB</span> <?= h(t('help.for_tabledb', [], 'for database query table')) ?>
                    </small>
                </div>
            </div>
        </div>
    </div>


    <!-- Default Vars Manager Modal (Alpine) -->
    <div x-data x-show="$store.modals.varManager" x-transition
         class="fixed inset-0 flex items-center justify-center p-4 z-[100010]"
         style="display:none;">
        <div class="fixed inset-0 bg-black/50" @click="$store.modals.varManager = false"></div>
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto" id="varManagerModal">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gray-100 rounded-t-lg">
                <h6 class="text-base font-semibold"><i class="bi bi-gear mr-1"></i><?= h(t('field.manage_vars_button', [], 'Manage Default Variables')) ?></h6>
                <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="$store.modals.varManager = false">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-xs text-gray-500 mb-2"><?= h(t('modal.var_manager_intro', [], 'Variables allowed to be used as a field default value (prefix $). Example: type $author_nama in the Default column.')) ?></p>
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-200 text-xs mb-2">
                        <thead class="bg-gray-100">
                            <tr><th class="border border-gray-200 px-2 py-1 text-left"><?= h(t('modal.var_name_col', [], 'Variable Name')) ?></th><th class="border border-gray-200 px-2 py-1 text-left"><?= h(t('modal.description_col', [], 'Description')) ?></th><th class="border border-gray-200 px-2 py-1 w-[50px]"></th></tr>
                        </thead>
                        <tbody id="varListBody">
                            <tr><td colspan="3" class="border border-gray-200 px-2 py-1 text-center text-gray-500"><?= h(t('modal.loading', [], 'Loading...')) ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <h6 class="text-sm mt-3 font-semibold"><?= h(t('modal.add_variable_title', [], 'Add Variable')) ?></h6>
                <div class="grid grid-cols-12 gap-2">
                    <div class="col-span-5">
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="newVarName" placeholder="<?= h(t('modal.var_name_placeholder', [], 'variable_name')) ?>">
                    </div>
                    <div class="col-span-5">
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="newVarDesc" placeholder="<?= h(t('modal.description_col', [], 'Description')) ?>">
                    </div>
                    <div class="col-span-2">
                        <button type="button" class="w-full inline-flex items-center justify-center px-2 py-1 rounded text-xs bg-blue-600 text-white hover:bg-blue-700" onclick="addVar()"><i class="bi bi-plus"></i></button>
                    </div>
                </div>
                <div class="mt-3 p-2 bg-gray-100 rounded text-xs">
                    <strong><?= h(t('modal.default_format_title', [], 'Default value format:')) ?></strong><br>
                    <code><?= h(t('modal.default_format_text_example', [], 'plain text')) ?></code> — <?= h(t('modal.default_format_text_desc', [], 'used directly as the default value')) ?><br>
                    <code>date:d F Y</code> — <?= h(t('modal.default_format_date_desc', [], "today's date (PHP format)")) ?><br>
                    <code>$nama_variabel</code> — <?= h(t('modal.default_format_var_desc', [], 'the value of the PHP variable (must be in the whitelist)')) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Field Inspector Modal (Alpine) -->
    <div x-data x-show="$store.modals.fieldInspector" x-transition
         class="fixed inset-0 flex items-center justify-center p-4 z-[100010]"
         style="display:none;">
        <div class="fixed inset-0 bg-black/50" @click="$store.modals.fieldInspector = false"></div>
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto" id="fieldInspectorModal">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gray-100 rounded-t-lg">
                <h6 class="text-base font-semibold"><i class="bi bi-search mr-1"></i><?= h(t('modal.field_inspector_title', [], 'Field Inspector & Migration')) ?></h6>
                <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="$store.modals.fieldInspector = false">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-xs text-gray-500 mb-2"><?= h(t('modal.field_inspector_intro', [], 'Check field usage in existing documents, rename a field with data migration, or clean up orphan data (data whose field no longer exists in the template).')) ?></p>

                <!-- Usage table -->
                <h6 class="text-sm font-bold mt-2"><?= h(t('modal.field_list_title', [], 'Field List')) ?></h6>
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-200 text-xs">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border border-gray-200 px-2 py-1 text-left"><?= h(t('modal.field_col', [], 'Field')) ?></th>
                                <th class="border border-gray-200 px-2 py-1 text-left"><?= h(t('list.col_status', [], 'Status')) ?></th>
                                <th class="border border-gray-200 px-2 py-1 text-left" width="100"><?= h(t('modal.used_in_col', [], 'Used in')) ?></th>
                                <th class="border border-gray-200 px-2 py-1 text-left" width="120"><?= h(t('list.col_actions', [], 'Actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody id="fieldInspectorBody">
                            <tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-gray-500"><?= h(t('modal.loading', [], 'Loading...')) ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Rename section -->
                <h6 class="text-sm font-bold mt-3"><?= h(t('modal.rename_field_title', [], 'Rename Field & Migrate Data')) ?></h6>
                <p class="text-xs text-gray-500 mb-2"><?= h(t('modal.rename_field_intro', [], 'Change the field name in the template AND migrate all field_values in existing documents. This action is irreversible — make sure the new name is correct.')) ?></p>
                <div class="grid grid-cols-12 gap-2 items-end">
                    <div class="col-span-5">
                        <label class="block text-xs text-gray-700 mb-0"><?= h(t('modal.old_name_label', [], 'Old name')) ?></label>
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="renameFieldOld" placeholder="<?= h(t('modal.old_name_placeholder', [], 'old_name')) ?>">
                    </div>
                    <div class="col-span-5">
                        <label class="block text-xs text-gray-700 mb-0"><?= h(t('modal.new_name_label', [], 'New name')) ?></label>
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="renameFieldNew" placeholder="<?= h(t('modal.new_name_placeholder', [], 'new_name')) ?>">
                    </div>
                    <div class="col-span-2">
                        <button type="button" class="w-full inline-flex items-center justify-center px-2 py-1 rounded text-xs bg-yellow-500 text-white hover:bg-yellow-600" onclick="runRenameField()"><i class="bi bi-arrow-right"></i> <?= h(t('modal.rename_button', [], 'Rename')) ?></button>
                    </div>
                </div>
                <small class="text-gray-500 text-xs"><?= h(t('modal.rename_field_hint', [], 'Will: (1) update {{old}} → {{new}} in the editor, (2) migrate the key across all documents')) ?></small>

                <!-- Orphan cleanup -->
                <h6 class="text-sm font-bold mt-3"><?= h(t('modal.cleanup_orphan_title', [], 'Cleanup Orphan Data')) ?></h6>
                <p class="text-xs text-gray-500 mb-2"><?= h(t('modal.cleanup_orphan_intro', [], "Remove keys in a document's field_values that no longer exist in the template. Signature-related keys (_ttd_mode_*, *_qr) are not removed.")) ?></p>
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs border border-red-600 text-red-600 hover:bg-red-50" onclick="runOrphanCleanup()"><i class="bi bi-eraser"></i> <?= h(t('modal.run_cleanup_button', [], 'Run Cleanup')) ?></button>
            </div>
            <div class="flex items-center justify-end px-4 py-3 border-t border-gray-200 gap-2">
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.fieldInspector = false"><?= h(t('actions.close', [], 'Close')) ?></button>
            </div>
        </div>
    </div>

    <!-- Query DB Modal (manage namespaces) - Alpine, static backdrop -->
    <div x-data x-show="$store.modals.tabledb" x-transition
         class="fixed inset-0 flex items-center justify-center p-4 z-[100010]"
         style="display:none;">
        <div class="fixed inset-0 bg-black/50"></div>
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto" id="tabledbModal">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 rounded-t-lg bg-sky-500 text-white">
                <h6 class="text-base font-semibold"><i class="bi bi-database mr-1"></i><span id="tabledbModalTitle"><?= h(t('tabledb.modal_title_add', [], 'Add Query DB')) ?></span></h6>
                <button type="button" class="text-white opacity-80 hover:opacity-100 text-2xl leading-none" @click="$store.modals.tabledb = false">&times;</button>
            </div>
            <div class="p-4">
                <input type="hidden" id="tabledbEditNs" value="">
                <div class="mb-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1"><?= h(t('tabledb.query_name_label', [], 'Query Name (namespace) *')) ?></label>
                    <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="tabledbNs" placeholder="<?= h(t('tabledb.query_name_placeholder', [], 'example: lab, medication, history')) ?>" maxlength="32">
                    <small class="text-gray-500 text-xs"><?= h(t('tabledb.query_name_hint', [], 'Letters, numbers, underscore only. The variable will become {{tabledb.<namespace>.column}}')) ?></small>
                </div>
                <div class="mb-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1"><?= h(t('tabledb.sql_label', [], 'SQL SELECT')) ?></label>
                    <textarea class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1 font-mono" id="tabledbSql" rows="5" placeholder="SELECT kolom1, kolom2, kolom3 FROM tabel WHERE nopen = {nopen}"></textarea>
                    <small class="text-gray-500 text-xs"><?= h(t('tabledb.sql_hint', [], 'Use {nopen}, {norm}, {nama_field} for parameters from the print form. Only SELECT/WITH is allowed, other queries are rejected.')) ?></small>
                </div>
                <div class="mb-2 flex gap-2">
                    <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-blue-600 text-white hover:bg-blue-700" onclick="analyzeTabledbSql()"><i class="bi bi-search mr-1"></i><?= h(t('tabledb.analyze_button', [], 'Analyze (View Columns)')) ?></button>
                    <span id="tabledbAnalyzeStatus" class="text-xs self-center"></span>
                </div>
                <div id="tabledbColumnsBox" class="mb-2" style="display:none;">
                    <label class="block text-xs font-bold text-gray-700 mb-1"><?= h(t('tabledb.available_columns_label', [], 'Available Columns (click to insert into editor)')) ?></label>
                    <div id="tabledbColumns" class="flex flex-wrap gap-1"></div>
                    <small class="text-gray-500 block mt-1 text-xs"><?= h(t('tabledb.columns_tip', [], 'Tip: insert a regular TinyMCE table, then paste/insert the {{tabledb.x.column}} variable in the row to be repeated. Header & footer rows (without variables) stay static.')) ?></small>
                </div>
            </div>
            <div class="flex items-center justify-end px-4 py-3 border-t border-gray-200 gap-2">
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.tabledb = false"><?= h(t('actions.cancel', [], 'Cancel')) ?></button>
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-blue-600 text-white hover:bg-blue-700" onclick="saveTabledbQuery()"><i class="bi bi-check-lg mr-1"></i><?= h(t('tabledb.save_query_button', [], 'Save Query')) ?></button>
            </div>
        </div>
    </div>

    <!-- URL Params Summary Modal (Alpine) -->
    <div x-data x-show="$store.modals.params" x-transition
         class="fixed inset-0 flex items-center justify-center p-4 z-[100010]"
         style="display:none;">
        <div class="fixed inset-0 bg-black/50" @click="$store.modals.params = false"></div>
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto" id="paramsModal">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gray-100 rounded-t-lg">
                <h6 class="text-base font-semibold"><i class="bi bi-link-45deg mr-1"></i><?= h(t('modal.params_title', [], 'Template URL Parameters')) ?></h6>
                <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="$store.modals.params = false">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-xs text-gray-500 mb-2"><?= h(t('modal.params_intro', [], 'Full URL you can send to the print page. Replace the <...> values with the data you want to prefill.')) ?></p>
                <div class="flex mb-3">
                    <textarea id="paramsUrlOutput" class="flex-1 rounded-l border border-gray-300 shadow-sm text-xs px-2 py-1 font-mono" rows="4" readonly></textarea>
                    <button class="inline-flex items-center px-3 py-1 rounded-r border border-blue-600 text-blue-600 hover:bg-blue-50" type="button" onclick="copyParamsUrl()"><i class="bi bi-clipboard"></i></button>
                </div>
                <h6 class="text-sm font-bold"><?= h(t('modal.params_list_title', [], 'Parameter List')) ?></h6>
                <div id="paramsList" class="text-xs"></div>
            </div>
            <div class="flex items-center justify-end px-4 py-3 border-t border-gray-200 gap-2">
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.params = false"><?= h(t('actions.close', [], 'Close')) ?></button>
            </div>
        </div>
    </div>

    <?= \Ezdoc\UI\Slot::render('designer:modals-extra', ['template' => $template ?? null]) ?>

    <!-- JS-triggered slots (Alpine event bridges) -->
    <div x-data @ezdoc:before-save.window="/* consumer hook: see designer:save-hook-pre */">
        <?= \Ezdoc\UI\Slot::render('designer:save-hook-pre', ['template' => $template ?? null]) ?>
    </div>
    <div x-data @ezdoc:after-save.window="/* consumer hook: see designer:save-hook-post */">
        <?= \Ezdoc\UI\Slot::render('designer:save-hook-post', ['template' => $template ?? null]) ?>
    </div>
    <div x-data @ezdoc:field-context.window="/* consumer right-click field */">
        <?= \Ezdoc\UI\Slot::render('designer:field-context-menu', ['template' => $template ?? null]) ?>
    </div>
    <div x-data @ezdoc:ttd-context.window="/* consumer right-click ttd */">
        <?= \Ezdoc\UI\Slot::render('designer:ttd-context-menu', ['template' => $template ?? null]) ?>
    </div>
    <div x-data @ezdoc:materai-context.window="/* consumer right-click materai */">
        <?= \Ezdoc\UI\Slot::render('designer:materai-context-menu', ['template' => $template ?? null]) ?>
    </div>

    <?php endif; ?>

    <script>
        // Alpine store — global modal state (must init before Alpine boots)
        document.addEventListener('alpine:init', () => {
            Alpine.store('modals', {
                varManager: false,
                fieldInspector: false,
                tabledb: false,
                params: false,
                verifyPreview: false,
                open(name) { this[name] = true; },
                close(name) { this[name] = false; }
            });
        });
        // Helper: allow legacy JS calls to open/close modals without touching Alpine directly
        function openAppModal(name) {
            if (window.Alpine && window.Alpine.store && window.Alpine.store('modals')) {
                window.Alpine.store('modals').open(name);
            } else {
                // Alpine not yet booted — wait
                document.addEventListener('alpine:initialized', () => {
                    window.Alpine.store('modals').open(name);
                }, { once: true });
            }
        }
        function closeAppModal(name) {
            if (window.Alpine && window.Alpine.store && window.Alpine.store('modals')) {
                window.Alpine.store('modals').close(name);
            }
        }
    </script>
    <script>
        // v0.9.12 Phase 2 — floating elements initial state (from server-side JSON column)
        window.__EZDOC_FLOATING_INIT = <?= $floatingElementsJson ?: '[]' ?>;
    </script>
    <?php include __DIR__ . '/../_partials/designer_scripts.php'; ?>

    <?php include __DIR__ . '/../_partials/dialog_helper.php'; ?>
<?php if (!$__ezdoc_isFragment): ?>
</body>
</html>
<?php endif; ?>
