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
    // Legacy monolith path: bootstrap + koneksi.php sudah loaded upstream.
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

    // v0.9.12 sidecar rehydration — kalau ada floating_elements JSON, inject
    // markers ke template_html supaya editor + rendering pipeline unchanged.
    // Backward-compat: legacy rows dgn floating markers still in HTML tetap works
    // (floating_elements NULL → no injection, content HTML retains markers as-is).
    if (!empty($template['floating_elements'])) {
        $floating = \Ezdoc\Template\FloatingExtractor::fromJson($template['floating_elements']);
        if (!empty($floating)) {
            $template['template_html'] = \Ezdoc\Template\FloatingInjector::inject(
                (string) $template['template_html'],
                $floating
            );
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
        // Config-driven endpoint URLs (read from data-attr injected server-side).
        // Fallback to empty object supaya standalone rendering (tanpa root wrapper) tetap boot.
        // spec: ezdoc-spec/openapi.yaml
        const EZDOC_URLS = (() => {
            try {
                const el = document.getElementById('ezdocDesignerRoot');
                if (el && el.dataset.ezdocUrls) return JSON.parse(el.dataset.ezdocUrls);
            } catch (e) { console.warn('ezdoc URLs parse fail:', e); }
            return {};
        })();

        // i18n dictionary (read from data-attr injected server-side, mirrors EZDOC_URLS above).
        // spec: docs/I18N.md
        const EZDOC_I18N = (() => {
            try {
                const el = document.getElementById('ezdocDesignerRoot');
                if (el && el.dataset.ezdocI18n) return JSON.parse(el.dataset.ezdocI18n);
            } catch (e) { console.warn('ezdoc i18n parse fail:', e); }
            return {};
        })();

        // Translate a dot-notation key against EZDOC_I18N, with {param} interpolation.
        // Mirrors Ezdoc\UI\Config's own dot-path traversal (PHP side) so both stay in sync.
        // 3rd arg `fallback` mirrors PHP Translator::t()'s $default — a missing/mistyped
        // key (or EZDOC_I18N not yet populated, e.g. list-mode pages without #ezdocDesignerRoot)
        // degrades to the original Indonesian copy instead of a raw dotted key.
        function t(key, params, fallback) {
            params = params || {};
            let ref = EZDOC_I18N;
            const segs = key.split('.');
            for (let i = 0; i < segs.length; i++) {
                if (ref && typeof ref === 'object' && segs[i] in ref) { ref = ref[segs[i]]; }
                else {
                    console.warn('[ezdoc:i18n] missing key', key);
                    ref = fallback !== undefined && fallback !== null ? fallback : key;
                    break;
                }
            }
            if (typeof ref !== 'string') { console.warn('[ezdoc:i18n] non-string value', key); ref = fallback !== undefined && fallback !== null ? fallback : key; }
            return ref.replace(/\{(\w+)\}/g, (m, name) =>
                Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : m
            );
        }

        // Paper sizes in mm
        const PAPER_SIZES = {
            'A4': { width: 210, height: 297 },
            'A5': { width: 148, height: 210 },
            'Letter': { width: 216, height: 279 },
            'Legal': { width: 216, height: 356 },
            'F4': { width: 215, height: 330 }
        };

        let configTtd = [];
        let configMateraiList = []; // list of materai placeholders {id, label, mode (upload/kosong), posMode, posX, posY}
        // Verify config: { show_patient: bool, custom_fields: [{key,label}...] }
        let verifyConfig = {
            show_patient: true,   // default ON (kalau doc_scope=patient, ini yg menang)
            custom_fields: []
        };
        // Access config: RBAC per-template (mode + create/edit/lock/delete roles+users)
        // Format: { mode: 'strict'|'permissive', create: {roles, users}, edit: {}, lock: {}, delete: {} }
        // Backward compat: null / empty roles+users = allow all (v2 behavior).
        // Note: kalau delete kosong, backend fallback ke superadmin-only (bukan allow-all)
        // supaya delete tetap aman by default (delete = destructive action).
        let accessConfig = {
            mode: 'strict',
            create: { roles: [], users: [] },
            edit: { roles: [], users: [] },
            lock: { roles: [], users: [] },
            delete: { roles: [], users: [] }
        };
        let configHeader = {
            logos: {},
            logoSizes: {},
            paperSize: 'A4',
            orientation: 'portrait',
            customWidth: 210,
            customHeight: 297,
            padding: { top: 20, right: 20, bottom: 20, left: 20 },
            tableDbQueries: {} // namespace -> { sql, columns, params }
        };

        <?php if ($template): ?>
        // spec: ezdoc-spec/schemas/template.json — template.config_* JSON columns
        try {
            configTtd = <?= $template['config_ttd'] ?: '[]' ?>;
            const savedHeader = <?= $template['config_header'] ?: '{}' ?>;
            console.log('Loaded savedHeader from DB:', savedHeader);
            if (savedHeader.logos) configHeader.logos = savedHeader.logos;
            if (savedHeader.logoSizes) configHeader.logoSizes = savedHeader.logoSizes;
            if (savedHeader.paperSize) configHeader.paperSize = savedHeader.paperSize;
            if (savedHeader.orientation) configHeader.orientation = savedHeader.orientation;
            if (savedHeader.customWidth) configHeader.customWidth = savedHeader.customWidth;
            if (savedHeader.customHeight) configHeader.customHeight = savedHeader.customHeight;
            if (savedHeader.padding) configHeader.padding = savedHeader.padding;
            // Legacy: configHeader.dynamicTables removed; old data ignored
            if (savedHeader.tableDbQueries) configHeader.tableDbQueries = savedHeader.tableDbQueries;
            // Migrate old format
            if (savedHeader.logoKiri) configHeader.logos['logo_kiri'] = savedHeader.logoKiri;
            if (savedHeader.logoKanan) configHeader.logos['logo_kanan'] = savedHeader.logoKanan;
            console.log('Loaded configHeader:', configHeader);
            // Load verify_config kalau ada
            <?php if (!empty($template['verify_config'])): ?>
            try {
                const savedVerify = <?= $template['verify_config'] ?>;
                if (savedVerify && typeof savedVerify === 'object') {
                    if (typeof savedVerify.show_patient === 'boolean') verifyConfig.show_patient = savedVerify.show_patient;
                    if (Array.isArray(savedVerify.custom_fields)) verifyConfig.custom_fields = savedVerify.custom_fields;
                }
            } catch(e) { console.warn('verify_config parse fail:', e); }
            <?php endif; ?>
            // Load access_config (RBAC) kalau ada
            <?php if (!empty($template['access_config'])): ?>
            try {
                const savedAccess = <?= $template['access_config'] ?>;
                if (savedAccess && typeof savedAccess === 'object') {
                    if (savedAccess.mode === 'strict' || savedAccess.mode === 'permissive') accessConfig.mode = savedAccess.mode;
                    for (const action of ['create', 'edit', 'lock', 'delete']) {
                        if (savedAccess[action] && typeof savedAccess[action] === 'object') {
                            accessConfig[action].roles = Array.isArray(savedAccess[action].roles) ? savedAccess[action].roles : [];
                            accessConfig[action].users = Array.isArray(savedAccess[action].users) ? savedAccess[action].users : [];
                        }
                    }
                }
            } catch(e) { console.warn('access_config parse fail:', e); }
            <?php endif; ?>
        } catch(e) { console.error('Error loading config:', e); }
        <?php endif; ?>

        // Escape user-supplied strings before injecting into innerHTML
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
 
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const isErr = type === 'error';
            const toast = document.createElement('div');
            const color = isErr
                ? 'bg-red-600 border-l-4 border-red-800 text-white font-semibold'
                : 'bg-green-600 border-l-4 border-green-800 text-white';
            toast.className = `p-4 rounded-lg mb-2 flex items-start justify-between shadow-xl min-w-[280px] max-w-[420px] ${color}`;
            const icon = document.createElement('span');
            icon.className = 'mr-2 text-lg leading-none';
            icon.innerHTML = isErr ? '!' : 'OK';
            const msgSpan = document.createElement('span');
            msgSpan.className = 'flex-1';
            msgSpan.innerHTML = message;
            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'ml-4 text-current opacity-80 hover:opacity-100 text-xl leading-none';
            closeBtn.innerHTML = '&times;';
            closeBtn.onclick = () => toast.remove();
            const msgWrap = document.createElement('div');
            msgWrap.className = 'flex items-start';
            msgWrap.appendChild(icon);
            msgWrap.appendChild(msgSpan);
            toast.appendChild(msgWrap);
            toast.appendChild(closeBtn);
            container.appendChild(toast);
            // Error toast stays 8s (longer so user notices); success 3s.
            setTimeout(() => toast.remove(), isErr ? 8000 : 3000);
        }

        // ================== QUERY DB (Tabledb) Manager ==================
        function openTabledbModal(editNs) {
            const titleEl = document.getElementById('tabledbModalTitle');
            const nsInput = document.getElementById('tabledbNs');
            const sqlInput = document.getElementById('tabledbSql');
            const editIdInput = document.getElementById('tabledbEditNs');
            const colsBox = document.getElementById('tabledbColumnsBox');
            const colsList = document.getElementById('tabledbColumns');
            const status = document.getElementById('tabledbAnalyzeStatus');
            status.textContent = '';

            if (editNs && configHeader.tableDbQueries && configHeader.tableDbQueries[editNs]) {
                const q = configHeader.tableDbQueries[editNs];
                titleEl.textContent = t('tabledb.modal_title_edit', {ns: editNs}, 'Edit Query: {ns}');
                nsInput.value = editNs;
                nsInput.disabled = true;
                sqlInput.value = q.sql || '';
                editIdInput.value = editNs;
                if (Array.isArray(q.columns) && q.columns.length) {
                    renderTabledbColumns(editNs, q.columns);
                    colsBox.style.display = 'block';
                } else {
                    colsBox.style.display = 'none';
                }
            } else {
                titleEl.textContent = t('tabledb.modal_title_add', {}, 'Add Query DB');
                nsInput.value = '';
                nsInput.disabled = false;
                sqlInput.value = '';
                editIdInput.value = '';
                colsBox.style.display = 'none';
                colsList.innerHTML = '';
            }
            openAppModal('tabledb');
        }

        async function analyzeTabledbSql() {
            const sql = (document.getElementById('tabledbSql').value || '').trim();
            const ns = (document.getElementById('tabledbNs').value || '').trim() || 'default';
            const status = document.getElementById('tabledbAnalyzeStatus');
            const colsBox = document.getElementById('tabledbColumnsBox');
            if (!sql) { status.textContent = t('tabledb.sql_empty_status', {}, 'SQL is empty'); status.className = 'small text-danger align-self-center'; return; }
            status.textContent = t('tabledb.analyzing_status', {}, 'Analyzing...');
            status.className = 'small text-muted align-self-center';
            try {
                const fd = new FormData();
                fd.append('ajax', '1');
                fd.append('action', 'analyze_query');
                fd.append('query', sql);
                // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1analyze_query
                const r = await fetch(EZDOC_URLS.analyze || '', { method: 'POST', body: fd });
                const data = await r.json();
                if (data.success && Array.isArray(data.columns)) {
                    const cols = data.columns.map(c => c.name);
                    renderTabledbColumns(ns, cols);
                    colsBox.style.display = 'block';
                    status.textContent = '✓ ' + t('tabledb.columns_found_status', {count: cols.length}, '{count} columns found');
                    status.className = 'small text-success align-self-center';
                } else {
                    status.textContent = '✗ ' + (data.message || t('tabledb.analyze_failed_status', {}, 'Analysis failed'));
                    status.className = 'small text-danger align-self-center';
                }
            } catch (e) {
                status.textContent = '✗ ' + t('tabledb.network_error_status', {message: e.message}, 'Network error: {message}');
                status.className = 'small text-danger align-self-center';
            }
        }

        function renderTabledbColumns(ns, columns) {
            const colsList = document.getElementById('tabledbColumns');
            const safeNs = (ns || 'default').replace(/[^a-zA-Z0-9_]/g, '');
            colsList.innerHTML = (columns || []).map(c => {
                const safeCol = String(c).replace(/[^a-zA-Z0-9_]/g, '');
                if (!safeCol) return '';
                const tag = '{{tabledb.' + safeNs + '.' + safeCol + '}}';
                return `<button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs border border-cyan-500 text-cyan-600 hover:bg-cyan-50" onclick="insertTabledbVar('${tag}')" title="${t('tabledb.insert_column_title', {}, 'Click to insert into editor')}">${escapeHtml(tag)}</button>`;
            }).join('');
        }

        function insertTabledbVar(tag) {
            const editor = tinymce.get('editor');
            if (!editor) return;
            editor.execCommand('mceInsertContent', false, tag);
            showToast(t('toast.variable_inserted', {tag: tag}, 'Variable inserted: {tag}'));
        }

        async function saveTabledbQuery() {
            let ns = (document.getElementById('tabledbNs').value || '').trim();
            const sql = (document.getElementById('tabledbSql').value || '').trim();
            const editNs = document.getElementById('tabledbEditNs').value;
            if (!ns) { showToast(t('toast.query_name_required', {}, 'Query name (namespace) is required'), 'error'); return; }
            ns = ns.replace(/[^a-zA-Z0-9_]/g, '');
            if (!ns) { showToast(t('toast.namespace_invalid_chars', {}, 'Namespace can only contain letters/numbers/underscore'), 'error'); return; }
            if (!sql) { showToast(t('toast.sql_empty', {}, 'SQL is empty'), 'error'); return; }

            // Get columns currently rendered (if user analyzed)
            const colButtons = document.querySelectorAll('#tabledbColumns button');
            const columns = Array.from(colButtons).map(b => {
                const m = b.textContent.match(/\.([a-zA-Z_]\w*)\}\}$/);
                return m ? m[1] : null;
            }).filter(x => x);

            // Prevent rename collision (if not editing existing)
            if (!editNs && configHeader.tableDbQueries && configHeader.tableDbQueries[ns]) {
                if (!(await ezdocConfirm(t('confirm.namespace_override', {ns: ns}, 'Namespace "{ns}" already exists. Override?'), { title: 'Namespace Exists', variant: 'warning', confirmText: 'Override' }))) return;
            }

            configHeader.tableDbQueries[ns] = { sql: sql, columns: columns };
            renderTabledbList();
            showToast(t('toast.query_saved', {ns: ns}, 'Query "{ns}" saved'));
            closeAppModal('tabledb');
        }

        async function deleteTabledbQuery(ns) {
            if (!(await ezdocConfirm(t('confirm.delete_query', {ns: ns}, 'Delete query "{ns}"? Variables already used in the editor will show as "—" when printed.'), { title: 'Delete Query', variant: 'danger', confirmText: 'Delete' }))) return;
            if (configHeader.tableDbQueries) delete configHeader.tableDbQueries[ns];
            renderTabledbList();
            showToast(t('toast.query_deleted', {ns: ns}, 'Query "{ns}" deleted'));
        }

        function renderTabledbList() {
            const list = document.getElementById('tabledbList');
            const empty = document.getElementById('tabledbEmpty');
            const countBadge = document.getElementById('tabledbCount');
            if (!list) return;
            const queries = configHeader.tableDbQueries || {};
            const keys = Object.keys(queries);
            if (countBadge) countBadge.textContent = keys.length;
            if (keys.length === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';
            list.innerHTML = keys.map(ns => {
                const q = queries[ns] || {};
                const cols = Array.isArray(q.columns) ? q.columns : [];
                const colChips = cols.slice(0, 8).map(c => {
                    const tag = '{{tabledb.' + ns + '.' + c + '}}';
                    return `<button type="button" class="inline-flex items-center py-0 px-1 mb-1 rounded border border-cyan-500 text-cyan-600 hover:bg-cyan-50" style="font-size:10px;" onclick="insertTabledbVar('${tag}')" title="${t('tabledb.insert_column_title_named', {tag: escapeHtml(tag)}, 'Insert {tag}')}">${escapeHtml(c)}</button>`;
                }).join(' ');
                const moreCount = cols.length > 8 ? ` <small class="text-gray-500">${t('tabledb.more_columns_label', {count: cols.length - 8}, '+{count} more')}</small>` : '';
                return `
                <div class="mb-2 p-2 bg-gray-100 rounded" style="border-left: 3px solid #0e7490;">
                    <div class="flex justify-between items-start mb-1">
                        <div>
                            <strong class="text-xs" style="color:#0e7490;">${escapeHtml(ns)}</strong>
                            <div class="text-gray-500" style="font-size:10px;">${t('tabledb.column_count_label', {count: cols.length}, '{count} columns')}</div>
                        </div>
                        <div class="inline-flex">
                            <button type="button" class="inline-flex items-center py-0 px-1 rounded-l border border-gray-500 text-gray-700 hover:bg-gray-50" onclick="openTabledbModal('${ns}')" title="${t('actions.edit', {}, 'Edit')}"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="inline-flex items-center py-0 px-1 rounded-r border border-red-600 text-red-600 hover:bg-red-50" onclick="deleteTabledbQuery('${ns}')" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1">${colChips}${moreCount}</div>
                </div>`;
            }).join('');
        }

        // Default Vars Manager
        function openVarManager() {
            openAppModal('varManager');
            loadVars();
        }

        function loadVars() {
            const body = document.getElementById('varListBody');
            body.innerHTML = `<tr><td colspan="3" class="border border-gray-200 px-2 py-1 text-center text-gray-500">${t('modal.loading', {}, 'Loading...')}</td></tr>`;
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'list_vars');
            // spec: ezdoc-spec/openapi.yaml#/paths/~1default_vars~1list
            fetch(EZDOC_URLS.listVars || '', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.vars.length) {
                        body.innerHTML = `<tr><td colspan="3" class="border border-gray-200 px-2 py-1 text-center text-gray-500">${t('modal.no_vars_yet', {}, 'No variables yet')}</td></tr>`;
                        return;
                    }
                    body.innerHTML = data.vars.map(v => `
                        <tr>
                            <td class="border border-gray-200 px-2 py-1"><code>$${escapeHtml(v.var_name)}</code></td>
                            <td class="border border-gray-200 px-2 py-1 text-xs">${escapeHtml(v.description || '')}</td>
                            <td class="border border-gray-200 px-2 py-1"><button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="deleteVar(${v.id})"><i class="bi bi-trash"></i></button></td>
                        </tr>
                    `).join('');
                });
        }

        function addVar() {
            const name = document.getElementById('newVarName').value.trim();
            const desc = document.getElementById('newVarDesc').value.trim();
            if (!name) return;
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'add_var');
            fd.append('var_name', name.replace(/^\$/, ''));
            fd.append('description', desc);
            // spec: ezdoc-spec/openapi.yaml#/paths/~1default_vars~1add
            fetch(EZDOC_URLS.addVar || '', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('newVarName').value = '';
                        document.getElementById('newVarDesc').value = '';
                        loadVars();
                    } else {
                        ezdocAlert(data.message || t('alert.generic_failed', {}, 'Failed'), { title: 'Error', variant: 'error' });
                    }
                });
        }

        async function deleteVar(id) {
            if (!(await ezdocConfirm(t('confirm.delete_variable', {}, 'Delete this variable from the whitelist?'), { title: 'Delete Variable', variant: 'danger', confirmText: 'Delete' }))) return;
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'delete_var');
            fd.append('var_id', id);
            // spec: ezdoc-spec/openapi.yaml#/paths/~1default_vars~1delete
            fetch(EZDOC_URLS.deleteVar || '', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(() => loadVars());
        }

        // Update page size and padding
        function updatePageSize() {
            const paperSize = document.getElementById('paperSize')?.value || 'A4';
            const orientation = document.querySelector('input[name="orientation"]:checked')?.value || 'portrait';
            const customPanel = document.getElementById('customSizePanel');

            // Show/hide custom size panel
            if (customPanel) {
                customPanel.style.display = paperSize === 'Custom' ? 'flex' : 'none';
            }

            const ptVal = document.getElementById('padTop')?.value;
            const prVal = document.getElementById('padRight')?.value;
            const pbVal = document.getElementById('padBottom')?.value;
            const plVal = document.getElementById('padLeft')?.value;

            const padTop = ptVal !== '' && ptVal !== undefined ? parseInt(ptVal) : 20;
            const padRight = prVal !== '' && prVal !== undefined ? parseInt(prVal) : 20;
            const padBottom = pbVal !== '' && pbVal !== undefined ? parseInt(pbVal) : 20;
            const padLeft = plVal !== '' && plVal !== undefined ? parseInt(plVal) : 20;

            // Get paper dimensions
            let paperWidth, paperHeight;
            if (paperSize === 'Custom') {
                paperWidth = parseInt(document.getElementById('customWidth')?.value) || 210;
                paperHeight = parseInt(document.getElementById('customHeight')?.value) || 297;
                configHeader.customWidth = paperWidth;
                configHeader.customHeight = paperHeight;
            } else {
                const paper = PAPER_SIZES[paperSize];
                paperWidth = paper?.width || 210;
                paperHeight = paper?.height || 297;
            }

            // Swap dimensions for landscape
            if (orientation === 'landscape') {
                [paperWidth, paperHeight] = [paperHeight, paperWidth];
            }

            configHeader.paperSize = paperSize;
            configHeader.orientation = orientation;
            configHeader.padding = { top: padTop, right: padRight, bottom: padBottom, left: padLeft };

            // editorContainer: simplified — TinyMCE widget fills wrapper.
            // Paper visualization diberikan oleh iframe body CSS (Google Docs pattern).
            const container = document.getElementById('editorContainer');
            if (container) {
                container.style.width = '100%';       // fill wrapper (was: paper width)
                container.style.minHeight = '';       // TinyMCE controls height
            }

            // Update TinyMCE iframe body — paper dimensions + padding via inline style.
            const editor = tinymce.get('editor');
            if (editor) {
                const iframe = editor.getContainer().querySelector('iframe');
                if (iframe && iframe.contentDocument) {
                    const body = iframe.contentDocument.body;
                    // Padding = paper margin (dompdf will read same values untuk export)
                    body.style.padding = `${padTop}mm ${padRight}mm ${padBottom}mm ${padLeft}mm`;
                    // max-width = paper width (constrains body to paper card look)
                    body.style.maxWidth = paperWidth + 'mm';
                    // Body min-height = paperHeight mm (match generate .page min-height).
                    //
                    // Setelah `* { box-sizing: border-box }` ditambahkan ke generate.php,
                    // generate `.page { min-height: paperH }` render border-box → total
                    // .page height = paperH mm, content area = paperH - padT - padB.
                    // Editor body dgn box-sizing: border-box + min-height: paperH mm
                    // punya semantic identical → total body = paperH, content area same.
                    //
                    // Sebelumnya editor pakai (paperH + padT + padB) → over-inflated 40mm
                    // (20mm padding × 2), bikin editor visual paper 40mm lebih tinggi dari
                    // generate. Floating element di visual "paper bottom" editor mapped ke
                    // beyond-paper area di generate → coordinate offset.
                    body.style.minHeight = paperHeight + 'mm';
                    // Page break visualization — CSS var yg drive repeating
                    // background-image di content_style. Line muncul di setiap
                    // paperHeight (bukan content area) sesuai actual print page.
                    body.style.setProperty('--ezdoc-page-h', paperHeight + 'mm');
                }

                // TinyMCE widget height = viewport-fill (getEditorHeight).
                const editorContainer = editor.getContainer();
                if (editorContainer) {
                    editorContainer.style.height = getEditorHeight() + 'px';
                }

                // Re-paginate — paper size / padding change → boundary shifts.
                if (typeof repaginateEditorDebounced === 'function') {
                    repaginateEditorDebounced();
                }
            }
        }

        // Load saved settings to form
        function loadPageSettings() {
            const ps = document.getElementById('paperSize');
            if (ps) ps.value = configHeader.paperSize || 'A4';

            // Load orientation
            const orientation = configHeader.orientation || 'portrait';
            const orientRadio = document.getElementById(orientation === 'landscape' ? 'orientLandscape' : 'orientPortrait');
            if (orientRadio) orientRadio.checked = true;

            // Load custom dimensions
            const cw = document.getElementById('customWidth');
            const ch = document.getElementById('customHeight');
            if (cw) cw.value = configHeader.customWidth || 210;
            if (ch) ch.value = configHeader.customHeight || 297;

            // Show custom panel if needed
            const customPanel = document.getElementById('customSizePanel');
            if (customPanel) {
                customPanel.style.display = configHeader.paperSize === 'Custom' ? 'flex' : 'none';
            }

            const pt = document.getElementById('padTop');
            const pr = document.getElementById('padRight');
            const pb = document.getElementById('padBottom');
            const pl = document.getElementById('padLeft');

            if (pt) pt.value = configHeader.padding?.top ?? 20;
            if (pr) pr.value = configHeader.padding?.right ?? 20;
            if (pb) pb.value = configHeader.padding?.bottom ?? 20;
            if (pl) pl.value = configHeader.padding?.left ?? 20;

            updatePageSize();
        }

        // Sync form values to configHeader (called before save)
        function syncPageSettings() {
            const paperSize = document.getElementById('paperSize')?.value || 'A4';
            const orientation = document.querySelector('input[name="orientation"]:checked')?.value || 'portrait';
            const customWidth = parseInt(document.getElementById('customWidth')?.value) || 210;
            const customHeight = parseInt(document.getElementById('customHeight')?.value) || 297;
            const padTop = parseInt(document.getElementById('padTop')?.value) || 0;
            const padRight = parseInt(document.getElementById('padRight')?.value) || 0;
            const padBottom = parseInt(document.getElementById('padBottom')?.value) || 0;
            const padLeft = parseInt(document.getElementById('padLeft')?.value) || 0;

            configHeader.paperSize = paperSize;
            configHeader.orientation = orientation;
            configHeader.customWidth = customWidth;
            configHeader.customHeight = customHeight;
            configHeader.padding = {
                top: padTop,
                right: padRight,
                bottom: padBottom,
                left: padLeft
            };

            console.log('Synced configHeader:', configHeader); // Debug
        }

        // Sync floating positions from DOM styles to data attributes before save
        function syncFloatingPositions() {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const iframeDoc = editor.getDoc();
            if (!iframeDoc) return;

            // Helper: sync 1 element — PRESERVE existing data-pos-x/y kalau inline style kosong.
            // Bug fix: `parseInt('') || 0` sebelumnya menyebabkan floating reset ke pojok kiri atas
            // kalau TinyMCE strip inline style (undo/redo, paste, re-init, dll).
            // Sekarang: cuma overwrite kalau ada valid inline style. Kalau tidak, biarkan data-pos-* apa adanya.
            function syncOne(el) {
                const styleLeft = el.style.left;
                const styleTop = el.style.top;
                if (styleLeft !== '' && styleLeft !== undefined) {
                    const left = parseInt(styleLeft);
                    if (!isNaN(left)) el.setAttribute('data-pos-x', left);
                }
                if (styleTop !== '' && styleTop !== undefined) {
                    const top = parseInt(styleTop);
                    if (!isNaN(top)) el.setAttribute('data-pos-y', top);
                }
            }

            iframeDoc.querySelectorAll(
                '.logo-placeholder.floating, .ttd-placeholder.floating, .qr-placeholder.floating, .materai-placeholder.floating'
            ).forEach(syncOne);

            console.log('Synced floating positions to data attributes (preserved existing on empty style)');
        }

        // Get clean content for save (replace logo images with placeholder text)
        function getCleanContentForSave() {
            const editor = tinymce.get('editor');
            if (!editor) return '';

            let content = editor.getContent();

            // Replace logo images back to placeholder text
            content = content.replace(/<span([^>]*data-logo="([^"]+)"[^>]*)>.*?<\/span>/gs, function(match, attrs, logoName) {
                return `<span${attrs}>[Logo: ${logoName}]</span>`;
            });

            return content;
        }

        // AJAX Save
        // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1save
        async function saveTemplate() {
            const indicator = document.getElementById('saveIndicator');
            indicator.textContent = t('save_status.saving', {}, 'Saving...');
            indicator.className = 'save-indicator saving';

            // Sync form values to configHeader before saving
            syncPageSettings();

            // Sync floating positions from DOM to data attributes
            syncFloatingPositions();

            const editor = tinymce.get('editor');
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'save');
            formData.append('template_id', document.getElementById('templateId').value);
            formData.append('nama_template', document.getElementById('namaTemplate').value);
            formData.append('category', (document.getElementById('templateCategory')?.value || '').trim());
            formData.append('doc_scope', document.getElementById('templateDocScope')?.value || 'patient');
            // Sync verifyConfig terakhir dari UI sebelum save
            onVerifyConfigChange();
            // Filter custom_fields yang key/label kosong sebelum simpan
            const cleanVerify = {
                show_patient: !!verifyConfig.show_patient,
                custom_fields: (verifyConfig.custom_fields || []).filter(f => (f.key || '').trim() !== '')
            };
            formData.append('verify_config', JSON.stringify(cleanVerify));
            // Sync access config UI ke state sebelum save + pack ke FormData
            onAccessConfigChange();
            // Filter: kalau semua roles+users kosong di semua action, kirim null supaya backward compat (allow-all).
            // Note: include 'delete' juga — kalau user set delete tapi biarkan lain kosong, tetap perlu save.
            const hasAnyAccess = ['create', 'edit', 'lock', 'delete'].some(a =>
                (accessConfig[a].roles?.length || 0) + (accessConfig[a].users?.length || 0) > 0
            );
            formData.append('access_config', hasAnyAccess ? JSON.stringify(accessConfig) : 'null');
            formData.append('template_html', getCleanContentForSave()); // Use clean content
            formData.append('config_ttd', JSON.stringify(configTtd));
            formData.append('config_header', JSON.stringify(configHeader));

            // Consumer-extensible: pre-save hook — allow consumers to mutate formData or cancel
            const beforeEvt = new CustomEvent('ezdoc:before-save', { detail: { formData, cancel: false }, cancelable: true });
            window.dispatchEvent(beforeEvt);
            if (beforeEvt.defaultPrevented || (beforeEvt.detail && beforeEvt.detail.cancel)) {
                indicator.textContent = t('save_status.cancelled', {}, 'Cancelled');
                indicator.className = 'save-indicator';
                setTimeout(() => { indicator.textContent = ''; }, 2000);
                return;
            }

            try {
                const saveUrl = EZDOC_URLS.save || window.location.href;
                const resp = await fetch(saveUrl, { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.success) {
                    indicator.textContent = t('save_status.saved', {}, 'Saved');
                    indicator.className = 'save-indicator saved';
                    showToast(data.message);
                    // Reset dirty flag — beforeunload prompt sekarang tidak akan
                    // muncul sampai user edit lagi.
                    if (window.ezdocMarkClean) window.ezdocMarkClean();
                    if (data.id) {
                        // Always sync the hidden input to the latest ID (defensive against stale value)
                        const idInput = document.getElementById('templateId');
                        if (idInput) idInput.value = data.id;
                        // Sync Preview link href so it points to the saved template
                        const btnPrev = document.getElementById('btnPreview');
                        if (btnPrev) {
                            const printBase = EZDOC_URLS.print || '';
                            if (printBase) {
                                btnPrev.href = printBase + (printBase.indexOf('?') !== -1 ? '&' : '?') + 'template_id=' + data.id;
                            } else {
                                btnPrev.style.display = 'none';
                            }
                        }
                        // Update URL only if transitioning from create (no id in URL yet)
                        if (!new URLSearchParams(window.location.search).get('id')) {
                            const editPattern = EZDOC_URLS.edit || '?action=edit&id={id}';
                            history.replaceState(null, '', editPattern.replace('{id}', data.id));
                        }
                    }
                    // Consumer-extensible: post-save hook — inform slots/consumer subscribers
                    window.dispatchEvent(new CustomEvent('ezdoc:after-save', { detail: { data } }));
                } else {
                    indicator.textContent = t('save_status.failed', {}, 'Failed');
                    indicator.className = 'save-indicator';
                    showToast(data.message, 'error');
                }
            } catch (e) {
                indicator.textContent = t('save_status.error', {}, 'Error');
                indicator.className = 'save-indicator';
                showToast(t('toast.save_failed', {message: e.message}, 'Save failed: {message}'), 'error');
            }
            setTimeout(() => { indicator.textContent = ''; }, 2000);
        }

        // Build & show URL parameters summary
        function showParamsSummary() {
            const templateId = document.getElementById('templateId')?.value || '0';
            const editor = tinymce.get('editor');
            const content = editor ? editor.getContent() : '';

            // Collect params with descriptions
            // Each entry: { key, desc, placeholder }
            const params = [];

            // Always-present standard params
            params.push({ key: 'template_id', desc: t('params.template_id_desc', {}, 'Template ID (auto from URL)'), placeholder: templateId });
            params.push({ key: 'norm', desc: t('params.norm_desc', {}, 'Medical Record Number'), placeholder: '<norm>' });
            params.push({ key: 'nopen', desc: t('params.nopen_desc', {}, 'Registration Number'), placeholder: '<nopen>' });
            params.push({ key: 'label', desc: t('params.label_desc', {}, 'Document label (distinguishes documents when nopen is the same)'), placeholder: '<label>' });

            // Scan {{field}} placeholders from template
            const fieldNames = new Set();
            const fieldRegex = /<span[^>]*class="[^"]*field-placeholder[^"]*"[^>]*>\{\{([^}]+)\}\}<\/span>/g;
            let m;
            while ((m = fieldRegex.exec(content)) !== null) fieldNames.add(m[1]);
            // Also bare {{field}} fallback
            const bareRegex = /\{\{([^}]+)\}\}/g;
            while ((m = bareRegex.exec(content)) !== null) fieldNames.add(m[1]);

            fieldNames.forEach(fn => {
                params.push({ key: fn, desc: t('params.field_desc', {name: fn}, 'Field: {name}'), placeholder: '<' + fn + '>' });
            });

            // Scan QR placeholders (data-qr="fieldName")
            const qrRegex = /data-qr="([^"]+)"/g;
            const qrFields = new Set();
            while ((m = qrRegex.exec(content)) !== null) qrFields.add(m[1]);
            qrFields.forEach(qf => {
                if (!fieldNames.has(qf)) {
                    params.push({ key: qf, desc: t('params.qr_field_desc', {name: qf}, 'QR field: {name}'), placeholder: '<' + qf + '>' });
                }
            });

            // TTD-related params from configTtd
            (configTtd || []).forEach(ttd => {
                const nf = ttd.nama_field || ('nama_' + ttd.id);
                const lbl = ttd.label || 'TTD';
                if (!fieldNames.has(nf)) {
                    params.push({ key: nf, desc: t('params.signer_name_desc', {label: lbl}, 'Signer name — {label}'), placeholder: '<nama ' + lbl + '>' });
                }
                const modes = ttd.ttdModes || 'image';
                if (modes.includes('qr')) {
                    params.push({ key: nf + '_qr', desc: t('params.qr_content_desc', {label: lbl}, 'QR content — {label}'), placeholder: '<konten QR ' + lbl + '>' });
                }
            });

            // Build URL — use configured print URL
            const base = EZDOC_URLS.print || '';
            if (!base) {
                ezdocAlert(t('alert.print_endpoint_missing', {}, 'Print/preview endpoint not configured yet. Set urls.print in Config to enable preview.'), { title: 'Not Configured', variant: 'warning' });
                return;
            }
            const qs = params.map(p => encodeURIComponent(p.key) + '=' + encodeURIComponent(p.placeholder)).join('&');
            // If base is a relative filename, resolve against current dir; if it's a full path/URL, use as-is
            let baseUrl;
            if (/^https?:\/\//i.test(base) || base.startsWith('/')) {
                baseUrl = base;
            } else {
                baseUrl = window.location.origin + window.location.pathname.replace(/[^/]+$/, base);
            }
            const url = baseUrl + (baseUrl.indexOf('?') !== -1 ? '&' : '?') + qs;
            document.getElementById('paramsUrlOutput').value = url;

            // Build params list
            const listHtml = params.map(p => `
                <div class="flex border-b border-gray-200 py-1">
                    <code class="mr-2" style="min-width:140px;color:#1e40af;">${escapeHtml(p.key)}</code>
                    <span class="text-gray-500">${escapeHtml(p.desc)}</span>
                </div>
            `).join('');
            document.getElementById('paramsList').innerHTML = listHtml;

            openAppModal('params');
        }

        function copyParamsUrl() {
            const ta = document.getElementById('paramsUrlOutput');
            ta.select();
            ta.setSelectionRange(0, 99999);
            try {
                navigator.clipboard.writeText(ta.value);
                showToast(t('toast.url_copied', {}, 'URL copied'));
            } catch (e) {
                document.execCommand('copy');
                showToast(t('toast.url_copied', {}, 'URL copied'));
            }
        }

        // Collect all field names currently in the template (matches the logic in showParamsSummary)
        function collectTemplateFieldNames() {
            const editor = tinymce.get('editor');
            const content = editor ? editor.getContent() : '';
            const names = new Set();
            // Field placeholders
            const fieldRegex = /<span[^>]*class="[^"]*field-placeholder[^"]*"[^>]*>\{\{([^}]+)\}\}<\/span>/g;
            let m;
            while ((m = fieldRegex.exec(content)) !== null) names.add(m[1]);
            const bareRegex = /\{\{([^}]+)\}\}/g;
            while ((m = bareRegex.exec(content)) !== null) names.add(m[1]);
            // QR placeholders
            const qrRegex = /data-qr="([^"]+)"/g;
            while ((m = qrRegex.exec(content)) !== null) names.add(m[1]);
            // TTD nama_field + <nama>_qr
            (configTtd || []).forEach(ttd => {
                const nf = ttd.nama_field || ('nama_' + ttd.id);
                names.add(nf);
                const modes = ttd.ttdModes || 'image';
                if (modes.includes('qr')) names.add(nf + '_qr');
            });
            return names;
        }

        // Show Field Inspector modal: compare template fields vs documents' fields
        async function showFieldInspector() {
            const templateId = document.getElementById('templateId')?.value || '0';
            if (templateId === '0') {
                ezdocAlert(t('alert.save_template_before_inspect', {}, 'Save the template first before inspecting.'), { title: 'Save Required', variant: 'warning' });
                return;
            }

            const body = document.getElementById('fieldInspectorBody');
            body.innerHTML = `<tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-gray-500">${t('modal.loading', {}, 'Loading...')}</td></tr>`;
            openAppModal('fieldInspector');

            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'field_usage_all');
            fd.append('template_id', templateId);
            // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1field_usage
            const resp = await fetch(EZDOC_URLS.fieldUsage || '', { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.success) {
                body.innerHTML = '<tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-red-600">' + t('modal.load_failed', {message: data.message || 'error'}, 'Failed to load: {message}') + '</td></tr>';
                return;
            }

            const templateFields = collectTemplateFieldNames();
            const fieldCounts = data.fieldCounts || {};
            const totalDocs = data.totalDocs || 0;

            // Union: all fields (template + db)
            const allFields = new Set([...templateFields, ...Object.keys(fieldCounts)]);
            const rows = [];
            [...allFields].sort().forEach(fn => {
                const inTemplate = templateFields.has(fn);
                const usedCount = fieldCounts[fn] || 0;
                let statusBadge = '';
                if (inTemplate && usedCount > 0) {
                    statusBadge = `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">${t('modal.status_active', {}, 'Active')}</span>`;
                } else if (inTemplate && usedCount === 0) {
                    statusBadge = `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">${t('modal.status_unused', {}, 'Not used yet')}</span>`;
                } else {
                    statusBadge = `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">${t('modal.status_orphan', {}, 'Orphan')}</span>`;
                }
                rows.push(`
                    <tr>
                        <td class="border border-gray-200 px-2 py-1"><code>${escapeHtml(fn)}</code></td>
                        <td class="border border-gray-200 px-2 py-1">${statusBadge}</td>
                        <td class="border border-gray-200 px-2 py-1">${t('modal.used_in_docs_count', {used: usedCount, total: totalDocs}, '{used} / {total} doc(s)')}</td>
                        <td class="border border-gray-200 px-2 py-1">
                            ${inTemplate ? `<button class="inline-flex items-center py-0 px-1 rounded border border-yellow-500 text-yellow-600 hover:bg-yellow-50" onclick="prefillRename('${escapeHtml(fn)}')" title="${t('modal.rename_button', {}, 'Rename')}"><i class="bi bi-arrow-right"></i></button>` : ''}
                        </td>
                    </tr>
                `);
            });
            body.innerHTML = rows.length ? rows.join('') : `<tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-gray-500">${t('modal.no_field_yet', {}, 'No fields yet')}</td></tr>`;
        }

        function prefillRename(name) {
            document.getElementById('renameFieldOld').value = name;
            document.getElementById('renameFieldNew').focus();
        }

        // Rename field: update editor content + migrate all docs
        async function runRenameField() {
            const templateId = document.getElementById('templateId')?.value || '0';
            const oldName = document.getElementById('renameFieldOld').value.trim();
            const newName = document.getElementById('renameFieldNew').value.trim();
            if (templateId === '0') { ezdocAlert(t('alert.save_template_first', {}, 'Save the template first'), { title: 'Save Required', variant: 'warning' }); return; }
            if (!oldName || !newName || oldName === newName) { ezdocAlert(t('alert.rename_names_required', {}, 'Old name and new name must be different'), { title: 'Invalid Input', variant: 'warning' }); return; }
            if (!(await ezdocConfirm(t('confirm.rename_field', {oldName: oldName, newName: newName}, 'Rename field "{oldName}" → "{newName}" in the template + migrate data across all documents? This action cannot be undone.'), { title: 'Rename Field', variant: 'danger', confirmText: 'Rename' }))) return;

            // Update editor content first
            const editor = tinymce.get('editor');
            if (editor) {
                let content = editor.getContent();
                const oldEsc = oldName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                // Field placeholders
                content = content.replace(new RegExp(`\\{\\{${oldEsc}\\}\\}`, 'g'), `{{${newName}}}`);
                // QR data-qr attribute
                content = content.replace(new RegExp(`data-qr="${oldEsc}"`, 'g'), `data-qr="${newName}"`);
                // TTD nama-field attribute
                content = content.replace(new RegExp(`data-nama-field="${oldEsc}"`, 'g'), `data-nama-field="${newName}"`);
                editor.setContent(content);
                // Update configTtd
                (configTtd || []).forEach(ttd => { if (ttd.nama_field === oldName) ttd.nama_field = newName; });
                scanFieldPlaceholders();
                scanTtdPlaceholders();
            }

            // Save template with new names
            await saveTemplate();

            // Migrate document data
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'rename_field');
            fd.append('template_id', templateId);
            fd.append('old_name', oldName);
            fd.append('new_name', newName);
            // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1rename_field
            const resp = await fetch(EZDOC_URLS.renameField || '', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                showToast(t('toast.rename_success', {count: data.updated}, 'Rename successful. {count} document(s) updated.'));
                document.getElementById('renameFieldOld').value = '';
                document.getElementById('renameFieldNew').value = '';
                showFieldInspector();
            } else {
                ezdocAlert(t('alert.migrate_failed', {message: data.message || 'error'}, 'Failed to migrate data: {message}'), { title: 'Migration Failed', variant: 'error' });
            }
        }

        // Cleanup orphan data across all documents
        async function runOrphanCleanup() {
            const templateId = document.getElementById('templateId')?.value || '0';
            if (templateId === '0') { alert(t('alert.save_template_first', {}, 'Save the template first')); return; }
            if (!(await ezdocConfirm(t('confirm.cleanup_orphans', {}, 'Delete all field_values keys that no longer exist in the template?'), { title: 'Cleanup Orphans', variant: 'warning', confirmText: 'Cleanup' }))) return;

            const validFields = [...collectTemplateFieldNames()].join(',');
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'cleanup_orphans');
            fd.append('template_id', templateId);
            fd.append('valid_fields', validFields);
            // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1cleanup_orphans
            const resp = await fetch(EZDOC_URLS.cleanupOrphans || '', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                showToast(t('toast.cleanup_done', {count: data.updated, keys: data.removedKeys.join(', ') || '-'}, 'Cleanup done. {count} document(s) updated. Keys removed: {keys}'));
                showFieldInspector();
            } else {
                ezdocAlert(t('alert.generic_failed_message', {message: data.message || 'error'}, 'Failed: {message}'), { title: 'Error', variant: 'error' });
            }
        }

        // Lock protection: before save, detect destructive changes if template is locked
        <?php if (!empty($template['is_locked'])): ?>
        (function wrapSaveForLockProtection() {
            if (typeof saveTemplate !== 'function') return;
            // Snapshot of initial field names (taken after scan on load)
            let initialFields = null;
            setTimeout(() => { initialFields = collectTemplateFieldNames(); }, 800);

            const origSave = saveTemplate;
            window.saveTemplate = async function() {
                if (initialFields) {
                    const current = collectTemplateFieldNames();
                    const removed = [...initialFields].filter(f => !current.has(f));
                    if (removed.length) {
                        const ok = await ezdocConfirm(t('confirm.locked_fields_change', {fields: removed.join('\n')}, 'This template is LOCKED. The following fields will disappear/be renamed:\n\n{fields}\n\nContinue? (recommended: use "Inspect Fields" to rename/migrate first)'), { title: 'Locked Template Warning', variant: 'danger', confirmText: 'Continue Anyway' });
                        if (!ok) return;
                    }
                }
                return origSave.apply(this, arguments);
            };
        })();
        <?php endif; ?>

        // TinyMCE with dynamic size
        <?php if ($action !== 'list'): ?>
        // Calculate initial height based on paper size
        function getEditorHeight() {
            // Sizing strategy (Google Docs pattern):
            //   TinyMCE fills editorWrapper = window height minus top bar.
            //   Paper visualization + backdrop di dalam iframe body CSS (bukan wrapper).
            //   Backdrop gray natural extend ke bawah paper tanpa artificial gap.
            const wrapper = document.getElementById('editorWrapper');
            const topBar = wrapper?.previousElementSibling; // dark top bar
            const topH = topBar ? topBar.offsetHeight : 60;
            return Math.max(400, window.innerHeight - topH);
        }

        /* ===== VIRTUAL PAGINATION (v0.9.13) =====
           Bootstrap Ezdoc\UI\PaginationJs — inject spacer divs at page boundaries
           di TinyMCE editor body supaya content flow respect margin di setiap
           physical page break. Config di-override dynamically (see repaginateEditor
           below) karena paper size / padding user-adjustable via toolbar. */
        <?= \Ezdoc\UI\PaginationJs::render(297.0, 20.0, 20.0) ?>

        /* Read current paper geometry from designer toolbar inputs, update
           EzdocPagination.config, then paginate editor body. Called on:
           - editor init
           - editor NodeChange/KeyUp/SetContent (debounced)
           - updatePageSize (paper size / padding change) */
        function repaginateEditor() {
            const editor = window.tinymce && tinymce.get('editor');
            if (!editor || !window.EzdocPagination) return;
            const iframe = editor.getContainer().querySelector('iframe');
            if (!iframe || !iframe.contentDocument) return;
            const body = iframe.contentDocument.body;
            if (!body) return;

            const ptEl = document.getElementById('padTop');
            const pbEl = document.getElementById('padBottom');
            const padT = ptEl && ptEl.value !== '' ? parseFloat(ptEl.value) : 20;
            const padB = pbEl && pbEl.value !== '' ? parseFloat(pbEl.value) : 20;

            let paperH = 297;
            const psEl = document.getElementById('paperSize');
            const paperSize = psEl ? psEl.value : 'A4';
            const orientation = document.querySelector('input[name="orientation"]:checked');
            const orient = orientation ? orientation.value : 'portrait';
            if (paperSize === 'Custom') {
                paperH = parseFloat(document.getElementById('customHeight')?.value) || 297;
            } else if (typeof PAPER_SIZES !== 'undefined' && PAPER_SIZES[paperSize]) {
                paperH = PAPER_SIZES[paperSize].height;
            }
            if (orient === 'landscape') {
                const w = paperSize === 'Custom'
                    ? (parseFloat(document.getElementById('customWidth')?.value) || 210)
                    : (PAPER_SIZES[paperSize]?.width || 210);
                paperH = w;
            }

            window.EzdocPagination.config.paperHeightMm = paperH;
            window.EzdocPagination.config.padTopMm = padT;
            window.EzdocPagination.config.padBottomMm = padB;
            window.EzdocPagination.paginate(body);
        }
        const repaginateEditorDebounced = (function() {
            let t = null;
            return function() {
                clearTimeout(t);
                t = setTimeout(repaginateEditor, 250);
            };
        })();

        tinymce.init({
            selector: '#editor',
            height: getEditorHeight(),
            width: '100%',
            menubar: false,
            statusbar: false,
            resize: false,
            // Sticky toolbar — tetap visible saat scroll editor content (Google Docs
            // pattern). Sticky within scrolling ancestor (editorWrapper).
            // Sticky toolbar DISABLED — sebelumnya `toolbar_sticky: true` + `offset: 0`
            // bikin toolbar jump ke top viewport setelah insert floating/custom
            // element (DOM change triggers layout recalc → sticky reposition ke Y=0
            // → menutupi header custom (nama template, tombol simpan, dll)).
            // Non-sticky = toolbar stays with editor container, no overlap risk.
            toolbar_sticky: false,
            // Plugins — full feature set (skip emoticons/media/mediaembed per user):
            //   autosave     : recover unsaved content after crash/close
            //   directionality: LTR/RTL toggle untuk multi-lang templates
            //   importcss    : import consumer app CSS (brand consistency)
            //   quickbars    : floating selection toolbar (Notion pattern)
            plugins: 'advlist anchor autolink autosave charmap code directionality fullscreen help hr image importcss insertdatetime lists link nonbreaking pagebreak preview quickbars searchreplace table visualblocks visualchars wordcount',
            // Autosave — critical UX (recovers content kalau browser crash/close).
            // Prefix pakai template ID supaya per-template autosave.
            // NOTE: `autosave_ask_before_unload` sengaja OFF — TinyMCE builtin
            // over-triggers meski nothing changed. Kita implement custom
            // beforeunload di window scope (lihat setelah tinymce.init) yg
            // cek editor.isDirty() dulu sebelum block navigation.
            autosave_ask_before_unload: false,
            autosave_interval: '30s',
            autosave_prefix: 'ezdoc-tpl-{path}{query}-',
            autosave_restore_when_empty: false,
            autosave_retention: '30m',
            // Selection quickbar — floating toolbar saat select text (Medium/Notion).
            // Only shows on selection, unobtrusive default state.
            quickbars_selection_toolbar: 'bold italic underline | forecolor backcolor | link | h2 h3 blockquote',
            quickbars_insert_toolbar: false, // disable insert quickbar (kita ada custom insert buttons)
            // Image dialog: advanced tab (border, spacing, styles) + caption support
            image_advtab: true,
            image_caption: true,
            // Right-click contextmenu untuk common ops
            contextmenu: 'link image table',
            // Non-editable class untuk lock certain content areas
            noneditable_class: 'mceNonEditable',
            // Import CSS from consumer app supaya template preview match production styling
            importcss_append: true,
            // ===== Paste hygiene (industri: PowerPaste-equivalent via built-in options) =====
            // MS Word paste ninggalin Mso*, mso-*, dan empty <p><span></span> — invisible
            // di editor tapi render sebagai blank rows di preview + dompdf PDF.
            // Whitelist tag berguna, strip Word garbage inline styles, remove empty nodes.
            paste_data_images: true,          // allow inline base64 images (screenshots)
            paste_merge_formats: true,        // merge adjacent identical spans
            paste_webkit_styles: 'none',      // strip webkit-specific inline styles
            paste_remove_styles_if_webkit: true,
            paste_word_valid_elements: 'b,strong,i,em,u,s,strike,sub,sup,br,p,span,ul,ol,li,table,tbody,thead,tfoot,tr,td,th,h1,h2,h3,h4,h5,h6,blockquote,pre,code,a[href]',
            paste_retain_style_properties: 'text-align,color,background-color,font-weight,font-style,text-decoration,vertical-align',
            paste_preprocess: function(_plugin, args) {
                let c = args.content;
                // Strip Word Mso* / MsoNormal classes
                c = c.replace(/\s*class="[^"]*Mso[^"]*"/gi, '');
                // Strip mso-* inline style properties
                c = c.replace(/mso-[^:]+:[^;"}]+;?\s*/gi, '');
                // Strip empty style attribute leftovers
                c = c.replace(/\s*style="\s*"/gi, '');
                c = c.replace(/\s*class="\s*"/gi, '');
                // Remove Word garbage empty paragraphs: <p><span></span></p>
                c = c.replace(/<p[^>]*>\s*<span[^>]*>\s*<\/span>\s*<\/p>/gi, '');
                // Remove other empty paragraphs (just whitespace/nbsp/br)
                c = c.replace(/<p[^>]*>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '');
                // Remove empty spans
                c = c.replace(/<span[^>]*>\s*<\/span>/gi, '');
                args.content = c;
            },
            paste_postprocess: function(_plugin, args) {
                // DOM-level second pass — remove empty paragraphs yang masih lolos preprocess
                // (setelah TinyMCE internal transforms). Preserve paragraphs dengan img/media.
                const root = args.node;
                if (!root) return;
                root.querySelectorAll('p').forEach(p => {
                    const text = (p.textContent || '').trim();
                    const hasMedia = p.querySelector('img, video, iframe, .field-placeholder, .ttd-placeholder, .materai-placeholder, .qr-placeholder, .logo-placeholder');
                    if (!text && !hasMedia) p.remove();
                });
                // Also strip Mso classes yang masih ada (defensive)
                root.querySelectorAll('[class*="Mso"]').forEach(el => {
                    const cleaned = (el.className || '').replace(/\s*Mso\w+\s*/g, ' ').trim();
                    if (cleaned) el.className = cleaned; else el.removeAttribute('class');
                });
            },
            // Single-row toolbar — semua tombol standard + custom ezdoc inserts sebaris.
            // Sliding mode akan tampilkan arrow untuk overflow saat width sempit.
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | outdent indent | ltr rtl | link table image | removeformat | searchreplace wordcount | code preview fullscreen help | insertfield insertttd insertmaterai insertqr insertlogo insertcond inserttable',
            toolbar_mode: 'sliding',
            font_size_formats: '8pt 9pt 10pt 11pt 12pt 14pt 16pt 18pt 20pt 24pt 28pt 36pt 48pt',
            font_family_formats: 'Times New Roman=Times New Roman,serif; Arial=arial,helvetica,sans-serif; Calibri=calibri,sans-serif; Courier New=courier new,courier,monospace; Georgia=georgia,serif; Tahoma=tahoma,arial,helvetica,sans-serif; Verdana=verdana,geneva,sans-serif; Helvetica=helvetica,arial,sans-serif',
            insertdatetime_formats: ['%d/%m/%Y', '%d %B %Y', '%H:%M', '%d/%m/%Y %H:%M'],
            insertdatetime_element: true,
            pagebreak_separator: '<div style="page-break-before:always;"></div>',
            nonbreaking_force_tab: true,
            browser_spellcheck: true,
            base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
            suffix: '.min',
            min_height: 400,
            // body_class 'content' — matches .content selectors di shared
            // Ezdoc\UI\ContentCss rules (so editor + generate + PDF all use
            // uniform ".content p", ".content table" selectors). Single source
            // of truth without needing dual selectors like "p, .content p".
            body_class: 'content',
            // Preserve custom attributes and styles
            extended_valid_elements: 'span[*],div[*]',
            custom_elements: '~span,~div',
            valid_styles: { '*': 'position,top,left,right,bottom,width,height,z-index,opacity,font-size,font-weight,font-style,color,background,background-color,border,border-bottom,border-radius,margin,padding,text-align,display,vertical-align,min-width,min-height,cursor,box-shadow,line-height' },
            verify_html: false,
            entity_encoding: 'raw',
            keep_styles: true,
            // Prevent cleanup of styles and classes
            valid_children: '+body[style|div|span],+div[style|div|span|p]',
            content_style: `
                /* Global box-sizing — MATCH generate.php's "* { box-sizing: border-box }".
                   Without this, td/th/p default to content-box in editor iframe,
                   causing width+padding calculation diff → table column widths
                   drift → text wrap point shifts → accumulated layout diff. */
                * { box-sizing: border-box; }
                /* Google Docs pattern — paper visualization DI DALAM iframe:
                   - html = gray backdrop (fills iframe viewport)
                   - body = paper card (fixed width A4, centered on backdrop)
                   Consistent di normal + fullscreen mode (TinyMCE tidak override
                   iframe internal CSS). */
                html {
                    background: #64748b;
                    padding: 20px 0;
                }
                body {
                    font-family: "Times New Roman", serif;
                    font-size: 12pt;
                    line-height: 1.6;
                    max-width: 210mm;      /* A4 width — updated dinamis via updatePageSize() */
                    margin: 0 auto;         /* center on gray backdrop */
                    background-color: white;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                    box-sizing: border-box;
                    position: relative;
                    /* Page break hint — 1px semi-transparent line di setiap
                       paper-height mark. Simple + accurate — no cumulative
                       displacement across pages.

                       Design rationale (setelah eksplorasi gap-band approach):
                       - Gap DI DALAM paperHeight tile → paper visual < paperHeight,
                         content near paper bottom overlap gap (ugly)
                       - Gap DI LUAR paperHeight tile (tile = paperHeight + gap)
                         → cumulative displacement (page N visual off by (N-1)×gap
                         dari actual print page position)
                       - 1px line di paperHeight boundary → accurate untuk semua N,
                         no content displacement, tetap visible sebagai page marker

                       Ini sesuai dgn continuous-DOM editor pattern (TinyMCE flat
                       body). Real "paper separated" look tidak achievable tanpa
                       hard page break DOM structure (Google Docs custom editor).

                       CSS var --ezdoc-page-h di-set dinamis oleh updatePageSize().
                       Fallback 297mm (A4 portrait) kalau var belum set. */
                    /* Dashed page break line — dual-layer background:
                       - Layer 1 (front): horizontal alternating transparent/white
                         stripes, 12px wide (6 transparent + 6 white). Masks layer 2
                         line into dashes. White opaque matches body bg → invisible
                         over paper area.
                       - Layer 2 (back): solid horizontal line at Y=paperH-1, tiled
                         vertically at paperH intervals.
                       Layer 1 masks layer 2 → visible dashed line at each page break
                       boundary. */
                    background-image:
                        linear-gradient(
                            to right,
                            transparent 0,
                            transparent 6px,
                            white 6px,
                            white 12px
                        ),
                        linear-gradient(
                            to bottom,
                            transparent 0,
                            transparent calc(var(--ezdoc-page-h, 297mm) - 1px),
                            rgba(100, 116, 139, 0.55) calc(var(--ezdoc-page-h, 297mm) - 1px),
                            rgba(100, 116, 139, 0.55) var(--ezdoc-page-h, 297mm)
                        );
                    background-size: 12px 100%, 100% var(--ezdoc-page-h, 297mm);
                    background-position: 0 0, 0 0;
                    background-repeat: repeat-x, repeat-y;
                    background-attachment: local; /* scroll dgn content */
                }
                /* "Page N" label di setiap page-break line — subtle floating
                   indicator, tidak affect content flow. Positioned via same
                   var. Nice-to-have: only page 2+ (skip first page label). */
                body::before {
                    content: '';
                    /* Placeholder — actual page labels rendered via JS overlay
                       (kalau butuh detail). CSS-only approach di sini fokus
                       ke visible break line saja. */
                }
                /* Scroll room bottom — pseudo-element supaya persist even ketika
                   updatePageSize() reset body.style.padding. Industri: Google Docs
                   / Notion editor invisible "scroll spacer" pattern. */
                body::after {
                    content: '';
                    display: block;
                    height: 120px;
                    pointer-events: none;
                }
                /* --- Shared content baseline (Ezdoc\UI\ContentCss) ---
                   Single source of truth for paragraph, list, table, heading
                   rendering across designer + generate + PDF. TinyMCE body has
                   the "content" class (via body_class option) so .content
                   selectors match. Previously these rules were duplicated
                   and drifted between 3 contexts, causing text flow
                   accumulation bugs (~1 line offset per page). Centralized now. */
                <?= \Ezdoc\UI\ContentCss::render() ?>
                /* Virtual pagination spacer companion CSS (Ezdoc\UI\PaginationJs).
                   Spacer straddles physical page boundary — visible gap sync
                   dgn dashed page break line above. Content resumes on next
                   virtual page with padT margin below break. */
                <?= \Ezdoc\UI\PaginationJs::renderCss(20.0) ?>
                /* Field placeholder — dimensions match rendered .f di generate.php
                   edit-on state (padding 1px 4px + border-bottom 1px dotted). Editor
                   renders identical box size dgn generate view saat field diisi user
                   → zero horizontal/vertical drift. Outline dashed = editor-only
                   visibility indicator, doesn't affect box (outline vs border). */
                .field-placeholder {
                    background: #dbeafe; color: #1e40af;
                    padding: 1px 4px; margin: 0;
                    border: none;
                    border-bottom: 1px dotted #333;
                    outline: 1px dashed #93c5fd; outline-offset: 0;
                    border-radius: 2px;
                    font-family: inherit; font-size: inherit; font-weight: inherit; font-style: inherit;
                    white-space: nowrap; display: inline;
                }
                .field-placeholder[data-type="number"] { background: #fef3c7; outline-color: #fbbf24; color: #92400e; }
                .field-placeholder[data-type="date"] { background: #e0e7ff; outline-color: #818cf8; color: #3730a3; }
                .field-placeholder[data-type="checkbox"] { background: #dcfce7; outline-color: #22c55e; color: #166534; }
                .field-placeholder[data-type="radio"] { background: #fce7f3; outline-color: #ec4899; color: #9d174d; }
                .field-placeholder[data-type="select"] { background: #f3e8ff; outline-color: #a855f7; color: #6b21a8; }
                /* Inline logo — layout-transparent (outline, no padding/border/min-width).
                   Non-floating placeholder tanpa image renders sebagai text label;
                   dgn image renders at natural image dimensions (matches print
                   <img class="logo-img"> exact). */
                .logo-placeholder {
                    display: inline-block;
                    padding: 0; margin: 0;
                    border: none; outline: 2px dashed #94a3b8; outline-offset: -2px;
                    background: rgba(241, 245, 249, 0.5);
                    border-radius: 2px; color: #64748b; font-size: 12px;
                    text-align: center; vertical-align: middle;
                }
                .logo-placeholder img {
                    display: block; border-radius: 2px;
                }
                /* Floating logo — padding: 0 supaya <img> di dalam span posisi
                   sama dgn generate rendering (generate render <img> langsung
                   at top/left tanpa wrapping span). line-height: 0 + font-size: 0
                   kill inline-block phantom descender space (baseline gap ~4-8px
                   at bottom due to font-metrics). Result: span dimensions = img
                   dimensions exactly, no baseline offset. */
                .logo-placeholder.floating {
                    position: absolute;
                    cursor: move;
                    border-color: #8b5cf6;
                    background: rgba(255, 255, 255, 0.95);
                    padding: 0;
                    margin: 0;
                    line-height: 0;
                    font-size: 0;
                }
                .logo-placeholder.floating.behind {
                    z-index: -1;
                }
                .logo-placeholder.floating.front {
                    z-index: 100;
                }
                .logo-placeholder.floating:hover {
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    border-color: #7c3aed;
                }
                .logo-placeholder.floating::after {
                    content: '⋮⋮';
                    position: absolute;
                    top: 2px;
                    right: 4px;
                    font-size: 10px;
                    color: #8b5cf6;
                    background: rgba(255,255,255,0.8);
                    padding: 0 2px;
                    border-radius: 2px;
                }
                /* TTD Placeholder — visual polish version. Border dashed (clean
                   corners vs outline yg broken), small padding untuk breathing
                   room, background subtle tint. Total dimensions closely match
                   generate .ttd-item-inline (~120×110px content + margin). */
                .ttd-placeholder {
                    display: inline-block;
                    padding: 6px 10px; margin: 5px;
                    border: 2px dashed #10b981; outline: none;
                    background: rgba(236, 253, 245, 0.25);
                    border-radius: 6px; color: #065f46;
                    min-width: 116px; min-height: 96px;
                    text-align: center; vertical-align: top;
                }
                /* Floating TTD — hanya override margin: 0 (position accuracy).
                   Padding + font-size + line-height inherit dari base supaya
                   inner text (label, name) render dgn proper spacing.
                   TTD adalah block element (bukan inline-block img wrapper
                   seperti logo) → tidak butuh line-height: 0 phantom descender
                   kill. */
                .ttd-placeholder.floating {
                    position: absolute;
                    cursor: move;
                    border-color: #10b981;
                    background: rgba(236, 253, 245, 0.95);
                    margin: 0;
                }
                .ttd-placeholder.floating.behind { z-index: -1; }
                .ttd-placeholder.floating.front { z-index: 100; }
                .ttd-placeholder.floating:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
                .ttd-placeholder.floating::after {
                    content: '⋮⋮';
                    position: absolute;
                    top: 2px;
                    right: 4px;
                    font-size: 10px;
                    color: #10b981;
                }
                /* Materai Placeholder */
                /* Conditional Section visual marker (editor only) — pakai OUTLINE
                   bukan border+padding. Outline render visually tapi TIDAK
                   affect layout (tidak add size ke element). Tanpa
                   padding/margin/border → content position editor exact match
                   dgn print (yg reset .conditional-section ke padding:0 margin:0
                   border:none). Sebelumnya editor pakai border 1px + padding 8px
                   + margin 8px 0 → tambah ~9mm per section (~1 baris teks) →
                   visual page break 1+ baris lebih cepat dari print reality. */
                .conditional-section {
                    outline: 1px dashed #06b6d4;
                    outline-offset: -1px;
                    background: #ecfeff;
                    border-radius: 4px;
                }

                .materai-placeholder {
                    display: inline-block; vertical-align: middle;
                }
                .materai-placeholder.floating {
                    position: absolute;
                    cursor: move;
                }
                .materai-placeholder.floating.behind { z-index: -1; opacity: 0.85; }
                .materai-placeholder.floating.front { z-index: 100; }
                .materai-placeholder.floating:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
                .materai-placeholder.floating::after {
                    content: '⋮⋮';
                    position: absolute;
                    top: 2px;
                    right: 4px;
                    font-size: 10px;
                    color: #c2410c;
                }
                /* QR Placeholder — dimensions + margin match rendered .qr-item-inline
                   di generate.php (inline-block + margin 5px + inner .qr-canvas-placeholder
                   80×80). Total occupied box = 90×90 including margin. Editor renders
                   identical box → identical text flow position. */
                .qr-placeholder {
                    display: inline-block;
                    padding: 0; margin: 5px;
                    border: 2px dashed #6366f1; background: #eef2ff;
                    border-radius: 4px; color: #4338ca; font-size: 11px;
                    width: 80px; height: 80px;
                    text-align: center; vertical-align: top;
                }
                /* Floating QR — margin: 0 override base (base pakai margin: 5px
                   untuk match inline .qr-item-inline; floating variant HARUS
                   margin: 0 supaya top/left coord = actual visual position,
                   match generate .qr-item-floating). */
                .qr-placeholder.floating {
                    position: absolute;
                    cursor: move;
                    border-color: #6366f1;
                    background: rgba(238, 242, 255, 0.95);
                    margin: 0;
                    padding: 0;
                }
                .qr-placeholder.floating.behind { z-index: -1; }
                .qr-placeholder.floating.front { z-index: 100; }
                .qr-placeholder.floating:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
                .qr-placeholder.floating::after {
                    content: '⋮⋮';
                    position: absolute;
                    top: 2px;
                    right: 4px;
                    font-size: 10px;
                    color: #6366f1;
                }
            `,
            table_default_attributes: { border: '1' },
            setup: function(editor) {
                // Custom Line Height Button
                editor.ui.registry.addMenuButton('lineheightbtn', {
                    text: 'LH',
                    tooltip: 'Line Height',
                    fetch: function(callback) {
                        const lineHeights = [
                            { value: '1', label: '1.0 (Rapat)' },
                            { value: '1.2', label: '1.2' },
                            { value: '1.4', label: '1.4' },
                            { value: '1.5', label: '1.5' },
                            { value: '1.6', label: '1.6 (Default)' },
                            { value: '1.8', label: '1.8' },
                            { value: '2', label: '2.0 (Double)' },
                            { value: '2.5', label: '2.5' },
                            { value: '3', label: '3.0' }
                        ];
                        const items = lineHeights.map(lh => ({
                            type: 'menuitem',
                            text: lh.label,
                            onAction: function() {
                                editor.formatter.apply('lineheight', { value: lh.value });
                            }
                        }));
                        callback(items);
                    }
                });

                // Register line height format
                editor.on('init', function() {
                    editor.formatter.register('lineheight', {
                        selector: 'p,div,h1,h2,h3,h4,h5,h6,td,th,li,span',
                        styles: { 'line-height': '%value' }
                    });
                });

                // Invalidate verify fields cache saat editor content berubah
                // (supaya field detection tetap fresh, tapi tidak scan berulang tiap keystroke)
                editor.on('change SetContent input', function() {
                    if (typeof invalidateVerifyFieldsCache === 'function') {
                        invalidateVerifyFieldsCache();
                    }
                });

                // Virtual pagination (v0.9.13 phase 2 — margin-based).
                //
                // Approach: paginate() modify margin-top of elements yg cross
                // physical page boundary (via .pg-boundary-push class + inline
                // margin-top). ZERO widget elements → zero TinyMCE caret container
                // insertion → zero cursor navigation issues.
                //
                // Trigger via MutationObserver (bukan TinyMCE event listeners) —
                // TinyMCE 'input'/'change' events kadang fire on cursor move di
                // beberapa browser (arrow key flicker bug). MutationObserver hanya
                // fires pada REAL DOM content changes (childList + characterData),
                // ignore attribute mutations (yg dilakukan paginate sendiri).
                editor.on('init', function() {
                    const body = editor.getBody();
                    if (typeof repaginateEditor === 'function') repaginateEditor();

                    // Register serializer node filter untuk strip pagination markers
                    // saat editor.getContent(). Registered di 'init' handler karena
                    // editor.serializer belum available di setup callback.
                    //
                    // Precedent: TinyMCE core pagebreak plugin, imagetools plugin —
                    // pakai serializer filter untuk transform elements at save time
                    // tanpa modify DOM tree (yg akan bikin visible flicker).
                    // TinyMCE 6 addNodeFilter('*') NOT wildcard — cuma match
                    // literal tag '*' (yg tidak ada). Pakai addAttributeFilter
                    // dgn our marker attribute → fires untuk any element yg
                    // punya data-pg-original-mt → precise strip.
                    if (editor.serializer && typeof editor.serializer.addAttributeFilter === 'function') {
                        editor.serializer.addAttributeFilter('data-pg-original-mt', function(nodes) {
                            for (let i = 0; i < nodes.length; i++) {
                                const node = nodes[i];
                                const cls = node.attr('class') || '';
                                const newCls = cls.split(/\s+/).filter(function(c) {
                                    return c && c !== 'pg-boundary-push';
                                }).join(' ');
                                node.attr('class', newCls || null);
                                node.attr('data-pg-original-mt', null);
                                const style = node.attr('style') || '';
                                if (style) {
                                    const newStyle = style
                                        .replace(/(^|;)\s*margin-top\s*:[^;]*(;|$)/gi, '$1')
                                        .replace(/(^|;)\s*page-break-before\s*:[^;]*(;|$)/gi, '$1')
                                        .replace(/(^|;)\s*break-before\s*:[^;]*(;|$)/gi, '$1')
                                        .replace(/^;+/, '')
                                        .replace(/;+$/, '')
                                        .trim();
                                    node.attr('style', newStyle || null);
                                }
                            }
                        });
                    }

                    // MutationObserver — fires HANYA pada real content mutations.
                    // Kita observe childList + characterData (NOT attributes) supaya
                    // paginate's own attribute mutations (class, style, data-*) tidak
                    // trigger recursion. Also filter out mmToPx probe elements
                    // (position:absolute + visibility:hidden = clearly non-content).
                    if (typeof MutationObserver === 'function') {
                        const observer = new MutationObserver(function(mutations) {
                            let contentChanged = false;
                            for (let i = 0; i < mutations.length; i++) {
                                const m = mutations[i];
                                if (m.type === 'characterData') { contentChanged = true; break; }
                                if (m.type === 'childList') {
                                    const nodes = [];
                                    for (let j = 0; j < m.addedNodes.length; j++) nodes.push(m.addedNodes[j]);
                                    for (let j = 0; j < m.removedNodes.length; j++) nodes.push(m.removedNodes[j]);
                                    for (let j = 0; j < nodes.length; j++) {
                                        const n = nodes[j];
                                        // Skip mmToPx probes (offscreen hidden divs).
                                        if (n.nodeType === 1 && n.style
                                            && n.style.position === 'absolute'
                                            && n.style.visibility === 'hidden') continue;
                                        contentChanged = true; break;
                                    }
                                    if (contentChanged) break;
                                }
                            }
                            if (contentChanged && typeof repaginateEditorDebounced === 'function') {
                                repaginateEditorDebounced();
                            }
                        });
                        observer.observe(body, {
                            childList: true,
                            subtree: true,
                            characterData: true
                            // attributes: false — paginate mutates attributes, tidak
                            // boleh trigger observer (avoid infinite recursion).
                        });
                    }
                });

                // ===== Register custom SVG icons (Bootstrap Icons paths) =====
                // Digunakan oleh custom insert buttons (Logo/QR/Field/TTD/Materai/Kondisi/Tabel).
                editor.ui.registry.addIcon('ezdoc-logo', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/><path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/></svg>');
                editor.ui.registry.addIcon('ezdoc-qr', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M2 2h2v2H2V2Zm4 0h1v4H2V5h4V2Zm6 4V4h-1V2h1V1h1v1h1v1h-1v3h-1Zm-3-1V1h1v4H9Zm3 4V7h1v2h-1Zm-3 1V8H8V7h4v2h-1v1h-1V9H9v1Zm-6 4V9h1v1h1v1h1v-1h1v4H4v-4H3v3H2Zm7 0V9h1v1h1v-1h1v4h-1v-2h-1v2H9Zm4-1v1h-1v-1h1Z"/><path d="M0 0h6v6H0V0Zm10 0h6v6h-6V0ZM0 10h6v6H0v-6Zm3-8H1v3h2V2Zm11 0h-2v3h2V2ZM3 12H1v3h2v-3Z"/></svg>');
                editor.ui.registry.addIcon('ezdoc-field', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5h-2z"/><path d="M4.146 10.146a.5.5 0 0 1 .708 0L6 11.293l1.146-1.147a.5.5 0 0 1 .708.708L6.707 12l1.147 1.146a.5.5 0 0 1-.708.708L6 12.707l-1.146 1.147a.5.5 0 0 1-.708-.708L5.293 12l-1.147-1.146a.5.5 0 0 1 0-.708zm5 0a.5.5 0 0 1 .708 0L11 11.293l1.146-1.147a.5.5 0 0 1 .708.708L11.707 12l1.147 1.146a.5.5 0 0 1-.708.708L11 12.707l-1.146 1.147a.5.5 0 0 1-.708-.708L10.293 12l-1.147-1.146a.5.5 0 0 1 0-.708z"/></svg>');
                editor.ui.registry.addIcon('ezdoc-ttd', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M13.498.795l.149-.149a1.207 1.207 0 1 1 1.707 1.708l-.149.148a1.5 1.5 0 0 1-.059 2.059L4.854 14.854a.5.5 0 0 1-.233.131l-4 1a.5.5 0 0 1-.606-.606l1-4a.5.5 0 0 1 .131-.232l9.642-9.642a.5.5 0 0 0-.642.056L6.854 4.854a.5.5 0 1 1-.708-.708L9.44.854A1.5 1.5 0 0 1 11.5.796a1.5 1.5 0 0 1 1.998-.001z"/></svg>');
                editor.ui.registry.addIcon('ezdoc-materai', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M4.5 1.938a.5.5 0 0 1 .858.494L4.905 4H9.5a.5.5 0 0 1 0 1H4.905l.453 1.568a.5.5 0 1 1-.858.494L4 5.28l-.5 1.782a.5.5 0 1 1-.858-.494L3.095 5H1.5a.5.5 0 0 1 0-1h1.595l-.453-1.568a.5.5 0 0 1 .858-.494L3.5 3.72l.5-1.782ZM2 12a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-2Zm2-1a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1H4Z"/><path d="M9.5 3a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z"/></svg>');
                editor.ui.registry.addIcon('ezdoc-cond', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M9.05.435c-.58-.58-1.52-.58-2.1 0L.436 6.95c-.58.58-.58 1.519 0 2.098l6.516 6.516c.58.58 1.519.58 2.098 0l6.516-6.516c.58-.58.58-1.519 0-2.098L9.05.435zM8 4c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995A.905.905 0 0 1 8 4zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>');
                editor.ui.registry.addIcon('ezdoc-table', '<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor"><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2Zm15 2h-4v3h4V4Zm0 4h-4v3h4V8Zm0 4h-4v3h3a1 1 0 0 0 1-1v-2Zm-5 3v-3H6v3h4Zm-5 0v-3H1v2a1 1 0 0 0 1 1h3Zm-4-4h4V8H1v3Zm0-4h4V4H1v3Zm5 0h4V4H6v3Zm4 1H6v3h4V8Z"/></svg>');

                // Logo insert with positioning options
                editor.ui.registry.addMenuButton('insertlogo', {
                    text: 'Logo',
                    icon: 'ezdoc-logo',
                    tooltip: t('toolbar_insert.logo_tooltip', {}, 'Insert Logo Placeholder'),
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: t('toolbar_insert.mode_inline', {}, 'Inline (in text)'), onAction: function() { insertLogoPrompt('inline'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_front', {}, 'Floating - In Front of Text'), onAction: function() { insertLogoPrompt('front'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_behind', {}, 'Floating - Behind Text'), onAction: function() { insertLogoPrompt('behind'); }}
                        ]);
                    }
                });

                function insertLogoPrompt(mode) {
                    const name = prompt(t('toolbar_insert.logo_name_prompt', {}, 'Logo name (example: logo_hospital):'), 'logo_' + Date.now());
                    if (name && name.trim()) {
                        const cleanName = name.trim().replace(/\s+/g, '_').toLowerCase();
                        const width = prompt(t('toolbar_insert.logo_width_prompt', {}, 'Logo width (example: 80px, 100px):'), '80px') || '80px';
                        configHeader.logoSizes[cleanName] = width;

                        let classes = 'logo-placeholder';
                        let style = '';
                        let posAttrs = '';

                        if (mode === 'front' || mode === 'behind') {
                            classes += ' floating ' + mode;
                            style = ` style="top: 20px; left: 20px;"`;
                            posAttrs = ` data-pos-mode="${mode}" data-pos-x="20" data-pos-y="20"`;
                        }

                        // Widget-wrapper pattern (CKEditor 5 non-editable atomic
                        // block precedent) untuk floating variants: wrap span dalam
                        // <p class="floating-only" contenteditable="false"> supaya:
                        // - .floating-only CSS collapse ke 0 height (no empty line)
                        // - contenteditable="false" wrapper = cursor can't accidentally
                        //   enter, protect dari accidental deletion via typing
                        // - explicit wrapper skip TinyMCE auto-paragraph creation
                        //   yg might not get .floating-only class in time.
                        const logoSpan = `<span class="${classes}" data-logo="${cleanName}" data-width="${width}"${posAttrs}${style} contenteditable="false">[Logo: ${cleanName}]</span>`;
                        if (mode === 'inline') {
                            editor.insertContent(logoSpan + '&nbsp;');
                        } else {
                            editor.insertContent(`<p class="floating-only" contenteditable="false">${logoSpan}</p>`);
                        }
                        setTimeout(scanLogos, 100);
                        if (mode !== 'inline') applyFloatingOnlyClasses();

                        // Initialize drag for floating logos
                        if (mode !== 'inline') {
                            setTimeout(initLogoDrag, 200);
                        }
                    }
                }

                // QR Code insert with positioning options
                editor.ui.registry.addMenuButton('insertqr', {
                    text: 'QR',
                    icon: 'ezdoc-qr',
                    tooltip: t('toolbar_insert.qr_tooltip', {}, 'Insert QR Code Placeholder'),
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: t('toolbar_insert.mode_inline', {}, 'Inline (in text)'), onAction: function() { insertQrPrompt('inline'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_front', {}, 'Floating - In Front of Text'), onAction: function() { insertQrPrompt('front'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_behind', {}, 'Floating - Behind Text'), onAction: function() { insertQrPrompt('behind'); }}
                        ]);
                    }
                });

                function insertQrPrompt(mode) {
                    const fieldName = prompt(t('toolbar_insert.qr_field_prompt', {}, 'Field name for QR data (example: verify_url, doc_number):'), 'qr_data');
                    if (fieldName && fieldName.trim()) {
                        const cleanName = fieldName.trim().replace(/\s+/g, '_').toLowerCase();
                        const width = prompt(t('toolbar_insert.qr_size_prompt', {}, 'QR size (example: 80px, 100px):'), '80px') || '80px';

                        let classes = 'qr-placeholder';
                        let style = '';
                        let posAttrs = '';

                        if (mode === 'front' || mode === 'behind') {
                            classes += ' floating ' + mode;
                            style = ` style="top: 20px; left: 20px;"`;
                            posAttrs = ` data-pos-mode="${mode}" data-pos-x="20" data-pos-y="20"`;
                        }

                        // Widget-wrapper pattern (see logo insert). Floating variant
                        // wrapped in <p.floating-only contenteditable=false>.
                        const qrSpan = `<span class="${classes}" data-qr="${cleanName}" data-width="${width}"${posAttrs}${style} contenteditable="false">[QR: ${cleanName}]</span>`;
                        if (mode === 'inline') {
                            editor.insertContent(qrSpan + '&nbsp;');
                        } else {
                            editor.insertContent(`<p class="floating-only" contenteditable="false">${qrSpan}</p>`);
                        }
                        if (mode !== 'inline') applyFloatingOnlyClasses();

                        // Initialize drag for floating QR
                        if (mode !== 'inline') {
                            setTimeout(() => {
                                setupDragDelegation();
                                restoreFloatingPositions();
                            }, 200);
                        }
                    }
                }

                // Field insert with type options
                editor.ui.registry.addMenuButton('insertfield', {
                    text: 'Field',
                    icon: 'ezdoc-field',
                    tooltip: t('toolbar_insert.field_tooltip', {}, 'Insert Field Input'),
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: t('toolbar_insert.field_type_text', {}, 'Text (default)'), onAction: () => insertFieldPrompt('text') },
                            { type: 'menuitem', text: t('field_type.number', {}, 'Number'), onAction: () => insertFieldPrompt('number') },
                            { type: 'menuitem', text: t('field_type.date', {}, 'Date'), onAction: () => insertFieldPrompt('date') },
                            { type: 'menuitem', text: t('field_type.checkbox', {}, 'Checkbox'), onAction: () => insertFieldPrompt('checkbox') },
                            { type: 'menuitem', text: t('toolbar_insert.field_type_radio', {}, 'Radio (single choice)'), onAction: () => insertFieldPrompt('radio') },
                            { type: 'menuitem', text: t('toolbar_insert.field_type_select', {}, 'Select (dropdown)'), onAction: () => insertFieldPrompt('select') }
                        ]);
                    }
                });

                function insertFieldPrompt(fieldType) {
                    const name = prompt(t('toolbar_insert.field_name_prompt', {}, 'Field name (example: patient_name):'));
                    if (!name || !name.trim()) return;

                    const cleanName = name.trim().replace(/\s+/g, '_').toLowerCase();
                    let options = '';
                    let label = '';

                    // For checkbox, radio, select - ask for options
                    if (fieldType === 'checkbox') {
                        // Label optional - if user cancels or leaves empty, checkbox has no label
                        const inp = prompt(t('toolbar_insert.checkbox_label_prompt', {}, 'Checkbox label (leave empty for no label):'), '');
                        label = inp === null ? '' : inp.trim();
                    } else if (fieldType === 'radio' || fieldType === 'select') {
                        const opts = prompt(t('toolbar_insert.options_prompt', {}, 'Options (comma-separated):\nExample: Yes,No,Maybe'), 'Ya,Tidak');
                        if (!opts) return;
                        options = opts;
                        label = prompt(t('toolbar_insert.label_prompt_optional', {}, 'Label (optional):'), '') || '';
                    }

                    // Ask for default value
                    const defaultVal = prompt(t('toolbar_insert.default_value_prompt', {}, 'Default value (leave empty if none):\nExample: plain text, date:d F Y, $author_nama'), '') || '';

                    let attrs = `data-type="${fieldType}"`;
                    if (options) attrs += ` data-options="${options.replace(/"/g, '&quot;')}"`;
                    if (label) attrs += ` data-label="${label.replace(/"/g, '&quot;')}"`;
                    if (defaultVal) attrs += ` data-default="${defaultVal.replace(/"/g, '&quot;')}"`;

                    // Display text in editor - show field name inside
                    editor.insertContent(`<span class="field-placeholder" ${attrs}>{{${cleanName}}}</span>&nbsp;`);
                }

                // TTD insert with positioning options
                editor.ui.registry.addMenuButton('insertttd', {
                    text: 'TTD',
                    icon: 'ezdoc-ttd',
                    tooltip: t('toolbar_insert.ttd_tooltip', {}, 'Insert Signature Placeholder'),
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: t('toolbar_insert.mode_inline', {}, 'Inline (in text)'), onAction: function() { insertTtdPrompt('inline'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_front', {}, 'Floating - In Front of Text'), onAction: function() { insertTtdPrompt('front'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_behind', {}, 'Floating - Behind Text'), onAction: function() { insertTtdPrompt('behind'); }}
                        ]);
                    }
                });

                function insertTtdPrompt(mode) {
                    const label = prompt(t('toolbar_insert.ttd_label_prompt', {}, 'Signature label (example: Attending Physician):'), t('fallback.signature', {}, 'Signature'));
                    if (label && label.trim()) {                 
                        const ttdId = 'ttd_' + Date.now();
                        const namaField = prompt(t('toolbar_insert.ttd_name_field_prompt', {}, "Field name for the signer's name:"), 'nama_' + ttdId);

                        // Default nama — kalau di-set, akan muncul otomatis kalau field belum diisi
                        const defaultNama = (prompt(t('toolbar_insert.ttd_default_name_prompt', {}, 'Default signature name (optional, leave empty if not needed):\nExample: dr. Hilmi K Riskawa, Sp.A., M.J'), '') || '').trim();

                        // Ask for TTD modes
                        const ttdModesInput = prompt(t('toolbar_insert.ttd_mode_prompt', {}, 'Signature mode (image / qr / image,qr):'), 'image');
                        const ttdModes = (ttdModesInput || 'image').trim().toLowerCase();

                        let qrData = '';
                        if (ttdModes.includes('qr')) {
                            qrData = prompt(t('toolbar_insert.ttd_qr_data_prompt', {}, 'QR data (can use {field_name}):\nExample: Signed by {nama_dokter} on {tanggal}'), '') || '';
                        }

                        let classes = 'ttd-placeholder';
                        let style = '';
                        let posAttrs = '';

                        if (mode === 'front' || mode === 'behind') {
                            classes += ' floating ' + mode;
                            style = ` style="top: 100px; left: 50px;"`;
                            posAttrs = ` data-pos-mode="${mode}" data-pos-x="50" data-pos-y="100"`;
                        }

                        let extraAttrs = '';
                        if (ttdModes !== 'image') extraAttrs += ` data-ttd-modes="${ttdModes}"`;
                        if (qrData) extraAttrs += ` data-ttd-qr-data="${qrData.replace(/"/g, '&quot;')}"`;
                        if (defaultNama) extraAttrs += ` data-default-nama="${defaultNama.replace(/"/g, '&quot;')}"`;

                        // Add to configTtd
                        configTtd.push({
                            id: ttdId,
                            label: label.trim(),
                            nama_field: namaField || 'nama_' + ttdId,
                            mode: mode,
                            ttdModes: ttdModes,
                            qrData: qrData,
                            defaultNama: defaultNama
                        });

                        // Preview text di editor: kalau ada default nama, tampilkan, kalau tidak dots
                        const previewNama = defaultNama || '..................';
                        // Dimensions match generate .ttd-item-inline structure exactly:
                        // - label: font-size 12pt (was 10px) → matches .ttd-label
                        // - canvas box: 100×50 dashed border → matches .ttd-canvas-placeholder
                        // - name: font-size 11pt (was 10px) → matches .ttd-name
                        // Total height ~107px, width ≥120px → identical footprint to
                        // generate rendered TTD box. Regex-safe (3 flat sibling divs).
                        const content = `
                            <div class="${classes}" data-ttd="${ttdId}" data-label="${label.trim()}" data-nama-field="${namaField}"${posAttrs}${extraAttrs}${style} contenteditable="false">
                                <div style="font-size:12pt;margin-bottom:5px;">${label.trim()}</div>
                                <div style="width:100px;height:50px;border:1px dashed #10b981;background:#ecfdf5;margin:0 auto;"></div>
                                <div style="font-size:11pt;margin-top:3px;">(${previewNama})</div>
                            </div>
                        `;
                        // Widget-wrapper pattern (see logo insert).
                        if (mode === 'inline') {
                            editor.insertContent(content + '&nbsp;');
                        } else {
                            editor.insertContent(`<p class="floating-only" contenteditable="false">${content}</p>`);
                        }
                        if (mode !== 'inline') applyFloatingOnlyClasses();

                        setTimeout(() => {
                            scanTtdPlaceholders();
                            if (mode !== 'inline') initTtdDrag();
                        }, 100);

                        renderTtd();
                    }
                }

                // ===== Materai (stamp duty) =====
                editor.ui.registry.addMenuButton('insertmaterai', {
                    text: 'Materai',
                    icon: 'ezdoc-materai',
                    tooltip: t('toolbar_insert.materai_tooltip', {}, 'Insert Stamp Duty (E-Materai upload / empty area to affix)'),
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: t('toolbar_insert.mode_inline', {}, 'Inline (in text)'), onAction: function() { insertMateraiPrompt('inline'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_front', {}, 'Floating - In Front of Text'), onAction: function() { insertMateraiPrompt('front'); }},
                            { type: 'menuitem', text: t('toolbar_insert.mode_behind', {}, 'Floating - Behind Text'), onAction: function() { insertMateraiPrompt('behind'); }}
                        ]);
                    }
                });

                function insertMateraiPrompt(mode) {
                    // Label optional (bisa kosong)
                    const labelInput = prompt(t('toolbar_insert.materai_label_prompt', {}, 'Stamp Duty label (leave empty for no label):'), 'Materai 10000');
                    if (labelInput === null) return; // user cancelled
                    const label = (labelInput || '').trim();

                    // Mode: upload (e-materai digital) atau kosong (tempel manual setelah print)
                    const matModeInput = (prompt(t('toolbar_insert.materai_mode_prompt', {}, 'Stamp Duty mode (upload / empty):'), 'upload') || 'upload').trim().toLowerCase();
                    const matMode = (matModeInput === 'kosong') ? 'kosong' : 'upload';

                    // Ukuran (default e-materai Peruri ~ 26mm x 36mm; di 96dpi ≈ 98x136px). Pakai 100x140 default.
                    const widthInput = prompt(t('toolbar_insert.materai_width_prompt', {}, 'Stamp Duty width (px):'), '100');
                    if (widthInput === null) return;
                    const heightInput = prompt(t('toolbar_insert.materai_height_prompt', {}, 'Stamp Duty height (px):'), '140');
                    if (heightInput === null) return;
                    const matW = Math.max(20, parseInt(widthInput) || 100);
                    const matH = Math.max(20, parseInt(heightInput) || 140);

                    const materaiId = 'materai_' + Date.now();
                    let classes = 'materai-placeholder';
                    let style = '';
                    let posAttrs = '';
                    if (mode === 'front' || mode === 'behind') {
                        classes += ' floating ' + mode;
                        style = ` style="top: 500px; left: 350px;"`;
                        posAttrs = ` data-pos-mode="${mode}" data-pos-x="350" data-pos-y="500"`;
                    }

                    const visualLabel = (matMode === 'kosong') ? t('materai.mode_empty_paren', {}, '(empty)') : t('materai.mode_upload_paren', {}, '(upload)');
                    const escLabel = label.replace(/"/g, '&quot;');

                    const content = `
                        <div class="${classes}" data-materai="${materaiId}" data-label="${escLabel}" data-mode="${matMode}" data-width="${matW}" data-height="${matH}"${posAttrs}${style} contenteditable="false">
                            <div style="border:1px dashed #c2410c; width:${matW}px; height:${matH}px; background:#fff7ed; color:#c2410c; text-align:center; font-size:10px; padding:6px; line-height:1.3;">
                                <strong>MATERAI</strong><br>10000<br>${visualLabel}
                            </div>
                        </div>
                    `;
                    // Widget-wrapper pattern (see logo insert).
                    if (mode === 'inline') {
                        editor.insertContent(content + '&nbsp;');
                    } else {
                        editor.insertContent(`<p class="floating-only" contenteditable="false">${content}</p>`);
                    }
                    if (mode !== 'inline') applyFloatingOnlyClasses();

                    setTimeout(() => {
                        scanMateraiPlaceholders();
                        if (mode !== 'inline') initTtdDrag();
                    }, 100);

                    renderMaterai();
                }

                // ===== Conditional Section (#7) =====
                editor.ui.registry.addButton('insertcond', {
                    text: t('toolbar_insert.cond_button', {}, 'Condition'),
                    icon: 'ezdoc-cond',
                    tooltip: t('toolbar_insert.cond_tooltip', {}, 'Insert Conditional Section (show/hide based on field value)'),
                    onAction: function() {
                        const expr = prompt(t('toolbar_insert.cond_expr_prompt', {}, 'Expression (example: jenis_kelamin=P, or umur>=17, or status_nikah!=lajang):\n\nOperator: = != > < >= <=\nCombine: AND, OR (e.g. jenis_kelamin=P AND umur>=17)'), 'jenis_kelamin=P');
                        if (!expr || !expr.trim()) return;
                        const escExpr = expr.trim().replace(/"/g, '&quot;');
                        const placeholder = `<div class="conditional-section" data-cond="${escExpr}" contenteditable="true">
                            <div style="font-size:10px;color:#0e7490;background:#ecfeff;padding:2px 6px;border-radius:3px;margin-bottom:4px;display:inline-block;">${t('cond.display_if_prefix', {}, 'Show if:')} <strong>${escapeHtml(expr.trim())}</strong></div>
                            <p>${escapeHtml(t('toolbar_insert.cond_placeholder_text', {}, 'Write the conditional content here... (this paragraph will be hidden if the condition is not met)'))}</p>
                        </div>`;
                        editor.insertContent(placeholder + '<p></p>');
                    }
                });

                editor.ui.registry.addButton('inserttable', {
                    text: t('toolbar_insert.table_button', {}, 'Table'),
                    icon: 'ezdoc-table',
                    tooltip: t('toolbar_insert.table_tooltip', {}, 'Insert Label-Value Table'),
                    onAction: function() {
                        const count = prompt(t('toolbar_insert.table_rows_prompt', {}, 'Number of rows:'), '3');
                        if (count && parseInt(count) > 0) {
                            let rows = '';
                            for (let i = 1; i <= parseInt(count); i++) {
                                rows += `<tr><td style="width:30%;border:none;">Label ${i}</td><td style="width:5px;border:none;">:</td><td style="border:none;"><span class="field-placeholder">{{field_${i}}}</span></td></tr>`;
                            }
                            editor.insertContent(`<table style="width:100%;border:none;"><tbody>${rows}</tbody></table><p></p>`);
                        }
                    }
                });

                // Auto-classify paragraphs yang cuma berisi floating elements
                // (position: absolute). Adds `.floating-only` class supaya CSS
                // collapse ke 0 height (via ContentCss rule `p.floating-only {
                // min-height: 0; margin: 0; line-height: 0 }`).
                //
                // Root fix untuk masalah: TinyMCE insertContent floating span
                // di-wrap dalam <p>. <p> tanpa `.floating-only` class render dgn
                // full line-height → empty line visible → judul dokumen shifted
                // down. Delete <p> hapus floating span inside.
                //
                // Mirror server-side detection di generate.php's renderContent()
                // regex-based `.floating-only` marking. Client-side version bikin
                // preview editor match rendered output.
                //
                // Undo-safe: undoManager.ignore() supaya classification tidak
                // trigger dirty state saat initial load (existing template).
                function applyFloatingOnlyClasses() {
                    const doc = editor.getDoc();
                    if (!doc) return;
                    const floatingSel = '.logo-placeholder.floating, .ttd-placeholder.floating, .qr-placeholder.floating, .materai-placeholder.floating';

                    editor.undoManager.ignore(() => {
                        doc.querySelectorAll('p').forEach(p => {
                            const hasFloating = p.querySelector(floatingSel);
                            if (!hasFloating) {
                                p.classList.remove('floating-only');
                                return;
                            }
                            // Extract non-floating text content:
                            // - Remove floating elements
                            // - Remove <br> (TinyMCE inserts bogus <br> untuk cursor placement)
                            const clone = p.cloneNode(true);
                            clone.querySelectorAll(floatingSel).forEach(el => el.remove());
                            clone.querySelectorAll('br').forEach(br => br.remove());
                            const remainingText = (clone.textContent || '').replace(/ |\s/g, '').trim();

                            if (remainingText === '') {
                                // Widget wrapper pattern — .floating-only class +
                                // contenteditable="false" untuk cursor protection.
                                // CKEditor 5 non-editable atomic block precedent.
                                p.classList.add('floating-only');
                                p.setAttribute('contenteditable', 'false');
                            } else {
                                p.classList.remove('floating-only');
                                p.removeAttribute('contenteditable');
                            }
                        });
                    });
                }
                window.ezdocApplyFloatingOnlyClasses = applyFloatingOnlyClasses;

                // Debounced scan: single timer prevents queueing dozens of
                // setTimeout callbacks during rapid typing (was 4× per keystroke).
                let _scanTimer = null;
                editor.on('change keyup', () => {
                    if (_scanTimer) clearTimeout(_scanTimer);
                    _scanTimer = setTimeout(() => {
                        _scanTimer = null;
                        scanLogos();
                        scanTtdPlaceholders();
                        scanMateraiPlaceholders();
                        scanFieldPlaceholders();
                        scanQrPlaceholders();
                        scanCondSections();
                        scanDynTables();
                        applyFloatingOnlyClasses();
                    }, 300);
                });

                // Click-to-focus sidebar card — VS Code Outline / Figma Layers pattern.
                // Click placeholder di editor → auto expand + scroll ke sub-card matching di panel kanan.
                // Ringan: single delegated listener, DOM walk max 8 levels, no re-render.
                editor.on('click', function(e) {
                    if (!window.ezdocFocusSidebarCard) return;
                    let el = e.target;
                    let type = null, id = null, depth = 0;
                    while (el && el !== editor.getBody() && depth < 8) {
                        if (el.classList) {
                            if (el.classList.contains('field-placeholder')) {
                                // Field name dari data-name attr (preferred) atau parse text {{name}}
                                id = el.getAttribute('data-name') || '';
                                if (!id) {
                                    const m = (el.textContent || '').match(/\{\{([^}]+)\}\}/);
                                    if (m) id = m[1];
                                }
                                if (id) { type = 'field'; break; }
                            }
                            if (el.classList.contains('ttd-placeholder')) {
                                id = el.getAttribute('data-ttd') || '';
                                if (id) { type = 'ttd'; break; }
                            }
                            if (el.classList.contains('materai-placeholder')) {
                                id = el.getAttribute('data-materai') || '';
                                if (id) { type = 'materai'; break; }
                            }
                            if (el.classList.contains('logo-placeholder')) {
                                id = el.getAttribute('data-logo') || el.getAttribute('data-id') || '';
                                if (id) { type = 'logo'; break; }
                            }
                            if (el.classList.contains('qr-placeholder')) {
                                id = el.getAttribute('data-qr') || '';
                                if (id) { type = 'qr'; break; }
                            }
                            if (el.classList.contains('conditional-section')) {
                                id = el.getAttribute('data-cond-id') || '';
                                if (id) { type = 'cond'; break; }
                            }
                        }
                        el = el.parentElement;
                        depth++;
                    }
                    if (type && id) window.ezdocFocusSidebarCard(type, id);
                });
                editor.on('init', function() {
                    // Wait for iframe to be fully ready
                    setTimeout(function() {
                        loadPageSettings();
                        scanLogos();
                        scanTtdPlaceholders();
                        scanMateraiPlaceholders();
                        scanFieldPlaceholders();
                        scanQrPlaceholders();
                        scanCondSections();
                        scanDynTables();
                        if (typeof renderTabledbList === 'function') renderTabledbList();
                        updateAllLogosInEditor(); // Show actual logos
                        initLogoDrag();
                        initTtdDrag();
                        // Existing floating elements dari saved template harus dapat
                        // .floating-only class supaya wrapper <p> collapse. Kalau tidak,
                        // template lama yg punya floating logo/TTD akan show extra
                        // empty line di top ketika di-load ke editor.
                        applyFloatingOnlyClasses();

                        // Force proper height after init
                        const height = getEditorHeight();
                        const container = editor.getContainer();
                        if (container) {
                            container.style.height = height + 'px';
                            const iframe = container.querySelector('iframe');
                            if (iframe) {
                                iframe.style.height = '100%';
                            }
                        }
                    }, 150);
                });

                // Drag state (using event delegation)
                let dragState = {
                    active: false,
                    element: null,
                    startX: 0,
                    startY: 0,
                    startLeft: 0,
                    startTop: 0
                };

                // Initialize drag for logos (wrapper function)
                function initLogoDrag() {
                    setupDragDelegation();
                    restoreFloatingPositions();
                }

                // Initialize drag for TTD (wrapper function)
                function initTtdDrag() {
                    setupDragDelegation();
                    restoreFloatingPositions();
                }

                // Restore positions and styles for floating elements
                function restoreFloatingPositions() {
                    const iframeDoc = editor.getDoc();
                    if (!iframeDoc) return;

                    // Restore logo positions
                    iframeDoc.querySelectorAll('.logo-placeholder.floating').forEach(el => {
                        const posX = el.getAttribute('data-pos-x');
                        const posY = el.getAttribute('data-pos-y');
                        if (posX) el.style.left = posX + 'px';
                        if (posY) el.style.top = posY + 'px';
                        el.style.position = 'absolute';
                        el.style.cursor = 'move';
                    });

                    // Restore TTD positions
                    iframeDoc.querySelectorAll('.ttd-placeholder.floating').forEach(el => {
                        const posX = el.getAttribute('data-pos-x');
                        const posY = el.getAttribute('data-pos-y');
                        if (posX) el.style.left = posX + 'px';
                        if (posY) el.style.top = posY + 'px';
                        el.style.position = 'absolute';
                        el.style.cursor = 'move';
                    });

                    // Restore QR positions
                    iframeDoc.querySelectorAll('.qr-placeholder.floating').forEach(el => {
                        const posX = el.getAttribute('data-pos-x');
                        const posY = el.getAttribute('data-pos-y');
                        if (posX) el.style.left = posX + 'px';
                        if (posY) el.style.top = posY + 'px';
                        el.style.position = 'absolute';
                        el.style.cursor = 'move';
                    });

                    // Restore Materai positions
                    iframeDoc.querySelectorAll('.materai-placeholder.floating').forEach(el => {
                        const posX = el.getAttribute('data-pos-x');
                        const posY = el.getAttribute('data-pos-y');
                        if (posX) el.style.left = posX + 'px';
                        if (posY) el.style.top = posY + 'px';
                        el.style.position = 'absolute';
                        el.style.cursor = 'move';
                    });

                    console.log('Restored floating positions');
                }

                // Setup drag using event delegation (once)
                function setupDragDelegation() {
                    const iframeDoc = editor.getDoc();
                    if (!iframeDoc || iframeDoc._dragSetup) return;
                    iframeDoc._dragSetup = true;

                    iframeDoc.addEventListener('mousedown', function(e) {
                        const target = e.target.closest('.logo-placeholder.floating, .ttd-placeholder.floating, .qr-placeholder.floating, .materai-placeholder.floating');
                        if (!target || e.button !== 0) return;

                        e.preventDefault();
                        e.stopPropagation();

                        dragState.active = true;
                        dragState.element = target;
                        dragState.startX = e.clientX;
                        dragState.startY = e.clientY;
                        dragState.startLeft = parseInt(target.style.left) || 0;
                        dragState.startTop = parseInt(target.style.top) || 0;
                        target.style.opacity = '0.8';
                    });

                    iframeDoc.addEventListener('mousemove', function(e) {
                        if (!dragState.active || !dragState.element) return;
                        e.preventDefault();

                        const dx = e.clientX - dragState.startX;
                        const dy = e.clientY - dragState.startY;

                        dragState.element.style.left = Math.max(0, dragState.startLeft + dx) + 'px';
                        dragState.element.style.top = Math.max(0, dragState.startTop + dy) + 'px';
                    });

                    iframeDoc.addEventListener('mouseup', function() {
                        if (!dragState.active || !dragState.element) return;

                        const el = dragState.element;
                        el.style.opacity = '1';

                        // Update data attributes — NaN guard supaya tidak overwrite jadi 0 kalau style kosong
                        const leftVal = parseInt(el.style.left);
                        const topVal = parseInt(el.style.top);
                        if (!isNaN(leftVal)) el.setAttribute('data-pos-x', leftVal);
                        if (!isNaN(topVal)) el.setAttribute('data-pos-y', topVal);

                        // Check type and scan
                        if (el.classList.contains('logo-placeholder')) {
                            setTimeout(scanLogos, 100);
                        } else if (el.classList.contains('ttd-placeholder')) {
                            setTimeout(scanTtdPlaceholders, 100);
                        } else if (el.classList.contains('materai-placeholder')) {
                            setTimeout(scanMateraiPlaceholders, 100);
                        }

                        editor.setDirty(true);
                        dragState.active = false;
                        dragState.element = null;
                    });

                    console.log('Drag delegation setup complete');
                }

                // ─── Floating position preservation across setContent calls ───
                // Root fix: setiap kali content di-modify via setContent (dari updateTtdAttr,
                // renameField, updateLogoMode, dll), TinyMCE parse ulang content — inline style
                // (position: absolute; left/top) bisa hilang atau dikirim tanpa style attribute.
                //
                // Solusi 2-arah:
                //   BeforeSetContent → sync inline style saat ini ke data-pos-x/y (safeguard
                //                       data sebelum di-flush). NaN guard supaya tidak overwrite
                //                       jadi 0 kalau style memang kosong.
                //   SetContent       → restore langsung ke inline style dari data-pos-x/y.
                //                       Panggil SYNC (tanpa setTimeout) supaya konsisten
                //                       sebelum operasi berikutnya.
                editor.on('BeforeSetContent', function() {
                    try {
                        const iframeDoc = editor.getDoc();
                        if (!iframeDoc) return;
                        iframeDoc.querySelectorAll(
                            '.logo-placeholder.floating, .ttd-placeholder.floating, .qr-placeholder.floating, .materai-placeholder.floating'
                        ).forEach(el => {
                            const sLeft = el.style.left, sTop = el.style.top;
                            if (sLeft !== '' && sLeft !== undefined) {
                                const l = parseInt(sLeft);
                                if (!isNaN(l)) el.setAttribute('data-pos-x', l);
                            }
                            if (sTop !== '' && sTop !== undefined) {
                                const t = parseInt(sTop);
                                if (!isNaN(t)) el.setAttribute('data-pos-y', t);
                            }
                        });
                    } catch (e) { console.warn('BeforeSetContent sync failed:', e); }
                });

                // Re-init after content changes — SYNC restore (tidak lagi 100ms delayed)
                // supaya kalau ada setContent chain berturut-turut, tiap intermediate state punya
                // inline style yang correct sebelum di-getContent lagi.
                editor.on('SetContent', function() {
                    try {
                        restoreFloatingPositions();
                        setupDragDelegation();
                        // Auto-classify floating-only paragraphs after content loads
                        // (existing floating elements from saved template need `.floating-only`
                        // class supaya wrapper <p> collapse ke 0 height).
                        applyFloatingOnlyClasses();
                        // updateAllLogosInEditor tetap async supaya lookup logo dari sidebar
                        // tidak blocking (misal lookup image src by name)
                        setTimeout(updateAllLogosInEditor, 50);
                    } catch (e) { console.warn('SetContent restore failed:', e); }
                });
            }
        });
        <?php endif; ?>

        // ===== BEFOREUNLOAD (conditional — only when dirty) =====
        // Industry pattern: track dirty state, prompt hanya kalau ada unsaved
        // changes. Native browser prompt tidak bisa di-style (browser
        // restriction) tapi konten text sudah "Changes you made may not be
        // saved" — standard user-recognizable copy.
        //
        // Manual flag `window._ezdocDirty` di-set:
        // - Editor content change → editor.on('dirty', ...) auto-set true
        // - Manual save flow (saveTemplate) → reset false setelah 200 OK
        // - Sidebar-only changes (add TTD/materai/logo/QR/kondisi/rename field)
        //   already call editor.setDirty(true) — captured by dirty listener
        window._ezdocDirty = false;
        (function () {
            function bindDirtyTracking() {
                const editor = tinymce.get('editor');
                if (!editor) { setTimeout(bindDirtyTracking, 200); return; }
                editor.on('dirty', function () { window._ezdocDirty = true; });
                // Reset saat content di-set programmatically (mis. undo ke initial).
                editor.on('SetContent', function () {
                    if (!editor.isDirty()) window._ezdocDirty = false;
                });
            }
            bindDirtyTracking();

            window.addEventListener('beforeunload', function (e) {
                if (!window._ezdocDirty) return; // clean → allow leave silently
                // Native prompt — browser show generic message.
                // Custom returnValue ignored di modern browsers, tapi wajib set
                // supaya prompt trigger di legacy browser.
                e.preventDefault();
                e.returnValue = '';
                return '';
            });

            // Public helper: dipanggil saveTemplate() success handler + save flows
            // buat clear dirty flag.
            window.ezdocMarkClean = function () {
                window._ezdocDirty = false;
                const editor = tinymce.get('editor');
                if (editor) editor.setDirty(false);
            };
        })();

        // ===== TAILWIND DIALOG HELPER =====
        // ezdocAlert / ezdocConfirm moved to layout.php — shared across all
        // ezdoc pages. Kalau layout tidak load (edge case: standalone view),
        // fallback ke native alert/confirm.
        if (!window.ezdocAlert) {
            window.ezdocAlert = function (msg) { alert(msg); return Promise.resolve(true); };
            window.ezdocConfirm = function (msg) { return Promise.resolve(confirm(msg)); };
        }


        // ===== FIELD PLACEHOLDERS =====
        // spec: ezdoc-spec/protocol/template-content.md#field-markers
        function scanFieldPlaceholders() {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const content = editor.getContent();
            const fieldData = [];

            // Parse field placeholders: <span class="field-placeholder" data-type="..." data-options="..." data-label="...">{{name}}</span>
            const regex = /<span[^>]*class="[^"]*field-placeholder[^"]*"([^>]*)>\{\{([^}]+)\}\}<\/span>/g;
            let match;

            while ((match = regex.exec(content)) !== null) {
                const attrs = match[1];
                const name = match[2];

                // Parse attributes
                let type = 'text';
                let options = '';
                let label = '';

                const typeMatch = attrs.match(/data-type="([^"]+)"/);
                if (typeMatch) type = typeMatch[1];

                const optionsMatch = attrs.match(/data-options="([^"]+)"/);
                if (optionsMatch) options = optionsMatch[1].replace(/&quot;/g, '"');

                const labelMatch = attrs.match(/data-label="([^"]+)"/);
                if (labelMatch) label = labelMatch[1].replace(/&quot;/g, '"');

                let defaultVal = '';
                const defaultMatch = attrs.match(/data-default="([^"]+)"/);
                if (defaultMatch) defaultVal = defaultMatch[1].replace(/&quot;/g, '"');

                // Validation attributes (#6)
                const required = /data-required="1"/.test(attrs);
                const minMatch = attrs.match(/data-min="([^"]+)"/);
                const maxMatch = attrs.match(/data-max="([^"]+)"/);
                const patternMatch = attrs.match(/data-pattern="([^"]+)"/);
                const errMsgMatch = attrs.match(/data-error-msg="([^"]+)"/);
                const min = minMatch ? minMatch[1] : '';
                const max = maxMatch ? maxMatch[1] : '';
                const pattern = patternMatch ? patternMatch[1].replace(/&quot;/g, '"') : '';
                const errorMsg = errMsgMatch ? errMsgMatch[1].replace(/&quot;/g, '"') : '';

                fieldData.push({ name, type, options, label, defaultVal, required, min, max, pattern, errorMsg });
            }

            renderFieldPanel(fieldData);
        }

        function renderFieldPanel(fields) {
            const list = document.getElementById('fieldList');
            const empty = document.getElementById('fieldEmpty');
            const countBadge = document.getElementById('fieldCount');
            if (!list) return;

            // Preserve open state of <details> validation panels + active focus
            // Keys are field names so they survive re-render.
            const openDetails = new Set();
            list.querySelectorAll('details[data-field-details]').forEach(d => {
                if (d.open) openDetails.add(d.getAttribute('data-field-details'));
            });
            const activeEl = document.activeElement;
            const activeFieldName = (activeEl && list.contains(activeEl))
                ? activeEl.closest('[data-field-details], [data-field-row]')?.getAttribute('data-field-details')
                  || activeEl.closest('[data-field-row]')?.getAttribute('data-field-row')
                : null;
            const activeAttr = activeEl?.getAttribute('data-validate-key') || null;
            const activeSelStart = activeEl?.selectionStart;
            const activeSelEnd = activeEl?.selectionEnd;

            // Update count badge
            if (countBadge) countBadge.textContent = fields.length;

            if (fields.length === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';

            const typeLabels = {
                'text': t('field_type.text', {}, 'Text'),
                'number': t('field_type.number', {}, 'Number'),
                'date': t('field_type.date', {}, 'Date'),
                'checkbox': t('field_type.checkbox', {}, 'Checkbox'),
                'radio': t('field_type.radio', {}, 'Radio'),
                'select': t('field_type.select', {}, 'Select')
            };

            // Type badge + icon per field type (mirror list.php Status badge + icon)
            const typeBadgeClasses = {
                'text':     'bg-blue-50 text-blue-700 ring-blue-200',
                'number':   'bg-amber-50 text-amber-700 ring-amber-200',
                'date':     'bg-violet-50 text-violet-700 ring-violet-200',
                'checkbox': 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                'radio':    'bg-pink-50 text-pink-700 ring-pink-200',
                'select':   'bg-purple-50 text-purple-700 ring-purple-200'
            };
            // Header gradient bg per type — subtle color hint for visual variety
            // (Airtable/Linear record-color pattern) tanpa going full-rainbow
            const typeHeaderBg = {
                'text':     'from-blue-50/70 to-white',
                'number':   'from-amber-50/70 to-white',
                'date':     'from-violet-50/70 to-white',
                'checkbox': 'from-emerald-50/70 to-white',
                'radio':    'from-pink-50/70 to-white',
                'select':   'from-purple-50/70 to-white'
            };
            // Left accent border color per type — Airtable "record color" pattern
            const typeAccent = {
                'text':     'before:bg-blue-400',
                'number':   'before:bg-amber-400',
                'date':     'before:bg-violet-400',
                'checkbox': 'before:bg-emerald-400',
                'radio':    'before:bg-pink-400',
                'select':   'before:bg-purple-400'
            };
            const typeIcons = {
                'text':     'bi-fonts',
                'number':   'bi-123',
                'date':     'bi-calendar-event',
                'checkbox': 'bi-check-square',
                'radio':    'bi-record-circle',
                'select':   'bi-chevron-expand'
            };

            list.innerHTML = fields.map((field, i) => {
                const needsOptions = ['radio', 'select'].includes(field.type);
                const needsLabel = ['checkbox'].includes(field.type);
                const badgeCls = typeBadgeClasses[field.type] || typeBadgeClasses['text'];
                const headerBg  = typeHeaderBg[field.type] || typeHeaderBg['text'];
                const accentCls = typeAccent[field.type] || typeAccent['text'];
                const tLabel = typeLabels[field.type] || field.type;
                const tIcon = typeIcons[field.type] || typeIcons['text'];
                const eName = escapeHtml(field.name);
                const eLabel = escapeHtml(field.label || '');
                const eOptions = escapeHtml(field.options || '');
                const eDefault = escapeHtml(field.defaultVal || '');

                const searchText = (eName + ' ' + (field.label || '') + ' ' + field.type).toLowerCase();
                return `
                <div class="mb-2 bg-white border border-gray-200 rounded-md overflow-hidden shadow-sm hover:border-gray-300 hover:shadow-md transition-all panel-list-item relative before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 ${accentCls} before:content-['']" data-search-text="${escapeHtml(searchText)}" data-focus-target="field:${eName}">
                    <!-- Card Header — single line inline dengan type color hint -->
                    <div class="flex items-center gap-1.5 pl-3 pr-2 py-1.5 bg-gradient-to-b ${headerBg} border-b border-gray-200">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded ring-1 ring-inset ${badgeCls} shrink-0"><i class="bi ${tIcon} text-[10px]"></i></span>
                        <code class="text-xs font-mono text-gray-900 truncate flex-1 min-w-0">{{${eName}}}</code>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-gray-600 bg-white/80 ring-1 ring-inset ring-gray-200 shrink-0">${tLabel}</span>
                        <button type="button" class="inline-flex items-center p-0.5 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 shrink-0" onclick="removeField('${eName}')" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-trash text-xs"></i></button>
                    </div>
                    <!-- Card Body — compact 2-column grid, tight spacing -->
                    <div class="pl-3 pr-2 py-2 space-y-1.5 bg-gradient-to-b from-white to-gray-50/30">
                        <!-- Row 1: Nama (2/3) + Tipe (1/3) -->
                        <div class="grid grid-cols-3 gap-1.5">
                            <div class="col-span-2">
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.name_label', {}, 'Name')}</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 font-mono bg-white" value="${eName}" onchange="updateFieldName('${eName}', this.value)">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.type_label', {}, 'Type')}</label>
                                <select class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-1.5 py-1 bg-white" onchange="updateFieldType('${eName}', this.value)">
                                    <option value="text" ${field.type === 'text' ? 'selected' : ''}>${t('field_type.text', {}, 'Text')}</option>
                                    <option value="number" ${field.type === 'number' ? 'selected' : ''}>${t('field_type.number', {}, 'Number')}</option>
                                    <option value="date" ${field.type === 'date' ? 'selected' : ''}>${t('field_type.date', {}, 'Date')}</option>
                                    <option value="checkbox" ${field.type === 'checkbox' ? 'selected' : ''}>${t('field_type.checkbox', {}, 'Checkbox')}</option>
                                    <option value="radio" ${field.type === 'radio' ? 'selected' : ''}>${t('field_type.radio', {}, 'Radio')}</option>
                                    <option value="select" ${field.type === 'select' ? 'selected' : ''}>${t('field_type.select', {}, 'Select')}</option>
                                </select>
                            </div>
                        </div>
                        <!-- Row 2: Default value — full width -->
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.default_label', {}, 'Default')}</label>
                            <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${eDefault}" onchange="updateFieldDefault('${eName}', this.value)" placeholder="text, date:d F Y, \$author_nama">
                        </div>
                        <!-- Row 3: Conditional (Label / Opsi) -->
                        ${needsLabel ? `
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.label_label', {}, 'Label')} <span class="text-gray-400 font-normal normal-case">${t('field.label_hint', {}, '(text next to the checkbox)')}</span></label>
                            <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${eLabel}" onchange="updateFieldLabel('${eName}', this.value)" placeholder="${t('field.label_placeholder', {}, '(leave empty if no label)')}">
                        </div>
                        ` : ''}
                        ${needsOptions ? `
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.options_label', {}, 'Options')} <span class="text-gray-400 font-normal normal-case">${t('field.options_hint', {}, '(comma-separated)')}</span></label>
                            <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${eOptions}" onchange="updateFieldOptions('${eName}', this.value)" placeholder="${t('field.options_example', {}, 'Yes, No, Maybe')}">
                        </div>
                        ` : ''}
                    </div>
                    <!-- Card Footer: Validasi collapsible -->
                    <details class="border-t border-gray-200 group bg-gray-50/40" data-field-details="${eName}">
                        <summary class="cursor-pointer text-[11px] font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100/60 flex items-center gap-1.5 pl-3 pr-2 py-1.5 select-none list-none transition-colors">
                            <i class="bi bi-chevron-right text-[10px] text-gray-400 transition-transform group-open:rotate-90"></i>
                            <i class="bi bi-shield-check text-[10px]"></i>
                            ${t('field_validation.validation_summary_label', {}, 'Validation')}
                        </summary>
                        <div class="pl-3 pr-2 py-1.5 bg-gray-50 border-t border-gray-200/60 space-y-1.5" data-field-row="${eName}">
                            <label class="flex items-center gap-1.5 text-[11px] text-gray-700 cursor-pointer">
                                <input class="rounded border-gray-400 text-gray-800 focus:ring-1 focus:ring-gray-400 shadow-sm" type="checkbox" id="req_${eName}" ${field.required ? 'checked' : ''} data-validate-key="required-${eName}" onchange="updateFieldValidation('${eName}', 'required', this.checked ? '1' : '')">
                                ${t('field_validation.required_label', {}, 'Required')}
                            </label>
                            ${['text', 'number'].includes(field.type) ? `
                            <div class="grid grid-cols-2 gap-1.5">
                                <div>
                                    <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field_validation.min_label', {}, 'Min')}</label>
                                    <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${escapeHtml(field.min || '')}" data-validate-key="min-${eName}" onchange="updateFieldValidation('${eName}', 'min', this.value)" placeholder="${field.type === 'number' ? t('field_validation.min_placeholder_number', {}, 'value') : t('field_validation.min_placeholder_text', {}, 'length')}">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field_validation.max_label', {}, 'Max')}</label>
                                    <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${escapeHtml(field.max || '')}" data-validate-key="max-${eName}" onchange="updateFieldValidation('${eName}', 'max', this.value)" placeholder="${field.type === 'number' ? t('field_validation.min_placeholder_number', {}, 'value') : t('field_validation.min_placeholder_text', {}, 'length')}">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field_validation.regex_label', {}, 'Regex')}</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 font-mono bg-white" value="${escapeHtml(field.pattern || '')}" data-validate-key="pattern-${eName}" onchange="updateFieldValidation('${eName}', 'pattern', this.value)" placeholder="${t('field_validation.regex_placeholder', {}, '^[0-9]+$ (optional)')}">
                            </div>
                            ` : ''}
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field_validation.error_msg_label', {}, 'Error message')}</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${escapeHtml(field.errorMsg || '')}" data-validate-key="errorMsg-${eName}" onchange="updateFieldValidation('${eName}', 'errorMsg', this.value)" placeholder="${t('field_validation.error_msg_placeholder', {}, 'Message when invalid')}">
                            </div>
                        </div>
                    </details>
                </div>`;
            }).join('');

            // Restore previously-open <details> + focus + selection
            list.querySelectorAll('details[data-field-details]').forEach(d => {
                if (openDetails.has(d.getAttribute('data-field-details'))) d.open = true;
            });
            if (activeAttr) {
                const restoreEl = list.querySelector(`[data-validate-key="${activeAttr}"]`);
                if (restoreEl) {
                    try { restoreEl.focus(); } catch (e) {}
                    if (typeof activeSelStart === 'number' && typeof restoreEl.setSelectionRange === 'function') {
                        try { restoreEl.setSelectionRange(activeSelStart, activeSelEnd); } catch (e) {}
                    }
                }
            }

            // Re-apply filter if search has value (preserves filter across re-render)
            const fs = document.getElementById('fieldSearch');
            if (fs && fs.value) filterPanel('fieldList', fs.value);
        }

        // ===== Generic panel filter (search) =====
        function filterPanel(listId, query) {
            const list = document.getElementById(listId);
            if (!list) return;
            const q = (query || '').trim().toLowerCase();
            const items = list.querySelectorAll('.panel-list-item');
            let visibleCount = 0;
            items.forEach(it => {
                if (!q) { it.classList.remove('panel-list-item-hidden'); visibleCount++; return; }
                const text = it.getAttribute('data-search-text') || '';
                const match = text.includes(q);
                it.classList.toggle('panel-list-item-hidden', !match);
                if (match) visibleCount++;
            });
            // Show "no match" message if filtered to zero
            const noMatchEl = document.getElementById(listId.replace('List', 'NoMatch'));
            if (noMatchEl) noMatchEl.style.display = (q && visibleCount === 0 && items.length > 0) ? 'block' : 'none';
        }

        function clearPanelSearch(searchId, listId) {
            const inp = document.getElementById(searchId);
            if (inp) inp.value = '';
            filterPanel(listId, '');
            if (inp) inp.focus();
        }

        // Update validation attribute on field placeholder span (does NOT re-render panel
        // unless attribute write actually changed something; preserves <details> open + focus)
        function updateFieldValidation(name, attrKey, value) {
            const editor = tinymce.get('editor');
            if (!editor) return;
            const dataAttrMap = {
                required: 'data-required',
                min: 'data-min',
                max: 'data-max',
                pattern: 'data-pattern',
                errorMsg: 'data-error-msg'
            };
            const dataAttr = dataAttrMap[attrKey];
            if (!dataAttr) return;

            let content = editor.getContent();
            const before = content;
            const escapedVal = String(value).replace(/"/g, '&quot;');
            const regex = new RegExp(`<span([^>]*class="[^"]*field-placeholder[^"]*")([^>]*)>\\{\\{${name}\\}\\}<\\/span>`, 'g');
            content = content.replace(regex, (match, classAttr, restAttrs) => {
                let newRest = restAttrs.replace(new RegExp(`\\s*${dataAttr}="[^"]*"`, 'g'), '');
                if (value !== '' && value !== null) newRest += ` ${dataAttr}="${escapedVal}"`;
                return `<span${classAttr}${newRest}>{{${name}}}</span>`;
            });
            if (content === before) return; // no actual change → skip re-render
            editor.setContent(content);
            // re-scan only if value actually changed (preserves <details> open + focus via restore in renderFieldPanel)
            scanFieldPlaceholders();
        }

        function updateFieldName(oldName, newName) {
            if (!newName || newName === oldName) return;
            newName = newName.trim().replace(/\s+/g, '_').toLowerCase();

            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            // Update the field name in the placeholder
            const regex = new RegExp(`(<span[^>]*class="[^"]*field-placeholder[^"]*"[^>]*>)\\{\\{${oldName}\\}\\}(<\\/span>)`, 'g');
            content = content.replace(regex, `$1{{${newName}}}$2`);
            editor.setContent(content);
            scanFieldPlaceholders();
        }

        function updateFieldType(name, newType) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            // Find and update the field placeholder
            const regex = new RegExp(`<span([^>]*class="[^"]*field-placeholder[^"]*")([^>]*)>\\{\\{${name}\\}\\}<\\/span>`, 'g');

            content = content.replace(regex, (match, classAttr, restAttrs) => {
                // Remove old data-type if exists
                restAttrs = restAttrs.replace(/\s*data-type="[^"]*"/g, '');
                // Add new data-type
                return `<span${classAttr} data-type="${newType}"${restAttrs}>{{${name}}}</span>`;
            });

            editor.setContent(content);
            scanFieldPlaceholders();
        }

        function updateFieldOptions(name, options) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const escapedOptions = options.replace(/"/g, '&quot;');

            const regex = new RegExp(`<span([^>]*class="[^"]*field-placeholder[^"]*")([^>]*)>\\{\\{${name}\\}\\}<\\/span>`, 'g');

            content = content.replace(regex, (match, classAttr, restAttrs) => {
                // Remove old data-options if exists
                restAttrs = restAttrs.replace(/\s*data-options="[^"]*"/g, '');
                // Add new data-options
                return `<span${classAttr}${restAttrs} data-options="${escapedOptions}">{{${name}}}</span>`;
            });

            editor.setContent(content);
            scanFieldPlaceholders();
        }

        function updateFieldLabel(name, label) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const escapedLabel = label.replace(/"/g, '&quot;');

            const regex = new RegExp(`<span([^>]*class="[^"]*field-placeholder[^"]*")([^>]*)>\\{\\{${name}\\}\\}<\\/span>`, 'g');

            content = content.replace(regex, (match, classAttr, restAttrs) => {
                // Remove old data-label if exists
                restAttrs = restAttrs.replace(/\s*data-label="[^"]*"/g, '');
                // Add new data-label
                return `<span${classAttr}${restAttrs} data-label="${escapedLabel}">{{${name}}}</span>`;
            });

            editor.setContent(content);
            scanFieldPlaceholders();
        }

        function updateFieldDefault(name, defaultVal) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const escapedDefault = defaultVal.replace(/"/g, '&quot;');

            const regex = new RegExp(`<span([^>]*class="[^"]*field-placeholder[^"]*")([^>]*)>\\{\\{${name}\\}\\}<\\/span>`, 'g');

            content = content.replace(regex, (match, classAttr, restAttrs) => {
                // Remove old data-default if exists
                restAttrs = restAttrs.replace(/\s*data-default="[^"]*"/g, '');
                // Add new data-default (only if not empty)
                const defaultAttr = escapedDefault ? ` data-default="${escapedDefault}"` : '';
                return `<span${classAttr}${restAttrs}${defaultAttr}>{{${name}}}</span>`;
            });

            editor.setContent(content);
            scanFieldPlaceholders();
        }

        async function removeField(name) {
            if (!(await ezdocConfirm(t('confirm.delete_field', {name: name}, 'Delete field {{{name}}}?'), { title: 'Delete Field', variant: 'danger', confirmText: 'Delete' }))) return;

            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            // Remove the field placeholder
            const regex = new RegExp(`<span[^>]*class="[^"]*field-placeholder[^"]*"[^>]*>\\{\\{${name}\\}\\}<\\/span>(&nbsp;)?`, 'g');
            content = content.replace(regex, '');
            editor.setContent(content);
            scanFieldPlaceholders();
        }

        // ===== QR PLACEHOLDERS =====
        function scanQrPlaceholders() {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const content = editor.getContent();
            const qrData = [];

            // Parse QR placeholders
            const regex = /<span[^>]*class="([^"]*qr-placeholder[^"]*)"[^>]*data-qr="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>/g;
            let match;

            while ((match = regex.exec(content)) !== null) {
                const classes = match[1];
                const isFloating = classes.includes('floating');
                const isBehind = classes.includes('behind');

                qrData.push({
                    name: match[2],
                    width: match[3] || '80px',
                    mode: isFloating ? (isBehind ? 'behind' : 'front') : 'inline',
                    posX: match[4] || '20',
                    posY: match[5] || '20'
                });
            }

            renderQrPanel(qrData);
        }

        function renderQrPanel(qrs) {
            const list = document.getElementById('qrList');
            const empty = document.getElementById('qrEmpty');
            const countBadge = document.getElementById('qrCount');
            if (!list) return;

            // Update count badge
            if (countBadge) countBadge.textContent = qrs.length;

            if (qrs.length === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';

            list.innerHTML = qrs.map(qr => {
                const mode = qr.mode || 'inline';
                const isFloating = mode !== 'inline';
                const eName = escapeHtml(qr.name);
                const eWidth = escapeHtml(qr.width);

                return `
                <div class="mb-2 p-2 bg-gray-100 rounded panel-list-item" style="border-left: 3px solid #06b6d4;" data-focus-target="qr:${eName}">
                    <div class="flex justify-between items-center mb-1">
                        <strong class="text-xs" style="color:#06b6d4;"><i class="bi bi-qr-code mr-1"></i>${eName}</strong>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="removeQr('${eName}')"><i class="bi bi-trash"></i></button>
                    </div>
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">${t('field.name_label', {}, 'Name')}</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eName}" onchange="updateQrName('${eName}', this.value)">
                    </div>
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">W</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eWidth}" onchange="updateQrWidth('${eName}', this.value)">
                    </div>
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateQrMode('${eName}', this.value)">
                        <option value="inline" ${mode === 'inline' ? 'selected' : ''}>${t('mode.inline', {}, 'Inline')}</option>
                        <option value="front" ${mode === 'front' ? 'selected' : ''}>${t('mode.floating_front', {}, 'Floating Front')}</option>
                        <option value="behind" ${mode === 'behind' ? 'selected' : ''}>${t('mode.floating_behind', {}, 'Floating Behind')}</option>
                    </select>
                    ${isFloating ? `
                    <div class="grid grid-cols-2 gap-1">
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">X</span>
                            <input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${qr.posX}" onchange="updateQrPos('${eName}', 'x', this.value)">
                        </div>
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Y</span>
                            <input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${qr.posY}" onchange="updateQrPos('${eName}', 'y', this.value)">
                        </div>
                    </div>
                    <small class="text-gray-500 text-xs">${t('panel.drag_hint', {item: 'QR'}, 'Drag {item} in the editor to change its position')}</small>
                    ` : ''}
                </div>`;
            }).join('');
        }

        function updateQrName(oldName, newName) {
            if (!newName || newName === oldName) return;
            newName = newName.trim().replace(/\s+/g, '_').toLowerCase();

            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const regex = new RegExp(`(<span[^>]*class="[^"]*qr-placeholder[^"]*"[^>]*)data-qr="${oldName}"`, 'g');
            content = content.replace(regex, `$1data-qr="${newName}"`);

            // Also update the display text
            content = content.replace(new RegExp(`(data-qr="${newName}"[^>]*>)[^<]*`, 'g'), `$1[QR: ${newName}]`);

            editor.setContent(content);
            scanQrPlaceholders();
        }

        function updateQrWidth(name, width) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const regex = new RegExp(`(<span[^>]*class="[^"]*qr-placeholder[^"]*"[^>]*data-qr="${name}"[^>]*)(?:data-width="[^"]*")?([^>]*>)`, 'g');
            content = content.replace(regex, `$1 data-width="${width}"$2`);
            editor.setContent(content);
            scanQrPlaceholders();
        }

        function updateQrMode(name, mode) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();

            // Find QR placeholder and update classes
            const regex = new RegExp(`<span([^>]*)class="([^"]*qr-placeholder[^"]*)"([^>]*data-qr="${name}"[^>]*)>`, 'g');

            content = content.replace(regex, (match, before, classes, after) => {
                // Remove old mode classes
                classes = classes.replace(/\s*(floating|behind|front)/g, '');

                // Add new mode classes
                if (mode === 'front') {
                    classes += ' floating front';
                } else if (mode === 'behind') {
                    classes += ' floating behind';
                }

                return `<span${before}class="${classes.trim()}"${after}>`;
            });

            editor.setContent(content);

            // Restore positions for floating elements
            setTimeout(() => {
                restoreFloatingPositions();
                scanQrPlaceholders();
            }, 100);
        }

        function updateQrPos(name, axis, value) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const attrName = axis === 'x' ? 'data-pos-x' : 'data-pos-y';

            // Remove old position attr and add new one
            const regex = new RegExp(`(<span[^>]*class="[^"]*qr-placeholder[^"]*"[^>]*data-qr="${name}"[^>]*)(?:\\s*${attrName}="[^"]*")?([^>]*>)`, 'g');
            content = content.replace(regex, `$1 ${attrName}="${value}"$2`);

            editor.setContent(content);

            // Update position in editor
            const iframeDoc = editor.getDoc();
            if (iframeDoc) {
                const el = iframeDoc.querySelector(`.qr-placeholder[data-qr="${name}"]`);
                if (el) {
                    if (axis === 'x') el.style.left = value + 'px';
                    else el.style.top = value + 'px';
                }
            }
        }

        async function removeQr(name) {
            if (!(await ezdocConfirm(t('confirm.delete_qr', {name: name}, 'Delete QR "{name}"?'), { title: 'Delete QR', variant: 'danger', confirmText: 'Delete' }))) return;

            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const regex = new RegExp(`<span[^>]*class="[^"]*qr-placeholder[^"]*"[^>]*data-qr="${name}"[^>]*>[^<]*<\\/span>(&nbsp;)?`, 'g');
            content = content.replace(regex, '');
            editor.setContent(content);
            scanQrPlaceholders();
        }

        // ===== CONDITIONAL SECTIONS =====
        // Scan .conditional-section elements dari iframe body (bukan raw content string)
        // supaya data-cond attribute paths reliable + support element reference untuk scroll/edit.
        function scanCondSections() {
            const editor = tinymce.get('editor');
            if (!editor) return;
            const iframeDoc = editor.getDoc();
            if (!iframeDoc) return;
            const nodes = iframeDoc.querySelectorAll('.conditional-section');
            const items = [];
            nodes.forEach((el, idx) => {
                if (!el.dataset.condId) {
                    el.dataset.condId = 'cond_' + Date.now() + '_' + idx;
                }
                items.push({
                    id: el.dataset.condId,
                    expr: el.getAttribute('data-cond') || ''
                });
            });
            renderCondPanel(items);
        }

        function renderCondPanel(items) {
            const list = document.getElementById('condList');
            const empty = document.getElementById('condEmpty');
            const countBadge = document.getElementById('condCount');
            if (!list) return;
            if (countBadge) countBadge.textContent = items.length;

            if (items.length === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';

            list.innerHTML = items.map((it, idx) => {
                const eExpr = escapeHtml(it.expr);
                const eId = escapeHtml(it.id);
                return `
                <div class="mb-2 bg-white border border-gray-200 rounded-md overflow-hidden shadow-sm hover:border-gray-300 hover:shadow-md transition-all panel-list-item relative before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 before:bg-cyan-400 before:content-['']" data-focus-target="cond:${eId}">
                    <div class="pl-2.5 pr-2 py-1.5">
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-cyan-700"><i class="bi bi-diamond-half"></i> #${idx + 1}</span>
                            <div class="flex gap-1">
                                <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 text-[10px]" onclick="gotoCondSection('${eId}')" title="${t('cond.scroll_to_element_title', {}, 'Scroll to element')}"><i class="bi bi-crosshair"></i></button>
                                <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-500 text-red-600 hover:bg-red-50 text-[10px]" onclick="removeCondSection('${eId}')" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        <label class="block text-[9px] font-semibold uppercase tracking-wide text-gray-400 mb-0.5">${t('cond.expression_label', {}, 'Expression')}</label>
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-[11px] px-2 py-1 font-mono"
                               value="${eExpr}"
                               onchange="updateCondExpr('${eId}', this.value)"
                               placeholder="jenis_kelamin=P">
                    </div>
                </div>`;
            }).join('');
        }

        function _condFindEl(id) {
            const editor = tinymce.get('editor');
            if (!editor) return null;
            const doc = editor.getDoc();
            if (!doc) return null;
            return doc.querySelector(`.conditional-section[data-cond-id="${id}"]`);
        }

        function updateCondExpr(id, expr) {
            const el = _condFindEl(id);
            if (!el) return;
            const trimmed = (expr || '').trim();
            if (!trimmed) return;
            el.setAttribute('data-cond', trimmed);
            // Update badge label di dalam section
            const badge = el.querySelector('div[style*="ecfeff"], div[style*="ECFEFF"]');
            if (badge) {
                badge.innerHTML = t('cond.display_if_prefix', {}, 'Show if:') + ' <strong>' + escapeHtml(trimmed) + '</strong>';
            }
            const editor = tinymce.get('editor');
            if (editor) editor.setDirty(true);
        }

        function gotoCondSection(id) {
            const el = _condFindEl(id);
            if (!el) return;
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Flash highlight
            const oldOutline = el.style.outline;
            el.style.outline = '2px solid #06b6d4';
            el.style.outlineOffset = '2px';
            setTimeout(() => { el.style.outline = oldOutline; el.style.outlineOffset = ''; }, 900);
        }

        async function removeCondSection(id) {
            if (!(await ezdocConfirm(t('cond.confirm_delete', {}, 'Delete this conditional section along with all its content?'), { title: 'Delete Conditional Section', variant: 'danger', confirmText: 'Delete' }))) return;
            const el = _condFindEl(id);
            if (!el) return;
            el.parentNode.removeChild(el);
            const editor = tinymce.get('editor');
            if (editor) editor.setDirty(true);
            scanCondSections();
        }

        // ===== ACTIVE CARD STATE =====
        // Persistent active state — hanya SATU card yg is-active at a time.
        // Industry: VS Code focused outline row, Figma active layer, Notion focus.
        // Call dgn null untuk clear semua.
        window.ezdocSetActivePanelCard = function(cardEl) {
            document.querySelectorAll('.panel-list-item.is-active').forEach(c => {
                if (c !== cardEl) c.classList.remove('is-active');
            });
            if (cardEl) cardEl.classList.add('is-active');
        };

        // Event delegation: klik atau focus input di dalam card → set active.
        // Runs once at page load. Handles all sidebar cards regardless of when
        // they're rendered (delegation dari sidebar container).
        (function() {
            const sidebar = document.querySelector('.sidebar-scroll');
            if (!sidebar) return;
            const setFromEvent = (e) => {
                const card = e.target.closest('.panel-list-item');
                if (card) window.ezdocSetActivePanelCard(card);
            };
            // Click sub-tree: mark active on any card interaction
            sidebar.addEventListener('click', setFromEvent);
            // Focus input/textarea/select: mark parent card active
            sidebar.addEventListener('focusin', setFromEvent);
        })();

        // ===== CLICK-TO-FOCUS SIDEBAR =====
        // Editor click → auto-scroll ke sub-card di panel kanan. VS Code Outline /
        // Figma Layers / Filament Forms pattern. Called by editor.on('click') listener.
        //
        // type: 'field' | 'ttd' | 'materai' | 'logo' | 'qr' | 'cond'
        // id:   identifier unique per type (mis. field name, ttd id, cond-id)
        window.ezdocFocusSidebarCard = function(type, id) {
            const PANEL_MAP = {
                field:   'fieldList',
                ttd:     'ttdList',
                materai: 'materaiList',
                logo:    'logoList',
                qr:      'qrList',
                cond:    'condList',
            };
            const listId = PANEL_MAP[type];
            if (!listId) return;
            const listEl = document.getElementById(listId);
            if (!listEl) return;

            // 1. Expand parent Alpine panel (walk up to find x-data ancestor).
            //    Alpine.$data(el).open = true triggers reactivity + x-collapse animation.
            const alpineRoot = listEl.closest('[x-data]');
            let wasCollapsed = false;
            if (alpineRoot && window.Alpine) {
                try {
                    const data = window.Alpine.$data(alpineRoot);
                    if (data && 'open' in data) {
                        wasCollapsed = !data.open;
                        data.open = true;
                    }
                } catch (e) { /* Alpine not initialized yet — skip */ }
            }

            // 2. Find target card + scroll into view. Delay kalau panel baru expand
            //    supaya scrollIntoView pakai final layout (bukan mid-transition height).
            const cardSel = '[data-focus-target="' + type + ':' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]';
            const doScroll = () => {
                const card = listEl.querySelector(cardSel);
                if (!card) return;
                // Restore visibility kalau filter search sedang aktif
                card.classList.remove('panel-list-item-hidden');
                card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                // Persistent active state — no temporary flash (flash animation
                // over-rode active bg/shadow → flicker perception). Active class
                // alone gives clear, stable visual cue.
                if (window.ezdocSetActivePanelCard) window.ezdocSetActivePanelCard(card);
            };
            setTimeout(doScroll, wasCollapsed ? 320 : 30);
        };

        // ===== DYNAMIC TABLES (LEGACY — REMOVED) =====
        // Replaced by new {{tabledb.<ns>.<col>}} variable system.
        // scanDynTables stub kept for backward compat with editor.on('change keyup') / editor.on('init') hooks.
        function scanDynTables() { /* no-op */ }

        // ===== LOGO PLACEHOLDERS =====
        function scanLogos() {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const content = editor.getContent();
            const logoData = [];
            // Parse logo placeholders with all attributes
            const regex = /<span[^>]*class="([^"]*logo-placeholder[^"]*)"[^>]*data-logo="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>/g;
            let match;
            while ((match = regex.exec(content)) !== null) {
                const classes = match[1];
                const isFloating = classes.includes('floating');
                const isBehind = classes.includes('behind');
                logoData.push({
                    name: match[2],
                    width: match[3] || configHeader.logoSizes[match[2]] || '80px',
                    mode: isFloating ? (isBehind ? 'behind' : 'front') : 'inline',
                    posX: match[5] || '20',
                    posY: match[6] || '20'
                });
            }
            renderLogoPanel(logoData);
        }

        // Scan TTD placeholders from editor content
        function scanTtdPlaceholders() {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const content = editor.getContent();
            const ttdData = [];

            // Parse TTD placeholders — robust to attribute ordering.
            // Step 1: capture every ttd-placeholder opening tag's attribute string.
            // Step 2: extract each data attribute individually so ordering doesn't matter.
            const tagRegex = /<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>/g;
            let match;
            while ((match = tagRegex.exec(content)) !== null) {
                const attrs = match[1];
                const ttdId = (attrs.match(/data-ttd="([^"]+)"/) || [])[1];
                if (!ttdId) continue;

                const classes = (attrs.match(/class="([^"]*)"/) || [])[1] || '';
                const label = (attrs.match(/data-label="([^"]+)"/) || [])[1] || 'Tanda Tangan';
                const namaField = (attrs.match(/data-nama-field="([^"]+)"/) || [])[1] || ('nama_' + ttdId);
                const posX = (attrs.match(/data-pos-x="([^"]+)"/) || [])[1] || '50';
                const posY = (attrs.match(/data-pos-y="([^"]+)"/) || [])[1] || '100';
                const ttdModes = (attrs.match(/data-ttd-modes="([^"]+)"/) || [])[1] || 'image';
                const qrDataRaw = (attrs.match(/data-ttd-qr-data="([^"]+)"/) || [])[1] || '';
                const defaultNamaRaw = (attrs.match(/data-default-nama="([^"]*)"/) || [])[1] || '';
                // RBAC per-TTD attributes (kosong = allow all, backward compat)
                const allowedRolesRaw = (attrs.match(/data-allowed-roles="([^"]*)"/) || [])[1] || '';
                const allowedUsersRaw = (attrs.match(/data-allowed-users="([^"]*)"/) || [])[1] || '';

                const isFloating = classes.includes('floating');
                const isBehind = classes.includes('behind');

                ttdData.push({
                    id: ttdId,
                    label,
                    nama_field: namaField,
                    mode: isFloating ? (isBehind ? 'behind' : 'front') : 'inline',
                    posX, posY,
                    ttdModes,
                    qrData: qrDataRaw.replace(/&quot;/g, '"'),
                    defaultNama: defaultNamaRaw.replace(/&quot;/g, '"'),
                    allowedRoles: allowedRolesRaw,
                    allowedUsers: allowedUsersRaw,
                });
            }

            // Sync with configTtd - update existing or add new
            ttdData.forEach(ttd => {
                const existing = configTtd.find(t => t.id === ttd.id);
                if (existing) {
                    existing.label = ttd.label;
                    existing.nama_field = ttd.nama_field;
                    existing.mode = ttd.mode;
                    existing.posX = ttd.posX;
                    existing.posY = ttd.posY;
                    existing.ttdModes = ttd.ttdModes;
                    existing.qrData = ttd.qrData;
                    existing.defaultNama = ttd.defaultNama;
                    // Sync RBAC per-TTD juga supaya sidebar tetap konsisten dengan editor
                    existing.allowedRoles = ttd.allowedRoles;
                    existing.allowedUsers = ttd.allowedUsers;
                } else {
                    configTtd.push(ttd);
                }
            });

            // Remove TTDs that are no longer in editor
            const ttdIds = ttdData.map(t => t.id);
            configTtd = configTtd.filter(t => ttdIds.includes(t.id));

            renderTtd();
        }

        // ===== Materai placeholders =====
        function scanMateraiPlaceholders() {
            const editor = tinymce.get('editor');
            if (!editor) return;
            const content = editor.getContent();
            const found = [];

            const tagRegex = /<div([^>]*class="[^"]*materai-placeholder[^"]*"[^>]*)>/g;
            let m;
            while ((m = tagRegex.exec(content)) !== null) {
                const attrs = m[1];
                const id = (attrs.match(/data-materai="([^"]+)"/) || [])[1];
                if (!id) continue;
                const classes = (attrs.match(/class="([^"]*)"/) || [])[1] || '';
                // Label can be empty (no data-label attribute or empty value)
                const labelMatch = attrs.match(/data-label="([^"]*)"/);
                const label = labelMatch ? labelMatch[1] : '';
                const matMode = (attrs.match(/data-mode="([^"]+)"/) || [])[1] || 'upload';
                const posX = (attrs.match(/data-pos-x="([^"]+)"/) || [])[1] || '350';
                const posY = (attrs.match(/data-pos-y="([^"]+)"/) || [])[1] || '500';
                const width = (attrs.match(/data-width="([^"]+)"/) || [])[1] || '100';
                const height = (attrs.match(/data-height="([^"]+)"/) || [])[1] || '140';
                const isFloating = classes.includes('floating');
                const isBehind = classes.includes('behind');

                found.push({
                    id,
                    label: label.replace(/&quot;/g, '"'),
                    mode: matMode,
                    posMode: isFloating ? (isBehind ? 'behind' : 'front') : 'inline',
                    posX, posY,
                    width, height
                });
            }

            // Sync with configMateraiList
            found.forEach(it => {
                const existing = configMateraiList.find(x => x.id === it.id);
                if (existing) {
                    Object.assign(existing, it);
                } else {
                    configMateraiList.push(it);
                }
            });
            const ids = found.map(x => x.id);
            configMateraiList = configMateraiList.filter(x => ids.includes(x.id));

            renderMaterai();
        }

        // ═══════════════════════════════════════════════════════════
        // KONFIGURASI VERIFIKASI PUBLIK
        // ═══════════════════════════════════════════════════════════

        // Cache field yang ter-detect dari template (invalidate saat editor content berubah)
        let _verifyFieldsCache = null;
        let _verifyFieldsCacheHash = null;

        // Label suggestion map — auto-guess label bagus dari key
        const VERIFY_LABEL_HINTS = {
            'nama_pasien':'Nama Pasien','nama':'Nama','norm':'No. Rekam Medis','no_rm':'No. Rekam Medis',
            'nopen':'No. Pendaftaran','no_surat':'Nomor Surat','nomor_surat':'Nomor Surat','no_dokumen':'Nomor Dokumen',
            'nomor':'Nomor','perihal':'Perihal','lampiran':'Lampiran',
            'tanggal':'Tanggal','tanggal_surat':'Tanggal Surat','tanggal_terbit':'Tanggal Terbit','tgl_surat':'Tanggal Surat',
            'tanggal_lahir':'Tanggal Lahir','tgl_lahir':'Tanggal Lahir','tempat_lahir':'Tempat Lahir',
            'tempat_terbit':'Tempat Terbit','tempat':'Tempat','kota':'Kota',
            'jenis_kelamin':'Jenis Kelamin','kelamin':'Jenis Kelamin','umur':'Umur','usia':'Usia',
            'alamat':'Alamat','pekerjaan':'Pekerjaan',
            'tanggal_periksa':'Tanggal Periksa','tgl_periksa':'Tanggal Periksa',
            'unit':'Unit/Poli','poli':'Poli','ruangan':'Ruangan',
            'nip_dokter':'NIP Dokter','sip_dokter':'SIP Dokter','spesialisasi':'Spesialisasi','nama_dokter':'Nama Dokter',
        };

        // Preset paket — quick-fill kombinasi field yang umum
        const VERIFY_PRESETS = {
            'pasien_dasar': {
                label: 'Data Pasien', icon: 'bi-person',
                fields: [
                    { key: 'tanggal_lahir', label: 'Tanggal Lahir' },
                    { key: 'jenis_kelamin', label: 'Jenis Kelamin' },
                    { key: 'alamat',        label: 'Alamat' },
                ]
            },
            'info_surat': {
                label: 'Info Surat', icon: 'bi-file-text',
                fields: [
                    { key: 'no_surat',       label: 'Nomor Surat' },
                    { key: 'tanggal_surat',  label: 'Tanggal Surat' },
                    { key: 'perihal',        label: 'Perihal' },
                ]
            },
            'kunjungan': {
                label: 'Detail Kunjungan', icon: 'bi-hospital',
                fields: [
                    { key: 'tanggal_periksa', label: 'Tanggal Periksa' },
                    { key: 'unit',            label: 'Unit/Poli' },
                ]
            },
        };

        // Convert key snake_case → label yang readable ("nama_pasien" → "Nama Pasien")
        function labelFromKey(key) {
            if (!key) return '';
            const k = key.toLowerCase();
            if (VERIFY_LABEL_HINTS[k]) return VERIFY_LABEL_HINTS[k];
            return k.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
        }

        // Detect semua field yang ada di template — grouped by source.
        // Cache hasil supaya tidak scan berulang. Auto-invalidate kalau content editor berubah.
        function detectTemplateFields(forceRefresh) {
            const editor = tinymce.get('editor');
            if (!editor) return [];
            const content = editor.getContent();
            // Simple hash via length + head + tail (cukup untuk invalidate)
            const hash = content.length + '|' + content.substring(0, 100) + '|' + content.substring(content.length - 100);
            if (!forceRefresh && _verifyFieldsCache && _verifyFieldsCacheHash === hash) {
                return _verifyFieldsCache;
            }

            const found = new Map();

            // 1. field-placeholder <span class="field-placeholder" data-type="...">{{name}}</span>
            const placeholderRe = /<span[^>]*class="[^"]*field-placeholder[^"]*"[^>]*data-type="([^"]*)"[^>]*>\{\{([a-zA-Z0-9_]+)\}\}<\/span>/g;
            let m;
            while ((m = placeholderRe.exec(content)) !== null) {
                const key = m[2], type = m[1] || 'text';
                if (key && !key.startsWith('_')) {
                    found.set(key, { key, label: labelFromKey(key), source: type, group: t('verify.group_field_input', {}, 'Field Input') });
                }
            }

            // 2. Plain {{field_name}} yang bukan tabledb & bukan internal
            const plainRe = /\{\{([a-zA-Z0-9_.]+)\}\}/g;
            while ((m = plainRe.exec(content)) !== null) {
                const key = m[1];
                if (!key || key.startsWith('_') || key.startsWith('tabledb.')) continue;
                if (found.has(key)) continue;
                found.set(key, { key, label: labelFromKey(key), source: 'variable', group: t('verify.group_field_variable', {}, 'Field Variable') });
            }

            // 3. data-nama-field dari TTD (nama penanda tangan)
            const ttdRe = /<div[^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*data-nama-field="([^"]+)"/g;
            while ((m = ttdRe.exec(content)) !== null) {
                const key = m[1];
                if (!key || key.startsWith('_')) continue;
                if (found.has(key)) continue;
                found.set(key, { key, label: labelFromKey(key), source: 'ttd', group: t('verify.group_signer_name', {}, 'Signer Name') });
            }

            const result = Array.from(found.values()).sort((a, b) => {
                if (a.group !== b.group) return a.group.localeCompare(b.group);
                return a.label.localeCompare(b.label);
            });

            _verifyFieldsCache = result;
            _verifyFieldsCacheHash = hash;
            return result;
        }

        function invalidateVerifyFieldsCache() {
            _verifyFieldsCache = null;
            _verifyFieldsCacheHash = null;
        }

        function renderVerifyConfig() {
            const listEl = document.getElementById('verifyFieldsList');
            const emptyEl = document.getElementById('verifyFieldsEmpty');
            const countEl = document.getElementById('verifyFieldCount');
            const toggleEl = document.getElementById('verifyShowPatient');
            if (!listEl) return;

            if (toggleEl) toggleEl.checked = !!verifyConfig.show_patient;

            const fields = verifyConfig.custom_fields || [];
            if (countEl) countEl.textContent = fields.length;

            const detected = detectTemplateFields();

            // Kalau kosong: tampilkan preset buttons + empty state
            if (fields.length === 0) {
                listEl.innerHTML = renderVerifyPresets(detected);
                if (emptyEl) emptyEl.style.display = 'block';
                return;
            }
            if (emptyEl) emptyEl.style.display = 'none';

            const usedKeys = new Set(fields.map(f => (f.key || '').toLowerCase()));

            listEl.innerHTML = fields.map((f, i) => {
                const isDetected = detected.some(d => d.key === f.key);
                const optionsHtml = renderFieldOptions(detected, f.key, usedKeys);

                return `
                <div class="mb-2 p-1 bg-gray-100 rounded border border-gray-300" style="font-size:11px;">
                    <div class="flex items-center gap-1 mb-1">
                        <span style="cursor:default;color:#9ca3af;flex-shrink:0;font-size:9px;" title="${t('verify.order_title', {order: i + 1}, 'Order: {order}')}">
                            <i class="bi bi-grip-vertical"></i>${i + 1}
                        </span>
                        <select class="flex-1 rounded border-gray-300 shadow-sm" onchange="onVerifyFieldKeyChange(${i}, this.value)" style="font-size:11px; padding:2px 20px 2px 4px; height:auto;">
                            <option value="">${t('verify.choose_field_option', {}, '- Choose a field from the template -')}</option>
                            ${optionsHtml}
                            <option value="__custom__" ${!isDetected && f.key ? 'selected' : ''}>${f.key ? t('verify.custom_entry_with_key', {key: escapeHtml(f.key)}, 'Type manually: {key}') : t('verify.custom_entry_option', {}, 'Type manually')}</option>
                        </select>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50 flex-shrink-0" onclick="removeVerifyField(${i})" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="flex items-center gap-1">
                        <span style="width:16px;"></span>
                        <input type="text" class="flex-1 rounded border-gray-300 shadow-sm" value="${escapeHtml(f.label || '')}" oninput="updateVerifyField(${i}, 'label', this.value)" placeholder="${t('verify.label_placeholder', {}, 'Label shown in verification')}" style="font-size:11px; padding:2px 4px;">
                        ${i > 0 ? `<button type="button" class="inline-flex items-center py-0 px-1 rounded border border-gray-500 text-gray-700 hover:bg-gray-50 flex-shrink-0" onclick="moveVerifyField(${i}, -1)" title="${t('verify.move_up_title', {}, 'Move up')}"><i class="bi bi-arrow-up"></i></button>` : '<span style="width:22px;"></span>'}
                        ${i < fields.length - 1 ? `<button type="button" class="inline-flex items-center py-0 px-1 rounded border border-gray-500 text-gray-700 hover:bg-gray-50 flex-shrink-0" onclick="moveVerifyField(${i}, 1)" title="${t('verify.move_down_title', {}, 'Move down')}"><i class="bi bi-arrow-down"></i></button>` : '<span style="width:22px;"></span>'}
                    </div>
                    ${!isDetected && f.key ? `<div class="mt-1" style="font-size:10px;color:#f59e0b;"><i class="bi bi-exclamation-triangle"></i> ${t('verify.not_detected_hint', {key: escapeHtml(f.key)}, 'Key "{key}" not detected in the template. Make sure this field exists in the document\'s field_values.')}</div>` : ''}
                </div>
            `;
            }).join('');
        }

        // Build <optgroup> options dari detected fields
        function renderFieldOptions(detected, currentKey, usedKeys) {
            if (detected.length === 0) {
                return `<option disabled>${t('verify.no_field_in_template', {}, '-- No field in template --')}</option>`;
            }
            const groups = {};
            detected.forEach(d => {
                if (!groups[d.group]) groups[d.group] = [];
                groups[d.group].push(d);
            });
            let html = '';
            for (const g in groups) {
                html += `<optgroup label="${escapeHtml(g)}">`;
                groups[g].forEach(d => {
                    const isUsed = usedKeys.has(d.key.toLowerCase()) && d.key !== currentKey;
                    const isSelected = d.key === currentKey;
                    html += `<option value="${escapeHtml(d.key)}" ${isSelected ? 'selected' : ''} ${isUsed ? 'disabled' : ''}>${escapeHtml(d.label)}${isUsed ? ' ' + t('verify.already_used_suffix', {}, '(already used)') : ''}</option>`;
                });
                html += `</optgroup>`;
            }
            return html;
        }

        // Preset quick-fill buttons — muncul saat custom_fields masih kosong
        function renderVerifyPresets(detected) {
            if (detected.length === 0) {
                return `<div class="text-center py-2" style="font-size:11px;color:#9ca3af;"><em>${t('verify.no_field_yet_hint', {}, 'Add a field to the template first (e.g. using the + Field button in the editor) before configuring this here.')}</em></div>`;
            }
            let html = `<div class="mb-2 mt-1"><small class="text-gray-500 block mb-1 text-xs"><i class="bi bi-lightning-charge"></i> ${t('verify.quick_preset_label', {}, 'Quick presets (click to auto-add):')}</small>`;
            let anyPreset = false;
            for (const key in VERIFY_PRESETS) {
                const p = VERIFY_PRESETS[key];
                const matched = p.fields.filter(f => detected.some(d => d.key === f.key));
                if (matched.length === 0) continue;
                anyPreset = true;
                html += `<button type="button" class="inline-flex items-center mr-1 mb-1 rounded border border-blue-600 text-blue-600 hover:bg-blue-50" style="font-size:10.5px;padding:2px 8px;" onclick="applyVerifyPreset('${key}')" title="${t('verify.preset_add_title', {count: matched.length, fields: matched.map(f => f.key).join(', ')}, 'Add {count} field(s): {fields}')}">
                    <i class="bi ${p.icon}"></i> ${escapeHtml(p.label)} <span class="ml-1 inline-flex items-center px-1 rounded-full bg-blue-100 text-blue-800" style="font-size:9px;">${matched.length}</span>
                </button>`;
            }
            if (!anyPreset) {
                html += `<em class="text-gray-500" style="font-size:11px;">${t('verify.no_preset_match', {}, 'No preset matches the fields in the template yet.')}</em>`;
            }
            html += '</div>';
            return html;
        }

        function applyVerifyPreset(presetKey) {
            const preset = VERIFY_PRESETS[presetKey];
            if (!preset) return;
            const detected = detectTemplateFields();
            const detectedKeys = new Set(detected.map(d => d.key));
            const usedKeys = new Set((verifyConfig.custom_fields || []).map(f => f.key));
            preset.fields.forEach(f => {
                if (detectedKeys.has(f.key) && !usedKeys.has(f.key)) {
                    verifyConfig.custom_fields.push({ key: f.key, label: f.label });
                }
            });
            renderVerifyConfig();
        }

        function onVerifyConfigChange() {
            const toggleEl = document.getElementById('verifyShowPatient');
            if (toggleEl) verifyConfig.show_patient = toggleEl.checked;
        }

        // ═════════ Access Config (RBAC per-template) ═════════
        // Parse comma-separated string ke array, strip whitespace, filter empty.
        function _parseCSV(str) {
            return String(str || '').split(',').map(s => s.trim()).filter(s => s !== '');
        }
        function _parseCSVInt(str) {
            return _parseCSV(str).map(s => parseInt(s, 10)).filter(n => !isNaN(n) && n > 0);
        }
        function _joinCSV(arr) {
            return Array.isArray(arr) ? arr.join(',') : '';
        }

        // Called saat user edit input di panel Konfig Akses
        function onAccessConfigChange() {
            const modeEl = document.getElementById('accessMode');
            if (modeEl) accessConfig.mode = modeEl.value === 'permissive' ? 'permissive' : 'strict';
            for (const action of ['create', 'edit', 'lock', 'delete']) {
                const rolesEl = document.getElementById('access' + action.charAt(0).toUpperCase() + action.slice(1) + 'Roles');
                const usersEl = document.getElementById('access' + action.charAt(0).toUpperCase() + action.slice(1) + 'Users');
                if (rolesEl) accessConfig[action].roles = _parseCSV(rolesEl.value);
                if (usersEl) accessConfig[action].users = _parseCSVInt(usersEl.value);
            }
            updateAccessConfigCount();
            // Auto-refresh preview kalau function-nya sudah ke-load
            if (typeof _refreshAccessPreviewDebounced === 'function') _refreshAccessPreviewDebounced();
        }

        // Debounce preview refresh — supaya tidak lag saat typing cepat
        let _accessPreviewTimer = null;
        function _refreshAccessPreviewDebounced() {
            clearTimeout(_accessPreviewTimer);
            _accessPreviewTimer = setTimeout(() => {
                if (typeof refreshAccessPreview === 'function') refreshAccessPreview();
            }, 200);
        }

        // Update badge counter — total roles + users di config
        function updateAccessConfigCount() {
            const badge = document.getElementById('accessConfigCount');
            if (!badge) return;
            let total = 0;
            for (const action of ['create', 'edit', 'lock', 'delete']) {
                total += (accessConfig[action].roles?.length || 0) + (accessConfig[action].users?.length || 0);
            }
            badge.textContent = total;
            badge.className = total > 0
                ? 'ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800'
                : 'ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-800';
        }

        // Populate UI dari accessConfig state (dipanggil saat page load)
        function populateAccessConfigUI() {
            const modeEl = document.getElementById('accessMode');
            if (modeEl) modeEl.value = accessConfig.mode || 'strict';
            for (const action of ['create', 'edit', 'lock', 'delete']) {
                const rolesEl = document.getElementById('access' + action.charAt(0).toUpperCase() + action.slice(1) + 'Roles');
                const usersEl = document.getElementById('access' + action.charAt(0).toUpperCase() + action.slice(1) + 'Users');
                if (rolesEl) rolesEl.value = _joinCSV(accessConfig[action].roles);
                if (usersEl) usersEl.value = _joinCSV(accessConfig[action].users);
            }
            updateAccessConfigCount();
        }
        // Auto-populate saat DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function() {
            populateAccessConfigUI();
            refreshAccessPreview();
        });

        // ─── Quick actions untuk panel Akses ───

        // Copy CREATE (roles+users) ke EDIT, LOCK & DELETE — supaya semua action punya restriction yang sama
        function copyAccessCreateToAll() {
            const createRoles = document.getElementById('accessCreateRoles')?.value || '';
            const createUsers = document.getElementById('accessCreateUsers')?.value || '';
            for (const target of ['Edit', 'Lock', 'Delete']) {
                const rEl = document.getElementById('access' + target + 'Roles');
                const uEl = document.getElementById('access' + target + 'Users');
                if (rEl) rEl.value = createRoles;
                if (uEl) uEl.value = createUsers;
            }
            onAccessConfigChange();
            refreshAccessPreview();
            showToast(t('access.copied_to_all_toast', {}, 'CREATE access copied to EDIT, LOCK & DELETE'));
        }

        // Clear semua field → allow all untuk create/edit/lock, superadmin-only untuk delete
        function clearAccessConfig() {
            for (const action of ['Create', 'Edit', 'Lock', 'Delete']) {
                const rEl = document.getElementById('access' + action + 'Roles');
                const uEl = document.getElementById('access' + action + 'Users');
                if (rEl) rEl.value = '';
                if (uEl) uEl.value = '';
            }
            onAccessConfigChange();
            refreshAccessPreview();
            showToast(t('access.reset_toast', {}, 'Access config reset'));
        }

        // Preset: isi keempat action dengan 'superadmin' saja
        function fillAccessSuperadminOnly() {
            for (const action of ['Create', 'Edit', 'Lock', 'Delete']) {
                const rEl = document.getElementById('access' + action + 'Roles');
                const uEl = document.getElementById('access' + action + 'Users');
                if (rEl) rEl.value = 'superadmin';
                if (uEl) uEl.value = '';
            }
            onAccessConfigChange();
            refreshAccessPreview();
            showToast(t('access.superadmin_only_toast', {}, 'All actions locked to superadmin only'));
        }

        // ─── Real-time preview: cek permission user login sekarang ───
        // Simulasi ezdoc_can_on_template() di JS supaya user langsung tahu implikasinya.
        // Logic MIRRORS PHP version — kalau kedua-duanya (roles + users) empty → allow.

        // Current user data (dari koneksi.php PHP globals)
        const CURRENT_USER_ID = <?= (int)($author_id ?? 0) ?>;
        const CURRENT_USER_ROLES = <?= json_encode(is_array($author_role_array ?? null) ? $author_role_array : []) ?>;
        const CURRENT_IS_SUPERADMIN = CURRENT_USER_ROLES.includes('superadmin');

        function _checkAllowed(action) {
            // Mirror ezdoc_can_on_template() logic
            if (CURRENT_IS_SUPERADMIN) return { allowed: true, reason: 'superadmin bypass' };
            const cfg = accessConfig[action] || { roles: [], users: [] };
            const roles = cfg.roles || [];
            const users = cfg.users || [];
            if (roles.length === 0 && users.length === 0) {
                // Delete = safe-by-default: kosong = superadmin only (bukan allow all)
                if (action === 'delete') return { allowed: false, reason: 'kosong = superadmin only' };
                return { allowed: true, reason: 'kosong (allow all)' };
            }
            for (const r of roles) if (CURRENT_USER_ROLES.includes(r)) return { allowed: true, reason: `role "${r}" match` };
            if (users.includes(CURRENT_USER_ID)) return { allowed: true, reason: `user_id ${CURRENT_USER_ID} match` };
            return { allowed: false, reason: `role/user tidak match` };
        }

        function refreshAccessPreview() {
            onAccessConfigChange(); // sync state dari UI dulu
            const el = document.getElementById('accessPreviewResult');
            if (!el) return;
            const actions = [
                { key: 'create', label: 'CREATE', icon: 'plus-circle' },
                { key: 'edit', label: 'EDIT', icon: 'pencil' },
                { key: 'lock', label: 'LOCK', icon: 'lock' },
                { key: 'delete', label: 'DELETE', icon: 'trash' },
            ];
            const rows = actions.map(a => {
                const chk = _checkAllowed(a.key);
                const badge = chk.allowed
                    ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-green-100 text-green-800" style="font-size:9px;">BOLEH</span>'
                    : '<span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-red-100 text-red-800" style="font-size:9px;">TOLAK</span>';
                return `<div class="flex justify-between items-center py-0" style="font-size:10px;">
                    <span><i class="bi bi-${a.icon}"></i> ${a.label}</span>
                    <span>${badge} <span style="color:#666;font-size:9px;">${chk.reason}</span></span>
                </div>`;
            }).join('');
            el.innerHTML = rows;
        }

        // Handle dropdown change — kalau __custom__ → prompt manual entry
        function onVerifyFieldKeyChange(idx, value) {
            if (!verifyConfig.custom_fields[idx]) return;
            if (value === '__custom__') {
                const customKey = prompt(t('verify.custom_key_prompt', {}, 'Type the field_values key name (e.g. hasil_pemeriksaan):'), verifyConfig.custom_fields[idx].key || '');
                if (customKey === null) { renderVerifyConfig(); return; }
                const cleanKey = customKey.trim();
                if (cleanKey === '') { renderVerifyConfig(); return; }
                verifyConfig.custom_fields[idx].key = cleanKey;
                if (!verifyConfig.custom_fields[idx].label) {
                    verifyConfig.custom_fields[idx].label = labelFromKey(cleanKey);
                }
            } else {
                verifyConfig.custom_fields[idx].key = value;
                // Auto-fill label kalau kosong (pakai label suggestion)
                if (!verifyConfig.custom_fields[idx].label && value) {
                    verifyConfig.custom_fields[idx].label = labelFromKey(value);
                }
            }
            renderVerifyConfig();
        }

        function addVerifyField() {
            verifyConfig.custom_fields.push({ key: '', label: '' });
            renderVerifyConfig();
            setTimeout(() => {
                const selects = document.querySelectorAll('#verifyFieldsList select');
                if (selects.length) selects[selects.length - 1].focus();
            }, 50);
        }

        function updateVerifyField(idx, prop, value) {
            if (verifyConfig.custom_fields[idx]) {
                verifyConfig.custom_fields[idx][prop] = (value || '').trim();
            }
        }

        function removeVerifyField(idx) {
            if (verifyConfig.custom_fields[idx]) {
                verifyConfig.custom_fields.splice(idx, 1);
                renderVerifyConfig();
            }
        }

        function moveVerifyField(idx, delta) {
            const arr = verifyConfig.custom_fields;
            const newIdx = idx + delta;
            if (newIdx < 0 || newIdx >= arr.length) return;
            [arr[idx], arr[newIdx]] = [arr[newIdx], arr[idx]];
            renderVerifyConfig();
        }

        // Auto-render saat halaman load + refresh saat panel dibuka (Alpine dispatches 'verify-panel-shown' via x-init watcher)
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(renderVerifyConfig, 500);
        });
        window.addEventListener('verify-panel-shown', function() {
            renderVerifyConfig();
        });

        // Preview halaman verifikasi: kirim config current + template ke endpoint,
        // load response HTML ke iframe di modal
        async function previewVerifikasi() {
            const iframe = document.getElementById('verifyPreviewIframe');
            const loadingEl = document.getElementById('verifyPreviewLoading');
            if (!iframe) return;

            // Sync verifyConfig terakhir dari UI
            onVerifyConfigChange();
            const cleanVerify = {
                show_patient: !!verifyConfig.show_patient,
                custom_fields: (verifyConfig.custom_fields || []).filter(f => (f.key || '').trim() !== '')
            };

            const editor = tinymce.get('editor');
            const templateHtml = editor ? editor.getContent() : '';

            // Reset loading state — tampilkan spinner di atas iframe
            if (loadingEl) {
                loadingEl.style.display = 'flex';
                loadingEl.innerHTML = '<div class="text-center"><div class="inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" role="status"></div><div class="mt-2 text-xs">' + t('verify.rendering_preview', {}, 'Rendering preview...') + '</div></div>';
            }
            iframe.srcdoc = ''; // Clear previous content

            // Show modal via Alpine store
            openAppModal('verifyPreview');

            try {
                const formData = new FormData();
                formData.append('verify_config', JSON.stringify(cleanVerify));
                formData.append('doc_scope', document.getElementById('templateDocScope')?.value || 'patient');
                formData.append('nama_template', document.getElementById('namaTemplate')?.value || 'Contoh Template');
                formData.append('category', (document.getElementById('templateCategory')?.value || '').trim());
                formData.append('template_html', templateHtml);

                // spec: ezdoc-spec/openapi.yaml#/paths/~1verify~1preview
                const resp = await fetch(EZDOC_URLS.verifyPreview || 'verifikasi_preview.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const html = await resp.text();

                // Set srcdoc, hide loading saat iframe fully rendered
                iframe.onload = function() {
                    if (loadingEl) loadingEl.style.display = 'none';
                };
                iframe.srcdoc = html;
                // Fallback timeout: hide loading setelah 3 detik walaupun onload belum fire
                setTimeout(() => {
                    if (loadingEl && loadingEl.style.display !== 'none') loadingEl.style.display = 'none';
                }, 3000);
            } catch (err) {
                if (loadingEl) {
                    loadingEl.innerHTML = '<div class="text-center text-red-600 p-3"><i class="bi bi-exclamation-triangle" style="font-size:24px;"></i><div class="mt-2">' + t('verify.preview_failed', {}, 'Failed to load preview:') + '<br><small>' + escapeHtml(err.message || String(err)) + '</small></div></div>';
                }
            }
        }

        function renderMaterai() {
            const list = document.getElementById('materaiList');
            const empty = document.getElementById('materaiEmpty');
            const countBadge = document.getElementById('materaiCount');
            if (!list) return;

            if (countBadge) countBadge.textContent = configMateraiList.length;
            if (configMateraiList.length === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';

            // Materai posMode accent — inline (rose), floating (amber/slate)
            const mateModeMap = {
                'inline':  { accent: 'before:bg-rose-400',  headerBg: 'from-rose-50/70 to-white',   badgeCls: 'bg-rose-50 text-rose-700 ring-rose-200',   label: t('mode.inline', {}, 'Inline') },
                'front':   { accent: 'before:bg-amber-400', headerBg: 'from-amber-50/70 to-white',  badgeCls: 'bg-amber-50 text-amber-700 ring-amber-200', label: t('mode.front', {}, 'Front') },
                'behind':  { accent: 'before:bg-slate-400', headerBg: 'from-slate-50/70 to-white',  badgeCls: 'bg-slate-50 text-slate-700 ring-slate-200', label: t('mode.behind', {}, 'Behind') }
            };

            list.innerHTML = configMateraiList.map((m, i) => {
                const eId = escapeHtml(m.id);
                const labelDisp = (m.label && m.label.trim()) ? escapeHtml(m.label) : `<span class="italic text-gray-400">${t('field.no_label_placeholder', {}, '(no label)')}</span>`;
                const posMode = m.posMode || 'inline';
                const isFloating = posMode !== 'inline';
                const w = parseInt(m.width) || 100;
                const h = parseInt(m.height) || 140;
                const mm = mateModeMap[posMode] || mateModeMap['inline'];
                const searchText = ((m.label || '') + ' ' + (m.mode || '') + ' ' + posMode + ' ' + m.id).toLowerCase();
                return `
                <div class="mb-2 bg-white border border-gray-200 rounded-md overflow-hidden shadow-sm hover:border-gray-300 hover:shadow-md transition-all panel-list-item relative before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 ${mm.accent} before:content-['']" data-search-text="${escapeHtml(searchText)}" data-focus-target="materai:${eId}">
                    <!-- Card Header -->
                    <div class="flex items-center gap-1.5 pl-3 pr-2 py-1.5 bg-gradient-to-b ${mm.headerBg} border-b border-gray-200">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded ring-1 ring-inset ${mm.badgeCls} shrink-0"><i class="bi bi-stamp text-[10px]"></i></span>
                        <span class="text-xs font-medium text-gray-900 truncate flex-1 min-w-0">${labelDisp}</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-gray-600 bg-white/80 ring-1 ring-inset ring-gray-200 shrink-0">${mm.label}</span>
                        <button type="button" class="inline-flex items-center p-0.5 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 shrink-0" onclick="deleteMaterai(${i})" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-trash text-xs"></i></button>
                    </div>
                    <!-- Card Body -->
                    <div class="pl-3 pr-2 py-2 space-y-1.5 bg-gradient-to-b from-white to-gray-50/30">
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.label_label', {}, 'Label')}</label>
                            <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${escapeHtml(m.label || '')}" oninput="updateMateraiAttr('${eId}', 'label', this.value, ${i})" placeholder="${t('field.label_placeholder', {}, '(leave empty if no label)')}">
                        </div>
                        <div class="grid grid-cols-2 gap-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.type_label', {}, 'Type')}</label>
                                <select class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-1.5 py-1 bg-white" onchange="updateMateraiAttr('${eId}', 'mode', this.value, ${i})">
                                    <option value="upload" ${m.mode === 'upload' ? 'selected' : ''}>${t('materai.mode_upload', {}, 'Upload')}</option>
                                    <option value="kosong" ${m.mode === 'kosong' ? 'selected' : ''}>${t('materai.mode_empty', {}, 'Empty')}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('materai.position_label', {}, 'Position')}</label>
                                <select class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-1.5 py-1 bg-white" onchange="updateMateraiPosMode('${eId}', this.value, ${i})">
                                    <option value="inline" ${posMode === 'inline' ? 'selected' : ''}>${t('mode.inline', {}, 'Inline')}</option>
                                    <option value="front" ${posMode === 'front' ? 'selected' : ''}>${t('mode.front', {}, 'Front')}</option>
                                    <option value="behind" ${posMode === 'behind' ? 'selected' : ''}>${t('mode.behind', {}, 'Behind')}</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Width</label>
                                <input type="number" min="20" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${w}" onchange="updateMateraiSize('${eId}', 'width', this.value, ${i})">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Height</label>
                                <input type="number" min="20" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${h}" onchange="updateMateraiSize('${eId}', 'height', this.value, ${i})">
                            </div>
                        </div>
                        ${isFloating ? `
                        <div class="grid grid-cols-2 gap-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Pos X</label>
                                <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${m.posX || 350}" onchange="updateMateraiPos('${eId}', 'x', this.value)">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Pos Y</label>
                                <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${m.posY || 500}" onchange="updateMateraiPos('${eId}', 'y', this.value)">
                            </div>
                        </div>
                        <p class="text-[10px] text-gray-500 italic">${t('panel.drag_hint', {item: 'materai'}, 'Drag {item} in the editor to change its position')}</p>
                        ` : ''}
                    </div>
                </div>`;
            }).join('');

            // Re-apply filter if search has value
            const ms = document.getElementById('materaiSearch');
            if (ms && ms.value) filterPanel('materaiList', ms.value);
        }

        // Resize materai placeholder (width/height) — update both data-attr and inner div CSS
        function updateMateraiSize(materaiId, dim, value, idx) {
            const v = Math.max(20, parseInt(value) || 0);
            if (idx !== undefined && configMateraiList[idx]) configMateraiList[idx][dim] = String(v);
            const editor = tinymce.get('editor');
            if (!editor) return;
            let content = editor.getContent();

            const dataAttr = (dim === 'width') ? 'data-width' : 'data-height';
            const cssProp  = (dim === 'width') ? 'width' : 'height';

            // Update outer tag attribute
            const tagRegex = new RegExp(`<div([^>]*data-materai="${materaiId}"[^>]*)>`, 'g');
            content = content.replace(tagRegex, function(match, attrs) {
                let newAttrs = attrs.replace(new RegExp(`\\s*${dataAttr}="[^"]*"`, 'g'), '');
                newAttrs += ` ${dataAttr}="${v}"`;
                return `<div${newAttrs}>`;
            });

            // Update inner div style (width/height in px)
            const innerRegex = new RegExp(`(<div[^>]*data-materai="${materaiId}"[^>]*>\\s*<div[^>]*style=")([^"]*)(")`, 'g');
            content = content.replace(innerRegex, (mm, before, css, after) => {
                let newCss = css.replace(new RegExp(`${cssProp}\\s*:\\s*[^;]*;?`, 'gi'), '');
                if (!newCss.endsWith(';') && newCss.trim() !== '') newCss += ';';
                newCss += `${cssProp}:${v}px;`;
                return before + newCss + after;
            });

            editor.setContent(content);
            renderMaterai();
        }

        // Change inline / floating mode + class on placeholder div
        function updateMateraiPosMode(materaiId, newPosMode, idx) {
            if (idx !== undefined && configMateraiList[idx]) configMateraiList[idx].posMode = newPosMode;
            const editor = tinymce.get('editor');
            if (!editor) return;
            let content = editor.getContent();

            const tagRegex = new RegExp(`<div([^>]*data-materai="${materaiId}"[^>]*)>`, 'g');
            content = content.replace(tagRegex, function(match, attrs) {
                // Strip floating-related classes
                let newAttrs = attrs.replace(/class="([^"]*)"/, function(m, cls) {
                    let classes = cls.split(/\s+/).filter(c =>
                        c && c !== 'floating' && c !== 'front' && c !== 'behind'
                    );
                    if (newPosMode === 'front' || newPosMode === 'behind') {
                        classes.push('floating', newPosMode);
                    }
                    return `class="${classes.join(' ')}"`;
                });

                // Remove old data-pos-mode/x/y and inline style positioning
                newAttrs = newAttrs.replace(/\s*data-pos-mode="[^"]*"/g, '');
                newAttrs = newAttrs.replace(/\s*style="[^"]*"/g, '');

                if (newPosMode !== 'inline') {
                    const item = configMateraiList.find(x => x.id === materaiId) || {};
                    const px = parseInt(item.posX) || 350;
                    const py = parseInt(item.posY) || 500;
                    // Ensure data-pos-x/y exist
                    if (!/data-pos-x=/.test(newAttrs)) newAttrs += ` data-pos-x="${px}"`;
                    if (!/data-pos-y=/.test(newAttrs)) newAttrs += ` data-pos-y="${py}"`;
                    newAttrs += ` data-pos-mode="${newPosMode}" style="position:absolute; left:${px}px; top:${py}px;"`;
                }
                return `<div${newAttrs}>`;
            });

            editor.setContent(content);
            // Re-init drag for newly floating items
            if (typeof initTtdDrag === 'function') initTtdDrag();
            renderMaterai();
        }

        function updateMateraiAttr(materaiId, attrName, value, idx) {
            if (idx !== undefined && configMateraiList[idx]) configMateraiList[idx][attrName] = value;
            const dataAttrMap = { label: 'data-label', mode: 'data-mode' };
            const dataAttr = dataAttrMap[attrName];
            if (!dataAttr) return;

            const editor = tinymce.get('editor');
            if (!editor) return;
            let content = editor.getContent();
            const escapedVal = String(value).replace(/"/g, '&quot;');
            const tagRegex = new RegExp(`<div([^>]*data-materai="${materaiId}"[^>]*)>`, 'g');
            content = content.replace(tagRegex, function(match, attrs) {
                let newAttrs = attrs.replace(new RegExp(`\\s*${dataAttr}="[^"]*"`, 'g'), '');
                if (value !== '' && value !== null) newAttrs += ` ${dataAttr}="${escapedVal}"`;
                // Update visual label content as well
                let result = `<div${newAttrs}>`;
                return result;
            });

            // Also update the visual content (mode label inside)
            if (attrName === 'mode') {
                const visual = (value === 'kosong') ? t('materai.mode_empty_paren', {}, '(empty)') : t('materai.mode_upload_paren', {}, '(upload)');
                const innerRegex = new RegExp(`(<div[^>]*data-materai="${materaiId}"[^>]*>\\s*<div[^>]*>)([\\s\\S]*?)(</div>\\s*</div>)`, 'g');
                content = content.replace(innerRegex, (mm, open, _inner, close) =>
                    `${open}<strong>MATERAI</strong><br>10000<br>${visual}${close}`
                );
            }

            editor.setContent(content);
            renderMaterai();
        }

        function updateMateraiPos(materaiId, axis, value) {
            const editor = tinymce.get('editor');
            if (!editor) return;
            let content = editor.getContent();
            const dataAttr = axis === 'x' ? 'data-pos-x' : 'data-pos-y';
            const styleProp = axis === 'x' ? 'left' : 'top';
            const tagRegex = new RegExp(`<div([^>]*data-materai="${materaiId}"[^>]*)>`, 'g');
            content = content.replace(tagRegex, function(match, attrs) {
                let newAttrs = attrs.replace(new RegExp(`\\s*${dataAttr}="[^"]*"`, 'g'), '');
                newAttrs += ` ${dataAttr}="${parseInt(value) || 0}"`;
                // Update inline style position
                newAttrs = newAttrs.replace(new RegExp(`${styleProp}\\s*:\\s*[^;\"]*;?`, 'g'), '');
                if (newAttrs.includes('style="')) {
                    newAttrs = newAttrs.replace(/style="([^"]*)"/, (s, css) => `style="${css}; ${styleProp}: ${parseInt(value) || 0}px;"`);
                } else {
                    newAttrs += ` style="${styleProp}: ${parseInt(value) || 0}px;"`;
                }
                return `<div${newAttrs}>`;
            });
            editor.setContent(content);
            // update internal config
            const it = configMateraiList.find(x => x.id === materaiId);
            if (it) {
                if (axis === 'x') it.posX = value;
                else it.posY = value;
            }
        }

        async function deleteMaterai(idx) {
            if (!configMateraiList[idx]) return;
            if (!(await ezdocConfirm(t('confirm.delete_materai', {}, 'Delete this stamp duty placeholder?'), { title: 'Delete Materai', variant: 'danger', confirmText: 'Delete' }))) return;
            const id = configMateraiList[idx].id;
            const editor = tinymce.get('editor');
            if (editor) {
                let content = editor.getContent();
                content = content.replace(new RegExp(`<div[^>]*data-materai="${id}"[^>]*>[\\s\\S]*?</div>\\s*`, 'g'), '');
                editor.setContent(content);
            }
            configMateraiList.splice(idx, 1);
            renderMaterai();
        }

        function renderLogoPanel(logos) {
            const list = document.getElementById('logoList');
            const empty = document.getElementById('logoEmpty');
            const countBadge = document.getElementById('logoCount');
            if (!list) return;

            // Update count badge
            if (countBadge) countBadge.textContent = logos.length;

            if (logos.length === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';

            // Mode accent — inline (green — anchored) vs floating (amber — draggable)
            const modeMap = {
                'inline':  { accent: 'before:bg-emerald-400', headerBg: 'from-emerald-50/70 to-white', badgeCls: 'bg-emerald-50 text-emerald-700 ring-emerald-200', label: t('mode.inline', {}, 'Inline') },
                'front':   { accent: 'before:bg-amber-400',   headerBg: 'from-amber-50/70 to-white',   badgeCls: 'bg-amber-50 text-amber-700 ring-amber-200',       label: t('mode.front', {}, 'Front') },
                'behind':  { accent: 'before:bg-slate-400',   headerBg: 'from-slate-50/70 to-white',   badgeCls: 'bg-slate-50 text-slate-700 ring-slate-200',       label: t('mode.behind', {}, 'Behind') }
            };

            list.innerHTML = logos.map(logo => {
                const src = configHeader.logos[logo.name] || '';
                const width = configHeader.logoSizes[logo.name] || logo.width || '80px';
                const mode = logo.mode || 'inline';
                const isFloating = mode !== 'inline';
                const eName = escapeHtml(logo.name);
                const eWidth = escapeHtml(width);
                const m = modeMap[mode] || modeMap['inline'];

                return `
                <div class="mb-2 bg-white border border-gray-200 rounded-md overflow-hidden shadow-sm hover:border-gray-300 hover:shadow-md transition-all panel-list-item relative before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 ${m.accent} before:content-['']" data-focus-target="logo:${eName}">
                    <!-- Card Header -->
                    <div class="flex items-center gap-1.5 pl-3 pr-2 py-1.5 bg-gradient-to-b ${m.headerBg} border-b border-gray-200">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded ring-1 ring-inset ${m.badgeCls} shrink-0"><i class="bi bi-image text-[10px]"></i></span>
                        <code class="text-xs font-mono text-gray-900 truncate flex-1 min-w-0">${eName}</code>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-gray-600 bg-white/80 ring-1 ring-inset ring-gray-200 shrink-0">${m.label}</span>
                        ${src ? `<button type="button" class="inline-flex items-center p-0.5 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 shrink-0" onclick="removeLogo('${eName}')" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-trash text-xs"></i></button>` : ''}
                    </div>
                    <!-- Card Body -->
                    <div class="pl-3 pr-2 py-2 space-y-1.5 bg-gradient-to-b from-white to-gray-50/30">
                        ${src ? `<img src="${src}" style="max-width:${eWidth};max-height:60px;display:block;" class="rounded border border-gray-200 mx-auto">` : ''}
                        <div class="grid grid-cols-3 gap-1.5">
                            <div class="col-span-2">
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('logo.mode_label', {}, 'Mode')}</label>
                                <select class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-1.5 py-1 bg-white" onchange="updateLogoMode('${eName}', this.value)">
                                    <option value="inline" ${mode === 'inline' ? 'selected' : ''}>${t('mode.inline', {}, 'Inline')}</option>
                                    <option value="front" ${mode === 'front' ? 'selected' : ''}>${t('mode.floating_front', {}, 'Floating Front')}</option>
                                    <option value="behind" ${mode === 'behind' ? 'selected' : ''}>${t('mode.floating_behind', {}, 'Floating Behind')}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Width</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${eWidth}" onchange="updateLogoSize('${eName}', this.value)">
                            </div>
                        </div>
                        ${isFloating ? `
                        <div class="grid grid-cols-2 gap-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Pos X</label>
                                <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${logo.posX}" onchange="updateLogoPos('${eName}', 'x', this.value)">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Pos Y</label>
                                <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${logo.posY}" onchange="updateLogoPos('${eName}', 'y', this.value)">
                            </div>
                        </div>
                        <p class="text-[10px] text-gray-500 italic">${t('panel.drag_hint', {item: 'logo'}, 'Drag {item} in the editor to change its position')}</p>
                        ` : ''}
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('logo.upload_image_label', {}, 'Upload image')}</label>
                            <input type="file" class="w-full text-[11px] file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-gray-100 file:text-gray-700 file:cursor-pointer hover:file:bg-gray-200" accept="image/*" onchange="uploadLogo('${eName}', this)">
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        function uploadLogo(name, input) {
            const file = input.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) { alert(t('alert.file_must_be_image', {}, 'File must be an image!')); return; }
            if (file.size > 500 * 1024) { alert(t('alert.file_max_size', {}, 'Max 500KB!')); return; }

            const reader = new FileReader();
            reader.onload = function(e) {
                configHeader.logos[name] = e.target.result;
                updateLogoInEditor(name); // Update logo in editor
                scanLogos();
            };
            reader.readAsDataURL(file);
        }

        function removeLogo(name) {
            delete configHeader.logos[name];
            updateLogoInEditor(name); // Update logo in editor (show placeholder)
            scanLogos();
        }

        // Update logo display in editor (show image or placeholder text)
        function updateLogoInEditor(name) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const iframeDoc = editor.getDoc();
            if (!iframeDoc) return;

            const logoEl = iframeDoc.querySelector(`[data-logo="${name}"]`);
            if (!logoEl) return;

            const src = configHeader.logos[name] || '';
            const width = configHeader.logoSizes[name] || logoEl.getAttribute('data-width') || '80px';

            if (src) {
                logoEl.innerHTML = `<img src="${src}" style="width:${width};height:auto;display:block;">`;
            } else {
                logoEl.innerHTML = `[Logo: ${name}]`;
            }
        }

        // Update all logos in editor
        function updateAllLogosInEditor() {
            const editor = tinymce.get('editor');
            if (!editor) return;

            const iframeDoc = editor.getDoc();
            if (!iframeDoc) return;

            iframeDoc.querySelectorAll('[data-logo]').forEach(el => {
                const name = el.getAttribute('data-logo');
                const src = configHeader.logos[name] || '';
                const width = configHeader.logoSizes[name] || el.getAttribute('data-width') || '80px';

                if (src) {
                    el.innerHTML = `<img src="${src}" style="width:${width};height:auto;display:block;">`;
                } else {
                    el.innerHTML = `[Logo: ${name}]`;
                }
            });
        }

        function updateLogoSize(name, size) {
            configHeader.logoSizes[name] = size;
            const editor = tinymce.get('editor');
            if (editor) {
                // Update data-width attribute
                let content = editor.getContent();
                const regex = new RegExp(`(<span[^>]*data-logo="${name}"[^>]*)data-width="[^"]*"`, 'g');
                content = content.replace(regex, `$1data-width="${size}"`);
                if (!content.includes(`data-logo="${name}" data-width=`)) {
                    content = content.replace(new RegExp(`data-logo="${name}"`, 'g'), `data-logo="${name}" data-width="${size}"`);
                }
                editor.setContent(content);

                // Also update image directly in DOM for immediate feedback
                const iframeDoc = editor.getDoc();
                if (iframeDoc) {
                    const logoEl = iframeDoc.querySelector(`[data-logo="${name}"]`);
                    if (logoEl) {
                        const img = logoEl.querySelector('img');
                        if (img) img.style.width = size;
                    }
                }
            }
            scanLogos();
        }

        function updateLogoMode(name, mode) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();

            // Find the logo span and update its classes and attributes
            const logoRegex = new RegExp(`<span([^>]*class=")([^"]*logo-placeholder[^"]*)("[^>]*data-logo="${name}"[^>]*)(?:\\s*style="[^"]*")?([^>]*)>`, 'g');

            content = content.replace(logoRegex, function(match, before, classes, middle, after) {
                // Remove existing floating/front/behind classes
                let newClasses = classes.replace(/\s*(floating|front|behind)/g, '').trim();

                // Add new classes based on mode
                if (mode === 'front') {
                    newClasses += ' floating front';
                } else if (mode === 'behind') {
                    newClasses += ' floating behind';
                }

                // Add or remove style for positioning
                let style = '';
                let posAttrs = '';
                if (mode !== 'inline') {
                    style = ' style="top: 20px; left: 20px;"';
                    posAttrs = ' data-pos-mode="' + mode + '" data-pos-x="20" data-pos-y="20"';
                }

                // Clean up old position attributes if inline
                let cleanMiddle = middle.replace(/\s*data-pos-mode="[^"]*"/g, '')
                                        .replace(/\s*data-pos-x="[^"]*"/g, '')
                                        .replace(/\s*data-pos-y="[^"]*"/g, '');

                return `<span${before}${newClasses}${cleanMiddle}${posAttrs}${style}${after}>`;
            });

            editor.setContent(content);
            setTimeout(scanLogos, 100);
        }

        function updateLogoPos(name, axis, value) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();

            // Update position data attribute
            const attrName = axis === 'x' ? 'data-pos-x' : 'data-pos-y';
            const styleProp = axis === 'x' ? 'left' : 'top';

            // Update data attribute
            const attrRegex = new RegExp(`(<span[^>]*data-logo="${name}"[^>]*)${attrName}="[^"]*"`, 'g');
            content = content.replace(attrRegex, `$1${attrName}="${value}"`);

            // Update style
            const styleRegex = new RegExp(`(<span[^>]*data-logo="${name}"[^>]*style="[^"]*?)${styleProp}:\\s*[^;]+;?`, 'g');
            content = content.replace(styleRegex, `$1${styleProp}: ${value}px;`);

            editor.setContent(content);
        }

        // TTD functions
        function updateTtd(i, field, value) {
            configTtd[i][field] = value;
            // Update in editor if label or nama_field changed
            if (field === 'label' || field === 'nama_field') {
                updateTtdInEditor(configTtd[i].id, field, value);
            }
        }

        function updateTtdInEditor(ttdId, field, value) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            if (field === 'label') {
                // Update data-label attribute
                content = content.replace(
                    new RegExp(`(<div[^>]*data-ttd="${ttdId}"[^>]*)data-label="[^"]*"`, 'g'),
                    `$1data-label="${value}"`
                );
                // Update displayed label text
                content = content.replace(
                    new RegExp(`(<div[^>]*data-ttd="${ttdId}"[^>]*>\\s*<div[^>]*>)[^<]*(</div>)`, 'g'),
                    `$1${value}$2`
                );
            } else if (field === 'nama_field') {
                content = content.replace(
                    new RegExp(`(<div[^>]*data-ttd="${ttdId}"[^>]*)data-nama-field="[^"]*"`, 'g'),
                    `$1data-nama-field="${value}"`
                );
            }
            editor.setContent(content);
        }

        function updateTtdAttr(ttdId, attrName, value, idx) {
            // Update configTtd
            if (idx !== undefined && configTtd[idx]) {
                configTtd[idx][attrName] = value;
            }

            // Map JS property to data attribute name
            const dataAttrMap = {
                ttdModes: 'data-ttd-modes',
                qrData: 'data-ttd-qr-data',
                defaultNama: 'data-default-nama',
                allowedRoles: 'data-allowed-roles',    // RBAC: comma-separated role names
                allowedUsers: 'data-allowed-users',    // RBAC: comma-separated id_pegawai
            };
            const dataAttr = dataAttrMap[attrName];
            if (!dataAttr) return;

            const editor = tinymce.get('editor');
            if (!editor) return;
            let content = editor.getContent();

            const escapedVal = String(value).replace(/"/g, '&quot;');

            // Find the entire opening div tag for this TTD, parse attrs order-independently
            const tagRegex = new RegExp(`<div([^>]*data-ttd="${ttdId}"[^>]*)>`, 'g');
            content = content.replace(tagRegex, function(match, attrs) {
                // Remove any existing occurrence of this attr (anywhere in the tag)
                let newAttrs = attrs.replace(new RegExp(`\\s*${dataAttr}="[^"]*"`, 'g'), '');
                // Append new attribute (if value is non-empty)
                if (value) newAttrs += ` ${dataAttr}="${escapedVal}"`;
                return `<div${newAttrs}>`;
            });

            // Kalau attr = defaultNama, update juga preview text (dots → default nama atau sebaliknya)
            if (attrName === 'defaultNama') {
                const previewText = value ? value : '..................';
                const escPreview = previewText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                // Regex: match <div ttdId>...<div (last one)>(anything)</div></div>
                const previewRegex = new RegExp(
                    `(<div[^>]*data-ttd="${ttdId}"[^>]*>\\s*<div[^>]*>[\\s\\S]*?</div>\\s*<div[^>]*>[\\s\\S]*?</div>\\s*<div[^>]*>)\\([^)]*\\)(</div>\\s*</div>)`,
                    'g'
                );
                content = content.replace(previewRegex, `$1(${escPreview})$2`);
            }

            editor.setContent(content);
            renderTtd();
        }

        // Quick preset: isi field QR dengan {verify_url} — atau clear kalau sudah aktif (toggle)
        function setTtdVerifyUrl(ttdId, idx) {
            const current = configTtd[idx] && configTtd[idx].qrData || '';
            const isActive = current.includes('{verify_url}');
            const newVal = isActive ? '' : '{verify_url}';
            updateTtdAttr(ttdId, 'qrData', newVal, idx);
            // Sync input display juga (kalau visible)
            const inp = document.getElementById('ttdQrInput_' + idx);
            if (inp) inp.value = newVal;
        }

        async function deleteTtd(i) {
            if (!(await ezdocConfirm(t('confirm.delete_ttd', {}, 'Delete this signature?'), { title: 'Delete Signature', variant: 'danger', confirmText: 'Delete' }))) return;
            const ttdId = configTtd[i].id;
            // Remove from editor
            const editor = tinymce.get('editor');
            if (editor) {
                let content = editor.getContent();
                // Match outer div with exactly 3 nested inner divs
                // Structure: <div data-ttd><div>label</div><div>line</div><div>dots</div></div>
                content = content.replace(
                    new RegExp(`<div[^>]*data-ttd="${ttdId}"[^>]*>\\s*<div[^>]*>[\\s\\S]*?</div>\\s*<div[^>]*>[\\s\\S]*?</div>\\s*<div[^>]*>[\\s\\S]*?</div>\\s*</div>(&nbsp;)?`, 'g'),
                    ''
                );
                editor.setContent(content);
            }
            configTtd.splice(i, 1);
            renderTtd();
        }

        function updateTtdMode(ttdId, mode) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const regex = new RegExp(`<div([^>]*class=")([^"]*ttd-placeholder[^"]*)("[^>]*data-ttd="${ttdId}"[^>]*)(?:\\s*style="[^"]*")?([^>]*)>`, 'g');

            content = content.replace(regex, function(match, before, classes, middle, after) {
                let newClasses = classes.replace(/\s*(floating|front|behind)/g, '').trim();

                if (mode === 'front') newClasses += ' floating front';
                else if (mode === 'behind') newClasses += ' floating behind';

                let style = '';
                let posAttrs = '';
                if (mode !== 'inline') {
                    style = ' style="top: 100px; left: 50px;"';
                    posAttrs = ' data-pos-mode="' + mode + '" data-pos-x="50" data-pos-y="100"';
                }

                let cleanMiddle = middle.replace(/\s*data-pos-mode="[^"]*"/g, '')
                                        .replace(/\s*data-pos-x="[^"]*"/g, '')
                                        .replace(/\s*data-pos-y="[^"]*"/g, '');

                return `<div${before}${newClasses}${cleanMiddle}${posAttrs}${style}>`;
            });

            editor.setContent(content);
            setTimeout(scanTtdPlaceholders, 100);
        }

        function updateTtdPos(ttdId, axis, value) {
            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const attrName = axis === 'x' ? 'data-pos-x' : 'data-pos-y';
            const styleProp = axis === 'x' ? 'left' : 'top';

            const attrRegex = new RegExp(`(<div[^>]*data-ttd="${ttdId}"[^>]*)${attrName}="[^"]*"`, 'g');
            content = content.replace(attrRegex, `$1${attrName}="${value}"`);

            const styleRegex = new RegExp(`(<div[^>]*data-ttd="${ttdId}"[^>]*style="[^"]*?)${styleProp}:\\s*[^;]+;?`, 'g');
            content = content.replace(styleRegex, `$1${styleProp}: ${value}px;`);

            editor.setContent(content);
        }

        function renderTtd() {
            const list = document.getElementById('ttdList');
            const empty = document.getElementById('ttdEmpty');
            const countBadge = document.getElementById('ttdCount');
            if (!list) return;

            // Update count badge
            if (countBadge) countBadge.textContent = configTtd.length;

            if (configTtd.length === 0) { list.innerHTML = ''; if (empty) empty.style.display = 'block'; return; }
            if (empty) empty.style.display = 'none';

            // Mode accent — inline (emerald), floating (amber for front, slate for behind)
            const ttdModeMap = {
                'inline':  { accent: 'before:bg-emerald-400', headerBg: 'from-emerald-50/70 to-white', badgeCls: 'bg-emerald-50 text-emerald-700 ring-emerald-200', label: t('mode.inline', {}, 'Inline') },
                'front':   { accent: 'before:bg-amber-400',   headerBg: 'from-amber-50/70 to-white',   badgeCls: 'bg-amber-50 text-amber-700 ring-amber-200',       label: t('mode.front', {}, 'Front') },
                'behind':  { accent: 'before:bg-slate-400',   headerBg: 'from-slate-50/70 to-white',   badgeCls: 'bg-slate-50 text-slate-700 ring-slate-200',       label: t('mode.behind', {}, 'Behind') }
            };

            list.innerHTML = configTtd.map((ttd, i) => {
                const mode = ttd.mode || 'inline';
                const isFloating = mode !== 'inline';
                const eLabel = escapeHtml(ttd.label || 'TTD ' + (i + 1));
                const eNamaField = escapeHtml(ttd.nama_field || '');
                const eId = escapeHtml(ttd.id);
                const m = ttdModeMap[mode] || ttdModeMap['inline'];
                const ttdModes = ttd.ttdModes || 'image';
                const hasQr = ttdModes.includes('qr');
                const searchText = ((ttd.label || '') + ' ' + (ttd.nama_field || '') + ' ' + mode + ' ' + ttdModes).toLowerCase();
                const verifyActive = (ttd.qrData || '').includes('{verify_url}');

                return `
                <div class="mb-2 bg-white border border-gray-200 rounded-md overflow-hidden shadow-sm hover:border-gray-300 hover:shadow-md transition-all panel-list-item relative before:absolute before:left-0 before:top-0 before:bottom-0 before:w-1 ${m.accent} before:content-['']" data-search-text="${escapeHtml(searchText)}" data-focus-target="ttd:${eId}">
                    <!-- Card Header -->
                    <div class="flex items-center gap-1.5 pl-3 pr-2 py-1.5 bg-gradient-to-b ${m.headerBg} border-b border-gray-200">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded ring-1 ring-inset ${m.badgeCls} shrink-0"><i class="bi bi-pen text-[10px]"></i></span>
                        <span class="text-xs font-medium text-gray-900 truncate flex-1 min-w-0">${eLabel}</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-gray-600 bg-white/80 ring-1 ring-inset ring-gray-200 shrink-0">${m.label}</span>
                        <button type="button" class="inline-flex items-center p-0.5 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 shrink-0" onclick="deleteTtd(${i})" title="${t('actions.delete', {}, 'Delete')}"><i class="bi bi-trash text-xs"></i></button>
                    </div>
                    <!-- Card Body -->
                    <div class="pl-3 pr-2 py-2 space-y-1.5 bg-gradient-to-b from-white to-gray-50/30">
                        <!-- Row 1: Label + Nama field (2-col) -->
                        <div class="grid grid-cols-2 gap-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('field.label_label', {}, 'Label')}</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${escapeHtml(ttd.label || '')}" oninput="updateTtd(${i}, 'label', this.value)" placeholder="${t('ttd.label_placeholder', {}, 'Signature label')}">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('ttd.name_field_label', {}, 'Name field')}</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white font-mono" value="${eNamaField}" oninput="updateTtd(${i}, 'nama_field', this.value)" placeholder="nama_dokter">
                            </div>
                        </div>
                        <!-- Row 2: Mode select -->
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('materai.position_label', {}, 'Position')}</label>
                            <select class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-1.5 py-1 bg-white" onchange="updateTtdMode('${eId}', this.value)">
                                <option value="inline" ${mode === 'inline' ? 'selected' : ''}>${t('mode.inline', {}, 'Inline')}</option>
                                <option value="front" ${mode === 'front' ? 'selected' : ''}>${t('mode.floating_front', {}, 'Floating Front')}</option>
                                <option value="behind" ${mode === 'behind' ? 'selected' : ''}>${t('mode.floating_behind', {}, 'Floating Behind')}</option>
                            </select>
                        </div>
                        ${isFloating ? `
                        <div class="grid grid-cols-2 gap-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Pos X</label>
                                <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${ttd.posX || 50}" onchange="updateTtdPos('${eId}', 'x', this.value)">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Pos Y</label>
                                <input type="number" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${ttd.posY || 100}" onchange="updateTtdPos('${eId}', 'y', this.value)">
                            </div>
                        </div>
                        <p class="text-[10px] text-gray-500 italic">${t('panel.drag_hint', {item: 'TTD'}, 'Drag {item} in the editor to change its position')}</p>
                        ` : ''}
                        <!-- Row 3: TTD Mode (image/qr/both) -->
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('ttd.display_label', {}, 'Display')}</label>
                            <select class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-1.5 py-1 bg-white" onchange="updateTtdAttr('${eId}', 'ttdModes', this.value, ${i})">
                                <option value="image" ${ttdModes === 'image' ? 'selected' : ''}>${t('ttd.display_image', {}, 'Image')}</option>
                                <option value="qr" ${ttdModes === 'qr' ? 'selected' : ''}>QR Code</option>
                                <option value="image,qr" ${ttdModes === 'image,qr' ? 'selected' : ''}>${t('ttd.display_image_qr', {}, 'Image + QR')}</option>
                            </select>
                        </div>
                        ${hasQr ? `
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('ttd.qr_data_label', {}, 'QR Data')} <span class="text-gray-400 font-normal normal-case">${t('ttd.qr_data_hint', {}, '— use {nama_field}')}</span></label>
                            <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white font-mono" id="ttdQrInput_${i}" value="${escapeHtml(ttd.qrData || '')}" onchange="updateTtdAttr('${eId}', 'qrData', this.value, ${i})" placeholder="{nama_dokter}">
                        </div>
                        <button type="button" class="w-full inline-flex items-center justify-center gap-1 rounded text-[11px] font-medium py-1 ${verifyActive ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50'}" onclick="setTtdVerifyUrl('${eId}', ${i})" title="${t('ttd.verify_qr_title', {}, 'QR for document authenticity verification')}">
                            <i class="bi bi-shield-check"></i>${verifyActive ? t('ttd.verify_active', {}, 'Verification active') : t('ttd.verify_use', {}, 'Use document verification')}
                        </button>
                        ` : ''}
                        <!-- Row: Default nama -->
                        <div>
                            <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">${t('ttd.default_name_label', {}, 'Default name')}</label>
                            <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white" value="${escapeHtml(ttd.defaultNama || '')}" onchange="updateTtdAttr('${eId}', 'defaultNama', this.value, ${i})" placeholder="dr. Hilmi...">
                        </div>
                    </div>
                    <!-- Card Footer: RBAC collapsible -->
                    <details class="border-t border-gray-200 group bg-gray-50/40">
                        <summary class="cursor-pointer text-[11px] font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100/60 flex items-center gap-1.5 pl-3 pr-2 py-1.5 select-none list-none transition-colors">
                            <i class="bi bi-chevron-right text-[10px] text-gray-400 transition-transform group-open:rotate-90"></i>
                            <i class="bi bi-lock text-[10px]"></i>
                            ${t('ttd.access_label', {}, 'Access')} <span class="text-gray-400 font-normal">${t('ttd.access_empty_hint', {}, '(empty = all)')}</span>
                        </summary>
                        <div class="pl-3 pr-2 py-1.5 bg-gray-50 border-t border-gray-200/60 space-y-1.5">
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Roles</label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white font-mono" value="${escapeHtml(ttd.allowedRoles || '')}" onchange="updateTtdAttr('${eId}', 'allowedRoles', this.value, ${i})" placeholder="dokter_dpjp,perawat">
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5 uppercase tracking-wide">Users <span class="text-gray-400 font-normal normal-case">(id_pegawai)</span></label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm focus:border-gray-500 focus:ring-1 focus:ring-gray-400 text-xs px-2 py-1 bg-white font-mono" value="${escapeHtml(ttd.allowedUsers || '')}" onchange="updateTtdAttr('${eId}', 'allowedUsers', this.value, ${i})" placeholder="42,99">
                            </div>
                        </div>
                    </details>
                </div>`;
            }).join('');

            // Re-apply filter if search has value
            const ts = document.getElementById('ttdSearch');
            if (ts && ts.value) filterPanel('ttdList', ts.value);
        }

        async function confirmDelete(id, name) {
            if (!(await ezdocConfirm(t('confirm.delete_template', {name: name}, 'Delete template "{name}"?'), { title: 'Delete Template', variant: 'danger', confirmText: 'Delete' }))) return;
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }

        // Keyboard shortcut
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (document.getElementById('templateId')) saveTemplate();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            renderTtd();
        });

        // Ensure editor is properly sized after page fully loads
        window.addEventListener('load', function() {
            const editor = tinymce.get('editor');
            if (editor) {
                setTimeout(function() {
                    const height = getEditorHeight();
                    const container = editor.getContainer();
                    if (container) {
                        container.style.height = height + 'px';
                        // Force iframe height
                        const editArea = container.querySelector('.tox-edit-area');
                        if (editArea) editArea.style.height = (height - 50) + 'px';
                        const iframe = container.querySelector('iframe');
                        if (iframe) iframe.style.height = '100%';
                    }
                }, 200);
            }
        });
    </script>

    <?php include __DIR__ . '/../_partials/dialog_helper.php'; ?>
<?php if (!$__ezdoc_isFragment): ?>
</body>
</html>
<?php endif; ?>
