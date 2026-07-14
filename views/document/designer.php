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

// Route params — allow caller override; fallback to GET (backward compat).
$action = isset($action) ? (string)$action : ($_GET['action'] ?? 'list');
$id     = isset($id)     ? (int)$id       : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$message = '';
$messageType = '';

if (isset($_GET['msg'])) {
    $message = $_GET['msg'] === 'saved' ? 'Template berhasil disimpan' : ($_GET['msg'] === 'deleted' ? 'Template berhasil dihapus' : '');
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

        /* PRESERVE: JS-toggled class for filter (JS adds/removes at runtime) */
        .panel-list-item-hidden { display: none !important; }
    </style>
</head>
<body>
<?php endif; /* !$__ezdoc_isFragment */ ?>
    <div class="fixed top-5 right-5 z-[9999]" id="toastContainer"></div>

    <?php if ($action === 'list'): ?>
    <section>
        <?= \Ezdoc\UI\Slot::render('designer:list-header-extra', ['templates' => $templates]) ?>
        <?php if ($message): ?>
        <div x-data="{ open: true }" x-show="open" class="p-4 rounded-md mb-4 flex items-start justify-between <?= $messageType === 'error' ? 'bg-red-50 border-l-4 border-red-400 text-red-800' : 'bg-green-50 border-l-4 border-green-400 text-green-800' ?>">
            <div class="text-sm"><?= h($message) ?></div>
            <button type="button" class="ml-4 text-current opacity-70 hover:opacity-100" @click="open = false">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Header — matches list.php pattern: h1 title + primary action button -->
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                <?= h($designerListTitle) ?>
            </h1>
            <a href="<?= h($urlCreate) ?>"
               class="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
               style="background-color: var(--ezdoc-primary);">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
                </svg>
                Buat Template
            </a>
        </div>

        <?php if (empty($templates)): ?>
        <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-3 text-sm text-gray-500">
                Belum ada template. <a href="<?= h($urlCreate) ?>" class="hover:underline" style="color: var(--ezdoc-primary);">Buat template baru</a>
            </p>
        </div>
        <?php else: ?>
        <?php
            // Build category counts for filter dropdown
            $catCounts = ['__all__' => count($templates), '__none__' => 0];
            foreach ($templates as $t) {
                $c = trim($t['category'] ?? '');
                if ($c === '') { $catCounts['__none__']++; }
                else { $catCounts[$c] = ($catCounts[$c] ?? 0) + 1; }
            }
            $catKeys = array_keys($catCounts);
        ?>

        <!-- Filter form — matches list.php grid pattern -->
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4 items-end">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Cari</label>
                <input type="search" id="tplSearchInput"
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm"
                       placeholder="Cari nama template..." oninput="filterTemplateList()">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Kategori</label>
                <select id="catFilterSelect" onchange="setCategoryFilter(this.value)"
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
                    <option value="__all__">Semua kategori (<?= $catCounts['__all__'] ?>)</option>
                    <?php if ($catCounts['__none__'] > 0): ?>
                    <option value="__none__">(Tanpa kategori) (<?= $catCounts['__none__'] ?>)</option>
                    <?php endif; ?>
                    <?php foreach ($catKeys as $ck): if ($ck === '__all__' || $ck === '__none__') continue; ?>
                    <option value="<?= h($ck) ?>"><?= h($ck) ?> (<?= $catCounts[$ck] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm" id="tplTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Nama Template</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Kategori</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Update</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($templates as $t): $locked = !empty($t['is_locked']); $cat = trim($t['category'] ?? ''); ?>
                    <tr id="tplRow<?= $t['id'] ?>" class="tpl-row hover:bg-gray-50" data-cat="<?= $cat === '' ? '__none__' : h($cat) ?>" data-name="<?= h(strtolower($t['nama_template'])) ?>">
                        <td class="px-4 py-3 font-medium text-gray-900"><?= h($t['nama_template']) ?></td>
                        <td class="px-4 py-3"><?= $cat === '' ? '<span class="text-gray-400">&mdash;</span>' : '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset bg-cyan-50 text-cyan-700 ring-cyan-200">'.h($cat).'</span>' ?></td>
                        <td class="px-4 py-3">
                            <span id="lockBadge<?= $t['id'] ?>" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset <?= $locked ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-gray-100 text-gray-700 ring-gray-300' ?>">
                                <i class="bi <?= $locked ? 'bi-lock-fill' : 'bi-unlock' ?> mr-1"></i>
                                <?= $locked ? 'Locked' : 'Open' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= date('d M Y H:i', strtotime($t['updated_at'])) ?></td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-1">
                                <a href="<?= h(str_replace('{id}', (string)$t['id'], $urlPrint . (strpos($urlPrint,'?') !== false ? '&' : '?') . 'template_id=' . $t['id'])) ?>" class="inline-flex items-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900" title="Cetak"><i class="bi bi-printer"></i></a>
                                <a href="<?= h(str_replace('{id}', (string)$t['id'], $urlEditPattern)) ?>" class="inline-flex items-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900" title="Edit"><i class="bi bi-pencil"></i></a>
                                <button type="button" id="lockBtn<?= $t['id'] ?>" class="inline-flex items-center rounded-md border p-1.5 <?= $locked ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-gray-300 text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>" onclick="toggleLock(<?= $t['id'] ?>, <?= $locked ? 1 : 0 ?>)" title="<?= $locked ? 'Unlock' : 'Lock' ?>">
                                    <i class="bi <?= $locked ? 'bi-lock-fill' : 'bi-unlock' ?>"></i>
                                </button>
                                <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900" onclick="copyTemplate(<?= $t['id'] ?>)" title="Duplikat"><i class="bi bi-files"></i></button>
                                <button type="button" class="inline-flex items-center rounded-md border border-red-300 bg-white p-1.5 text-red-600 hover:bg-red-50" onclick="confirmDelete(<?= $t['id'] ?>, '<?= h($t['nama_template']) ?>')" title="Hapus"><i class="bi bi-trash"></i></button>
                                <?= \Ezdoc\UI\Slot::render('designer:list-row-actions-extra', ['template' => $t]) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="tplEmptyMsg" class="mt-3 text-center py-4 text-sm text-gray-500" style="display:none;"><i class="bi bi-funnel mr-1"></i>Tidak ada template yang cocok dengan filter.</div>
                <script>
                    // Endpoint URLs injected from PHP Config
                    const EZDOC_LIST_URLS = <?= json_encode(['toggle' => $urlToggle, 'copy' => $urlCopy], JSON_UNESCAPED_SLASHES) ?>;

                    async function toggleLock(id, currentLocked) {
                        const newLocked = currentLocked ? 0 : 1;
                        const fd = new FormData();
                        fd.append('ajax', '1');
                        fd.append('action', 'toggle_lock');
                        fd.append('template_id', id);
                        fd.append('locked', newLocked);
                        // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1toggle_lock
                        const resp = await fetch(EZDOC_LIST_URLS.toggle, { method: 'POST', body: fd });
                        const data = await resp.json();
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Gagal: ' + (data.message || 'error'));
                        }
                    }

                    async function copyTemplate(id) {
                        if (!confirm('Duplikat template ini?')) return;
                        const fd = new FormData();
                        fd.append('ajax', '1');
                        fd.append('action', 'copy_template');
                        fd.append('template_id', id);
                        // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1copy
                        const resp = await fetch(EZDOC_LIST_URLS.copy, { method: 'POST', body: fd });
                        const data = await resp.json();
                        if (data.success) {
                            alert('Berhasil dibuat: ' + data.nama);
                            location.reload();
                        } else {
                            alert('Gagal: ' + (data.message || 'error'));
                        }
                    }

                    // ===== Category filter + search =====
                    let activeCatFilter = '__all__';
                    function setCategoryFilter(cat) {
                        activeCatFilter = cat || '__all__';
                        filterTemplateList();
                    }
                    function filterTemplateList() {
                        const q = (document.getElementById('tplSearchInput')?.value || '').trim().toLowerCase();
                        let visible = 0;
                        document.querySelectorAll('.tpl-row').forEach(tr => {
                            const cat = tr.dataset.cat || '__none__';
                            const name = tr.dataset.name || '';
                            const catOk = activeCatFilter === '__all__' || activeCatFilter === cat;
                            const qOk = !q || name.includes(q);
                            const show = catOk && qOk;
                            tr.style.display = show ? '' : 'none';
                            if (show) visible++;
                        });
                        const empty = document.getElementById('tplEmptyMsg');
                        const tbl = document.getElementById('tplTable');
                        if (empty) empty.style.display = visible === 0 ? '' : 'none';
                        if (tbl) tbl.style.display = visible === 0 ? 'none' : '';
                    }
                </script>
                <?php endif; ?>
        <form id="deleteForm" method="POST" action="<?= h($urlDelete) ?>" style="display:none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="delete_id" id="deleteId">
        </form>
    </section>

    <?php else: ?>
    <!-- EDITOR MODE -->
    <div class="h-screen overflow-hidden p-0 w-full" id="ezdocDesignerRoot" data-ezdoc-urls='<?= h(json_encode($ezdocUrls, JSON_UNESCAPED_SLASHES)) ?>'>
        <div class="h-full flex flex-wrap">
            <!-- Main Editor Area -->
            <div class="h-full flex flex-col w-full md:w-2/3 lg:w-3/4">
                <!-- Top Bar -->
                <div class="bg-gray-900 text-white p-2 flex justify-between items-center shrink-0">
                    <div class="flex items-center flex-wrap gap-1.5">
                        <a href="<?= h($urlEditorBack) ?>" class="inline-flex items-center px-2 py-1 rounded text-xs border border-white text-white hover:bg-white/10" title="Kembali ke daftar template"><i class="bi bi-arrow-left"></i></a>
                        <input type="text" id="namaTemplate" class="rounded border-gray-300 shadow-sm text-xs px-2 py-1 text-gray-900 w-[220px]" value="<?= h($template['nama_template'] ?? '') ?>" placeholder="Nama Template *">
                        <input type="text" id="templateCategory" list="categoryList" class="rounded border-gray-300 shadow-sm text-xs px-2 py-1 text-gray-900 w-[160px]" value="<?= h($template['category'] ?? '') ?>" placeholder="Kategori (opsional)" title="Kategori/folder pengelompokan template" maxlength="100">
                        <datalist id="categoryList">
                            <?php foreach ($existingCategories as $cat): ?>
                            <option value="<?= h($cat) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <select id="templateDocScope" class="rounded border-gray-300 shadow-sm text-xs px-2 py-1 text-gray-900 w-[180px]" title="Jenis template — Umum untuk surat non-pasien (pesanan, memo, tugas)">
                            <?php $__scope = $template['doc_scope'] ?? 'patient'; ?>
                            <option value="patient" <?= $__scope === 'patient' ? 'selected' : '' ?>>Surat Pasien (butuh NORM)</option>
                            <option value="general" <?= $__scope === 'general' ? 'selected' : '' ?>>Surat Umum (tanpa NORM)</option>
                        </select>
                        <span class="save-indicator" id="saveIndicator"></span>
                    </div>
                    <div class="flex items-center">
                        <?php if (!empty($template['is_locked'])): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 mr-2" title="Template locked: perubahan destruktif akan di-warn"><i class="bi bi-lock-fill mr-1"></i>Locked</span>
                        <?php endif; ?>
                        <?= \Ezdoc\UI\Slot::render('designer:toolbar-extra', ['template' => $template]) ?>
                        <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-green-600 text-white hover:bg-green-700" onclick="saveTemplate()"><i class="bi bi-check-lg mr-1"></i>Simpan</button>
                        <a href="<?= h($urlPrint . (strpos($urlPrint,'?') !== false ? '&' : '?') . 'template_id=' . ($template['id'] ?? 0)) ?>" id="btnPreview" class="inline-flex items-center px-2 py-1 rounded text-xs border border-white text-white hover:bg-white/10 ml-1" target="_blank"><i class="bi bi-printer mr-1"></i>Preview</a>
                        <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs border border-white text-white hover:bg-white/10 ml-1" onclick="showParamsSummary()"><i class="bi bi-link-45deg mr-1"></i>URL Params</button>
                        <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs border border-white text-white hover:bg-white/10 ml-1" onclick="showFieldInspector()"><i class="bi bi-search mr-1"></i>Inspect Fields</button>
                    </div>
                </div>

                <input type="hidden" id="templateId" value="<?= $template['id'] ?? 0 ?>">

                <!-- Editor Wrapper (paper background) -->
                <div class="bg-slate-500 p-5 flex-1 overflow-auto" id="editorWrapper">
                    <div class="bg-white mx-auto shadow-[0_4px_20px_rgba(0,0,0,0.3)]" id="editorContainer">
                        <textarea id="editor"><?= h($template['template_html'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="w-full md:w-1/3 lg:w-1/4 bg-white h-screen overflow-y-auto p-3">
                <?= \Ezdoc\UI\Slot::render('designer:sidebar-header', ['template' => $template]) ?>
                <!-- Page Settings -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 mb-2.5">
                    <h6 class="text-base font-semibold mb-2"><i class="bi bi-file-earmark mr-1"></i>Ukuran Kertas</h6>
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-2 px-2 py-1" id="paperSize" onchange="updatePageSize()">
                        <option value="A4">A4 (210 x 297 mm)</option>
                        <option value="A5">A5 (148 x 210 mm)</option>
                        <option value="Letter">Letter (216 x 279 mm)</option>
                        <option value="Legal">Legal (216 x 356 mm)</option>
                        <option value="F4">F4/Folio (215 x 330 mm)</option>
                        <option value="Custom">Custom...</option>
                    </select>
                    <!-- Custom Size -->
                    <div id="customSizePanel" class="grid grid-cols-2 gap-1 mb-2" style="display:none;">
                        <div>
                            <label class="block text-xs text-gray-700 mb-0">Lebar (mm)</label>
                            <input type="number" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="customWidth" value="210" min="50" max="500" oninput="updatePageSize()">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-700 mb-0">Tinggi (mm)</label>
                            <input type="number" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="customHeight" value="297" min="50" max="500" oninput="updatePageSize()">
                        </div>
                    </div>
                    <!-- Orientation -->
                    <div class="inline-flex w-full mb-2 rounded overflow-hidden border border-gray-300" role="group">
                        <input type="radio" class="hidden peer/portrait" name="orientation" id="orientPortrait" value="portrait" checked onchange="updatePageSize()">
                        <label class="flex-1 text-center text-xs px-2 py-1 cursor-pointer bg-gray-50 hover:bg-gray-100 peer-checked/portrait:bg-gray-700 peer-checked/portrait:text-white" for="orientPortrait"><i class="bi bi-phone"></i> Portrait</label>
                        <input type="radio" class="hidden peer/landscape" name="orientation" id="orientLandscape" value="landscape" onchange="updatePageSize()">
                        <label class="flex-1 text-center text-xs px-2 py-1 cursor-pointer bg-gray-50 hover:bg-gray-100 peer-checked/landscape:bg-gray-700 peer-checked/landscape:text-white border-l border-gray-300" for="orientLandscape"><i class="bi bi-phone-landscape"></i> Landscape</label>
                    </div>
                    <h6 class="text-base font-semibold mb-2 mt-3"><i class="bi bi-border-outer mr-1"></i>Padding (mm)</h6>
                    <div class="grid grid-cols-2 gap-1">
                        <div>
                            <label class="block text-xs text-gray-700 mb-0">Atas</label>
                            <input type="number" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="padTop" value="20" min="0" max="100" oninput="updatePageSize()">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-700 mb-0">Bawah</label>
                            <input type="number" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="padBottom" value="20" min="0" max="100" oninput="updatePageSize()">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-700 mb-0">Kiri</label>
                            <input type="number" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="padLeft" value="20" min="0" max="100" oninput="updatePageSize()">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-700 mb-0">Kanan</label>
                            <input type="number" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="padRight" value="20" min="0" max="100" oninput="updatePageSize()">
                        </div>
                    </div>
                </div>

                <!-- Field Panel (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: true }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold"><i class="bi bi-input-cursor-text mr-1"></i>Fields <span id="fieldCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="relative mb-1">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1 pr-6" id="fieldSearch" placeholder="🔍 Cari field (nama/label/tipe)..." oninput="filterPanel('fieldList', this.value)">
                                <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 border-0 bg-transparent text-gray-400 hover:text-red-500 text-base leading-none cursor-pointer px-1" onclick="clearPanelSearch('fieldSearch','fieldList')" title="Clear">×</button>
                            </div>
                            <div id="fieldList"></div>
                            <div id="fieldEmpty" class="text-center text-gray-500 text-xs py-2">
                                Klik "+ Field" di editor
                            </div>
                            <div id="fieldNoMatch" class="text-center text-gray-500 text-xs py-2" style="display:none;">
                                <em>Tidak ada field cocok dengan pencarian.</em>
                            </div>
                            <button type="button" class="w-full mt-1 inline-flex items-center justify-center px-2 py-1 rounded text-xs border border-gray-500 text-gray-700 hover:bg-gray-50" onclick="openVarManager()">
                                <i class="bi bi-gear mr-1"></i>Kelola Variabel Default
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Logo Panel (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold"><i class="bi bi-image mr-1"></i>Logo <span id="logoCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div id="logoList"></div>
                            <div id="logoEmpty" class="text-center text-gray-500 text-xs py-2">
                                Klik "+ Logo" di editor
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TTD Panel (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold"><i class="bi bi-pen mr-1"></i>Tanda Tangan <span id="ttdCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-800">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="relative mb-1">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1 pr-6" id="ttdSearch" placeholder="🔍 Cari TTD..." oninput="filterPanel('ttdList', this.value)">
                                <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 border-0 bg-transparent text-gray-400 hover:text-red-500 text-base leading-none cursor-pointer px-1" onclick="clearPanelSearch('ttdSearch','ttdList')" title="Clear">×</button>
                            </div>
                            <div id="ttdList"></div>
                            <div id="ttdEmpty" class="text-center text-gray-500 text-xs py-2">
                                Klik "+ TTD" di editor
                            </div>
                            <div id="ttdNoMatch" class="text-center text-gray-500 text-xs py-2" style="display:none;">
                                <em>Tidak ada TTD cocok.</em>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Materai Panel (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold text-[#c2410c]"><i class="bi bi-stamp mr-1"></i>Materai <span id="materaiCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-[#c2410c] text-white">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="relative mb-1">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1 pr-6" id="materaiSearch" placeholder="🔍 Cari Materai..." oninput="filterPanel('materaiList', this.value)">
                                <button type="button" class="absolute top-1/2 right-1.5 -translate-y-1/2 border-0 bg-transparent text-gray-400 hover:text-red-500 text-base leading-none cursor-pointer px-1" onclick="clearPanelSearch('materaiSearch','materaiList')" title="Clear">×</button>
                            </div>
                            <div id="materaiList"></div>
                            <div id="materaiEmpty" class="text-center text-gray-500 text-xs py-2">
                                Klik "+ Materai" di editor
                            </div>
                            <div id="materaiNoMatch" class="text-center text-gray-500 text-xs py-2" style="display:none;">
                                <em>Tidak ada materai cocok.</em>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Query DB Panel (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold text-cyan-700"><i class="bi bi-database mr-1"></i>Query DB <span id="tabledbCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-cyan-700 text-white">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <button type="button" class="w-full mb-2 inline-flex items-center justify-center px-2 py-1 rounded text-xs border border-cyan-500 text-cyan-600 hover:bg-cyan-50" onclick="openTabledbModal()"><i class="bi bi-plus-lg mr-1"></i>Tambah Query</button>
                            <div id="tabledbList"></div>
                            <div id="tabledbEmpty" class="text-center text-gray-500 text-xs py-2">
                                Belum ada query.<br>
                                <span class="text-xs">Tambah query → variabel <code>{{tabledb.x.kolom}}</code> bisa dipakai di tabel TinyMCE biasa untuk repeating row.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- QR Panel (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold"><i class="bi bi-qr-code mr-1"></i>QR Code <span id="qrCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-cyan-100 text-cyan-800">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div id="qrList"></div>
                            <div id="qrEmpty" class="text-center text-gray-500 text-xs py-2">
                                Klik "+ QR" di editor
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Konfigurasi Verifikasi Publik (Collapsible) -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }" x-init="$watch('open', v => { if(v) window.dispatchEvent(new CustomEvent('verify-panel-shown')) })">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold text-sky-700"><i class="bi bi-shield-check mr-1"></i>Konfig Verifikasi <span id="verifyFieldCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse id="verifyConfigCollapse">
                        <div class="p-2 pt-0">
                            <div class="text-gray-500 mb-2 text-[11px]">
                                Field yang tampil di halaman verifikasi publik (saat QR di-scan). Kosongkan untuk pakai default (norm+nama pasien+field umum).
                            </div>

                            <!-- Toggle: tampilkan data pasien -->
                            <div class="flex items-center gap-2 mb-2 text-xs">
                                <input class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" type="checkbox" id="verifyShowPatient" onchange="onVerifyConfigChange()">
                                <label class="cursor-pointer" for="verifyShowPatient" title="Tampilkan block NORM + Nama Pasien + Nopen">
                                    Tampilkan Data Pasien
                                </label>
                            </div>

                            <hr class="my-2">

                            <!-- Field custom list -->
                            <div class="text-xs mb-1"><strong>Field Custom di Verifikasi:</strong></div>
                            <div id="verifyFieldsList"></div>
                            <div id="verifyFieldsEmpty" class="text-center text-gray-500 py-2 text-[11px]">
                                <em>Belum ada field custom. Pakai tombol + di bawah, atau kosongkan untuk pakai default whitelist.</em>
                            </div>
                            <div class="grid gap-1 mt-1">
                                <button type="button" class="inline-flex items-center justify-center px-2 py-1 rounded border border-blue-600 text-blue-600 hover:bg-blue-50 text-[11px]" onclick="addVerifyField()">
                                    <i class="bi bi-plus-lg"></i> Tambah Field
                                </button>
                                <button type="button" class="inline-flex items-center justify-center px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 text-[11px]" onclick="previewVerifikasi()">
                                    <i class="bi bi-eye"></i> Preview Halaman Verifikasi
                                </button>
                            </div>

                            <div class="text-gray-500 mt-2 text-[10px]">
                                <i class="bi bi-info-circle"></i> Field ini akan tampil urut sesuai daftar di atas
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Konfigurasi Akses Template (Collapsible) - RBAC per template -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-0 mb-2.5" x-data="{ open: false }">
                    <div class="panel-header cursor-pointer bg-gray-50 hover:bg-gray-200 border-b border-gray-200 flex justify-between items-center p-2" :class="{'is-collapsed': !open}" @click="open = !open" role="button">
                        <h6 class="mb-0 text-xs font-semibold text-emerald-800"><i class="bi bi-lock-fill mr-1"></i>Konfig Akses <span id="accessConfigCount" class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">0</span></h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                    <div x-show="open" x-collapse>
                        <div class="p-2 pt-0">
                            <div class="p-2 rounded mb-2 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-[11px]">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Penting</strong>: RBAC per-action <em>independent</em>.
                                Kalau kamu set <strong>Create</strong> tapi <strong>Edit</strong> kosong,
                                user tetap bisa <em>update</em> dokumen (backward compat v2).
                                Kalau mau kunci semua action, isi ketiganya, atau pakai tombol
                                <strong>"Salin ke Semua"</strong> di bawah.
                            </div>

                            <!-- Mode enforcement -->
                            <div class="mb-2">
                                <label class="font-bold text-[11px]">Mode Enforcement:</label>
                                <select class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessMode" onchange="onAccessConfigChange()">
                                    <option value="strict">Strict — tolak dengan error</option>
                                    <option value="permissive">Permissive — allow tapi log audit</option>
                                </select>
                            </div>

                            <!-- Status Akses Anda (real-time preview based on current inputs) -->
                            <div class="mb-2 p-2 border border-gray-300 rounded bg-green-50 text-[11px]">
                                <div class="flex justify-between items-center mb-1">
                                    <strong class="text-emerald-800"><i class="bi bi-person-check"></i> Status Akses Anda</strong>
                                    <button type="button" class="inline-flex items-center px-1 py-0 rounded border border-gray-500 text-gray-700 hover:bg-gray-50 text-[10px]"
                                            onclick="refreshAccessPreview()" title="Refresh cek berdasarkan input di atas">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                                <div class="text-[10px] text-gray-500">
                                    User ID: <code><?= (int)($author_id ?? 0) ?></code>
                                    &middot; Roles: <code><?= h(implode(', ', is_array($author_role_array ?? null) ? $author_role_array : [])) ?></code>
                                </div>
                                <div id="accessPreviewResult" class="mt-1"></div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="flex gap-1 mb-2 flex-wrap">
                                <button type="button" class="inline-flex items-center px-2 py-0 rounded border border-blue-600 text-blue-600 hover:bg-blue-50 text-[10px]" onclick="copyAccessCreateToAll()" title="Copy roles+users dari CREATE ke EDIT & LOCK — supaya semua action punya restriction yang sama">
                                    <i class="bi bi-arrow-down-square"></i> Salin ke Semua Action
                                </button>
                                <button type="button" class="inline-flex items-center px-2 py-0 rounded border border-red-600 text-red-600 hover:bg-red-50 text-[10px]" onclick="clearAccessConfig()" title="Reset semua field jadi kosong (= allow all)">
                                    <i class="bi bi-x-circle"></i> Reset
                                </button>
                                <button type="button" class="inline-flex items-center px-2 py-0 rounded border border-yellow-500 text-yellow-600 hover:bg-yellow-50 text-[10px]" onclick="fillAccessSuperadminOnly()" title="Isi ketiga action dengan role 'superadmin' saja">
                                    <i class="bi bi-shield-lock"></i> Lock ke Superadmin
                                </button>
                            </div>

                            <hr class="my-2">

                            <!-- Per-action config: create -->
                            <div class="mb-2">
                                <label class="font-bold text-blue-600 text-[11px]">
                                    <i class="bi bi-plus-circle"></i> Boleh CREATE dokumen:
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessCreateRoles"
                                       placeholder="Roles: dokter,perawat (kosong = semua)"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessCreateUsers"
                                       placeholder="User IDs: 42,99 (kosong = semua)"
                                       oninput="onAccessConfigChange()">
                            </div>

                            <!-- Per-action config: edit -->
                            <div class="mb-2">
                                <label class="font-bold text-yellow-600 text-[11px]">
                                    <i class="bi bi-pencil"></i> Boleh EDIT dokumen:
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessEditRoles"
                                       placeholder="Roles: dokter,perawat (kosong = semua)"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessEditUsers"
                                       placeholder="User IDs: 42,99 (kosong = semua)"
                                       oninput="onAccessConfigChange()">
                            </div>

                            <!-- Per-action config: lock -->
                            <div class="mb-2">
                                <label class="font-bold text-red-600 text-[11px]">
                                    <i class="bi bi-lock"></i> Boleh LOCK dokumen:
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessLockRoles"
                                       placeholder="Roles: kepala_bidang (kosong = semua)"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessLockUsers"
                                       placeholder="User IDs (kosong = semua)"
                                       oninput="onAccessConfigChange()">
                            </div>

                            <!-- Per-action config: delete (default: superadmin-only kalau kosong, beda dgn create/edit/lock!) -->
                            <div class="mb-2">
                                <label class="font-bold text-[11px] text-[#7f1d1d]">
                                    <i class="bi bi-trash"></i> Boleh DELETE dokumen:
                                </label>
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm px-2 py-1 text-[11px]" id="accessDeleteRoles"
                                       placeholder="Roles (kosong = superadmin only)"
                                       oninput="onAccessConfigChange()">
                                <input type="text" class="w-full rounded border-gray-300 shadow-sm mt-1 px-2 py-1 text-[11px]" id="accessDeleteUsers"
                                       placeholder="User IDs (kosong = superadmin only)"
                                       oninput="onAccessConfigChange()">
                                <div class="mt-1 text-[10px] text-[#7f1d1d]">
                                    <i class="bi bi-exclamation-circle"></i> Delete = destructive. Default kosong = <strong>superadmin only</strong> (beda dgn create/edit/lock).
                                </div>
                            </div>

                            <div class="text-gray-500 mt-2 text-[10px]">
                                <i class="bi bi-info-circle"></i> Format: comma-separated. Role name harus match dengan hasRole().
                                User ID adalah id_pegawai. Superadmin selalu boleh apapun.
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
                                <span>Preview Halaman Verifikasi</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white text-yellow-600 text-[10px] font-bold tracking-wider">MODE PREVIEW</span>
                            </h6>
                            <button type="button" class="text-white opacity-80 hover:opacity-100 text-2xl leading-none" @click="$store.modals.verifyPreview = false" aria-label="Close">&times;</button>
                        </div>
                        <!-- Body: iframe langsung tanpa banner injected -->
                        <div class="p-0 h-[78vh] bg-gray-100 overflow-hidden relative">
                            <div id="verifyPreviewLoading" class="absolute inset-0 flex items-center justify-center text-gray-500 bg-white z-[5]">
                                <div class="text-center">
                                    <div class="inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" role="status"></div>
                                    <div class="mt-2 text-xs">Merender preview...</div>
                                </div>
                            </div>
                            <iframe id="verifyPreviewIframe" class="w-full h-full border-0 block bg-gray-100" title="Preview Verifikasi" sandbox="allow-same-origin"></iframe>
                        </div>
                        <div class="py-2 px-3 bg-gray-100 border-0 flex justify-between items-center">
                            <small class="text-gray-500 text-[11px]">
                                <i class="bi bi-info-circle"></i> Nilai field = <strong>mock/contoh</strong> berdasarkan field yg ada di template. Real: dari field_values dokumen.
                            </small>
                            <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.verifyPreview = false">Tutup</button>
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
                        <strong>Shortcut:</strong> Ctrl+S untuk simpan<br>
                        <strong>+ Field:</strong> Text, Number, Date, Checkbox, Radio, Select<br>
                        <span class="field-tag">+ Logo</span> untuk logo<br>
                        <span class="field-tag">+ Tabel</span> untuk tabel<br>
                        <span class="field-tag">+ Tabel DB</span> untuk tabel query database
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
                <h6 class="text-base font-semibold"><i class="bi bi-gear mr-1"></i>Kelola Variabel Default</h6>
                <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="$store.modals.varManager = false">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-xs text-gray-500 mb-2">Variabel yang diizinkan untuk dipakai sebagai default value field (prefix <code>$</code>). Contoh: ketik <code>$author_nama</code> di kolom Default.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-200 text-xs mb-2">
                        <thead class="bg-gray-100">
                            <tr><th class="border border-gray-200 px-2 py-1 text-left">Nama Variabel</th><th class="border border-gray-200 px-2 py-1 text-left">Keterangan</th><th class="border border-gray-200 px-2 py-1 w-[50px]"></th></tr>
                        </thead>
                        <tbody id="varListBody">
                            <tr><td colspan="3" class="border border-gray-200 px-2 py-1 text-center text-gray-500">Memuat...</td></tr>
                        </tbody>
                    </table>
                </div>
                <h6 class="text-sm mt-3 font-semibold">Tambah Variabel</h6>
                <div class="grid grid-cols-12 gap-2">
                    <div class="col-span-5">
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="newVarName" placeholder="nama_variabel">
                    </div>
                    <div class="col-span-5">
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="newVarDesc" placeholder="Keterangan">
                    </div>
                    <div class="col-span-2">
                        <button type="button" class="w-full inline-flex items-center justify-center px-2 py-1 rounded text-xs bg-blue-600 text-white hover:bg-blue-700" onclick="addVar()"><i class="bi bi-plus"></i></button>
                    </div>
                </div>
                <div class="mt-3 p-2 bg-gray-100 rounded text-xs">
                    <strong>Format default value:</strong><br>
                    <code>text biasa</code> — langsung jadi nilai default<br>
                    <code>date:d F Y</code> — tanggal hari ini (format PHP)<br>
                    <code>$nama_variabel</code> — isi variabel PHP (harus ada di whitelist)
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
                <h6 class="text-base font-semibold"><i class="bi bi-search mr-1"></i>Field Inspector &amp; Migration</h6>
                <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="$store.modals.fieldInspector = false">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-xs text-gray-500 mb-2">Cek penggunaan field di dokumen yang sudah ada, rename field dengan migrasi data, atau hapus orphan data (data yang fieldnya sudah tidak ada di template).</p>

                <!-- Usage table -->
                <h6 class="text-sm font-bold mt-2">Daftar Field</h6>
                <div class="overflow-x-auto">
                    <table class="min-w-full border border-gray-200 text-xs">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border border-gray-200 px-2 py-1 text-left">Field</th>
                                <th class="border border-gray-200 px-2 py-1 text-left">Status</th>
                                <th class="border border-gray-200 px-2 py-1 text-left" width="100">Dipakai di</th>
                                <th class="border border-gray-200 px-2 py-1 text-left" width="120">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="fieldInspectorBody">
                            <tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-gray-500">Memuat...</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Rename section -->
                <h6 class="text-sm font-bold mt-3">Rename Field &amp; Migrasi Data</h6>
                <p class="text-xs text-gray-500 mb-2">Ganti nama field di template <strong>dan</strong> migrate semua <code>field_values</code> dokumen existing. Aksi ini irreversible — pastikan nama baru benar.</p>
                <div class="grid grid-cols-12 gap-2 items-end">
                    <div class="col-span-5">
                        <label class="block text-xs text-gray-700 mb-0">Nama lama</label>
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="renameFieldOld" placeholder="nama_lama">
                    </div>
                    <div class="col-span-5">
                        <label class="block text-xs text-gray-700 mb-0">Nama baru</label>
                        <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="renameFieldNew" placeholder="nama_baru">
                    </div>
                    <div class="col-span-2">
                        <button type="button" class="w-full inline-flex items-center justify-center px-2 py-1 rounded text-xs bg-yellow-500 text-white hover:bg-yellow-600" onclick="runRenameField()"><i class="bi bi-arrow-right"></i> Rename</button>
                    </div>
                </div>
                <small class="text-gray-500 text-xs">Akan: (1) update <code>{{old}}</code> → <code>{{new}}</code> di editor, (2) migrate key di semua dokumen</small>

                <!-- Orphan cleanup -->
                <h6 class="text-sm font-bold mt-3">Cleanup Orphan Data</h6>
                <p class="text-xs text-gray-500 mb-2">Hapus key di <code>field_values</code> dokumen yang tidak ada di template lagi. TTD-related keys (<code>_ttd_mode_*</code>, <code>*_qr</code>) tidak ikut terhapus.</p>
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs border border-red-600 text-red-600 hover:bg-red-50" onclick="runOrphanCleanup()"><i class="bi bi-eraser"></i> Jalankan Cleanup</button>
            </div>
            <div class="flex items-center justify-end px-4 py-3 border-t border-gray-200 gap-2">
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.fieldInspector = false">Tutup</button>
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
                <h6 class="text-base font-semibold"><i class="bi bi-database mr-1"></i><span id="tabledbModalTitle">Tambah Query DB</span></h6>
                <button type="button" class="text-white opacity-80 hover:opacity-100 text-2xl leading-none" @click="$store.modals.tabledb = false">&times;</button>
            </div>
            <div class="p-4">
                <input type="hidden" id="tabledbEditNs" value="">
                <div class="mb-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nama Query (namespace) *</label>
                    <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1" id="tabledbNs" placeholder="contoh: lab, obat, riwayat" maxlength="32">
                    <small class="text-gray-500 text-xs">Hanya huruf, angka, underscore. Variabel akan jadi <code>{{tabledb.&lt;namespace&gt;.kolom}}</code></small>
                </div>
                <div class="mb-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1">SQL SELECT</label>
                    <textarea class="w-full rounded border-gray-300 shadow-sm text-xs px-2 py-1 font-mono" id="tabledbSql" rows="5" placeholder="SELECT kolom1, kolom2, kolom3 FROM tabel WHERE nopen = {nopen}"></textarea>
                    <small class="text-gray-500 text-xs">Pakai <code>{nopen}</code>, <code>{norm}</code>, <code>{nama_field}</code> untuk parameter dari form cetak. Hanya SELECT/WITH, query lain ditolak.</small>
                </div>
                <div class="mb-2 flex gap-2">
                    <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-blue-600 text-white hover:bg-blue-700" onclick="analyzeTabledbSql()"><i class="bi bi-search mr-1"></i>Analyze (Lihat Kolom)</button>
                    <span id="tabledbAnalyzeStatus" class="text-xs self-center"></span>
                </div>
                <div id="tabledbColumnsBox" class="mb-2" style="display:none;">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Kolom Tersedia (klik untuk insert ke editor)</label>
                    <div id="tabledbColumns" class="flex flex-wrap gap-1"></div>
                    <small class="text-gray-500 block mt-1 text-xs">Tip: insert tabel TinyMCE biasa, lalu paste/insert variabel <code>{{tabledb.x.kolom}}</code> di baris yang akan di-repeat. Header & footer (baris tanpa variabel) tetap statis.</small>
                </div>
            </div>
            <div class="flex items-center justify-end px-4 py-3 border-t border-gray-200 gap-2">
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.tabledb = false">Batal</button>
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-blue-600 text-white hover:bg-blue-700" onclick="saveTabledbQuery()"><i class="bi bi-check-lg mr-1"></i>Simpan Query</button>
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
                <h6 class="text-base font-semibold"><i class="bi bi-link-45deg mr-1"></i>Parameter URL Template</h6>
                <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="$store.modals.params = false">&times;</button>
            </div>
            <div class="p-4">
                <p class="text-xs text-gray-500 mb-2">URL lengkap yang bisa kamu kirim ke halaman cetak. Ganti nilai <code>&lt;...&gt;</code> sesuai data yang mau di-prefill.</p>
                <div class="flex mb-3">
                    <textarea id="paramsUrlOutput" class="flex-1 rounded-l border border-gray-300 shadow-sm text-xs px-2 py-1 font-mono" rows="4" readonly></textarea>
                    <button class="inline-flex items-center px-3 py-1 rounded-r border border-blue-600 text-blue-600 hover:bg-blue-50" type="button" onclick="copyParamsUrl()"><i class="bi bi-clipboard"></i></button>
                </div>
                <h6 class="text-sm font-bold">Daftar Parameter</h6>
                <div id="paramsList" class="text-xs"></div>
            </div>
            <div class="flex items-center justify-end px-4 py-3 border-t border-gray-200 gap-2">
                <button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs bg-gray-500 text-white hover:bg-gray-600" @click="$store.modals.params = false">Tutup</button>
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
            icon.innerHTML = isErr ? '⚠' : '✓';
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
                titleEl.textContent = 'Edit Query: ' + editNs;
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
                titleEl.textContent = 'Tambah Query DB';
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
            if (!sql) { status.textContent = 'SQL kosong'; status.className = 'small text-danger align-self-center'; return; }
            status.textContent = 'Menganalisa...';
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
                    status.textContent = '✓ ' + cols.length + ' kolom ditemukan';
                    status.className = 'small text-success align-self-center';
                } else {
                    status.textContent = '✗ ' + (data.message || 'Gagal analyze');
                    status.className = 'small text-danger align-self-center';
                }
            } catch (e) {
                status.textContent = '✗ Network error: ' + e.message;
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
                return `<button type="button" class="inline-flex items-center px-2 py-1 rounded text-xs border border-cyan-500 text-cyan-600 hover:bg-cyan-50" onclick="insertTabledbVar('${tag}')" title="Klik untuk insert ke editor">${escapeHtml(tag)}</button>`;
            }).join('');
        }

        function insertTabledbVar(tag) {
            const editor = tinymce.get('editor');
            if (!editor) return;
            editor.execCommand('mceInsertContent', false, tag);
            showToast('Variabel disisipkan: ' + tag);
        }

        function saveTabledbQuery() {
            let ns = (document.getElementById('tabledbNs').value || '').trim();
            const sql = (document.getElementById('tabledbSql').value || '').trim();
            const editNs = document.getElementById('tabledbEditNs').value;
            if (!ns) { showToast('Nama query (namespace) wajib diisi', 'error'); return; }
            ns = ns.replace(/[^a-zA-Z0-9_]/g, '');
            if (!ns) { showToast('Namespace hanya huruf/angka/underscore', 'error'); return; }
            if (!sql) { showToast('SQL kosong', 'error'); return; }

            // Get columns currently rendered (if user analyzed)
            const colButtons = document.querySelectorAll('#tabledbColumns button');
            const columns = Array.from(colButtons).map(b => {
                const m = b.textContent.match(/\.([a-zA-Z_]\w*)\}\}$/);
                return m ? m[1] : null;
            }).filter(x => x);

            // Prevent rename collision (if not editing existing)
            if (!editNs && configHeader.tableDbQueries && configHeader.tableDbQueries[ns]) {
                if (!confirm('Namespace "' + ns + '" sudah ada. Override?')) return;
            }

            configHeader.tableDbQueries[ns] = { sql: sql, columns: columns };
            renderTabledbList();
            showToast('Query "' + ns + '" disimpan');
            closeAppModal('tabledb');
        }

        function deleteTabledbQuery(ns) {
            if (!confirm('Hapus query "' + ns + '"? Variabel di editor yang sudah dipakai akan jadi "—" saat cetak.')) return;
            if (configHeader.tableDbQueries) delete configHeader.tableDbQueries[ns];
            renderTabledbList();
            showToast('Query "' + ns + '" dihapus');
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
                    return `<button type="button" class="inline-flex items-center py-0 px-1 mb-1 rounded border border-cyan-500 text-cyan-600 hover:bg-cyan-50" style="font-size:10px;" onclick="insertTabledbVar('${tag}')" title="Insert ${escapeHtml(tag)}">${escapeHtml(c)}</button>`;
                }).join(' ');
                const moreCount = cols.length > 8 ? ` <small class="text-gray-500">+${cols.length - 8} lagi</small>` : '';
                return `
                <div class="mb-2 p-2 bg-gray-100 rounded" style="border-left: 3px solid #0e7490;">
                    <div class="flex justify-between items-start mb-1">
                        <div>
                            <strong class="text-xs" style="color:#0e7490;">${escapeHtml(ns)}</strong>
                            <div class="text-gray-500" style="font-size:10px;">${cols.length} kolom</div>
                        </div>
                        <div class="inline-flex">
                            <button type="button" class="inline-flex items-center py-0 px-1 rounded-l border border-gray-500 text-gray-700 hover:bg-gray-50" onclick="openTabledbModal('${ns}')" title="Edit"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="inline-flex items-center py-0 px-1 rounded-r border border-red-600 text-red-600 hover:bg-red-50" onclick="deleteTabledbQuery('${ns}')" title="Hapus"><i class="bi bi-trash"></i></button>
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
            body.innerHTML = '<tr><td colspan="3" class="border border-gray-200 px-2 py-1 text-center text-gray-500">Memuat...</td></tr>';
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'list_vars');
            // spec: ezdoc-spec/openapi.yaml#/paths/~1default_vars~1list
            fetch(EZDOC_URLS.listVars || '', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.vars.length) {
                        body.innerHTML = '<tr><td colspan="3" class="border border-gray-200 px-2 py-1 text-center text-gray-500">Belum ada variabel</td></tr>';
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
                        alert(data.message || 'Gagal');
                    }
                });
        }

        function deleteVar(id) {
            if (!confirm('Hapus variabel ini dari whitelist?')) return;
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

            const container = document.getElementById('editorContainer');
            if (container) {
                container.style.width = paperWidth + 'mm';
                container.style.minHeight = paperHeight + 'mm';
            }

            // Update TinyMCE body padding and container height
            const editor = tinymce.get('editor');
            if (editor) {
                const iframe = editor.getContainer().querySelector('iframe');
                if (iframe && iframe.contentDocument) {
                    const body = iframe.contentDocument.body;
                    body.style.padding = `${padTop}mm ${padRight}mm ${padBottom}mm ${padLeft}mm`;
                    body.style.minHeight = (paperHeight - padTop - padBottom) + 'mm';
                }

                // Resize editor height based on paper size
                const newHeight = Math.round(paperHeight * 3.78);
                const editorContainer = editor.getContainer();
                if (editorContainer) {
                    editorContainer.style.height = newHeight + 'px';
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
            indicator.textContent = 'Menyimpan...';
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
                indicator.textContent = 'Dibatalkan';
                indicator.className = 'save-indicator';
                setTimeout(() => { indicator.textContent = ''; }, 2000);
                return;
            }

            try {
                const saveUrl = EZDOC_URLS.save || window.location.href;
                const resp = await fetch(saveUrl, { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.success) {
                    indicator.textContent = 'Tersimpan';
                    indicator.className = 'save-indicator saved';
                    showToast(data.message);
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
                    indicator.textContent = 'Gagal';
                    indicator.className = 'save-indicator';
                    showToast(data.message, 'error');
                }
            } catch (e) {
                indicator.textContent = 'Error';
                indicator.className = 'save-indicator';
                showToast('Gagal menyimpan: ' + e.message, 'error');
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
            params.push({ key: 'template_id', desc: 'ID template (auto dari URL)', placeholder: templateId });
            params.push({ key: 'norm', desc: 'No Rekam Medis', placeholder: '<norm>' });
            params.push({ key: 'nopen', desc: 'No Pendaftaran', placeholder: '<nopen>' });
            params.push({ key: 'label', desc: 'Label dokumen (pembeda jika nopen sama)', placeholder: '<label>' });

            // Scan {{field}} placeholders from template
            const fieldNames = new Set();
            const fieldRegex = /<span[^>]*class="[^"]*field-placeholder[^"]*"[^>]*>\{\{([^}]+)\}\}<\/span>/g;
            let m;
            while ((m = fieldRegex.exec(content)) !== null) fieldNames.add(m[1]);
            // Also bare {{field}} fallback
            const bareRegex = /\{\{([^}]+)\}\}/g;
            while ((m = bareRegex.exec(content)) !== null) fieldNames.add(m[1]);

            fieldNames.forEach(fn => {
                params.push({ key: fn, desc: 'Field: ' + fn, placeholder: '<' + fn + '>' });
            });

            // Scan QR placeholders (data-qr="fieldName")
            const qrRegex = /data-qr="([^"]+)"/g;
            const qrFields = new Set();
            while ((m = qrRegex.exec(content)) !== null) qrFields.add(m[1]);
            qrFields.forEach(qf => {
                if (!fieldNames.has(qf)) {
                    params.push({ key: qf, desc: 'QR field: ' + qf, placeholder: '<' + qf + '>' });
                }
            });

            // TTD-related params from configTtd
            (configTtd || []).forEach(ttd => {
                const nf = ttd.nama_field || ('nama_' + ttd.id);
                const lbl = ttd.label || 'TTD';
                if (!fieldNames.has(nf)) {
                    params.push({ key: nf, desc: 'Nama penandatangan — ' + lbl, placeholder: '<nama ' + lbl + '>' });
                }
                const modes = ttd.ttdModes || 'image';
                if (modes.includes('qr')) {
                    params.push({ key: nf + '_qr', desc: 'Konten QR — ' + lbl, placeholder: '<konten QR ' + lbl + '>' });
                }
            });

            // Build URL — use configured print URL
            const base = EZDOC_URLS.print || '';
            if (!base) {
                alert('Print/preview endpoint belum di-configure. Set urls.print di Config untuk enable preview.');
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
                showToast('URL disalin');
            } catch (e) {
                document.execCommand('copy');
                showToast('URL disalin');
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
                alert('Simpan template dulu sebelum inspect.');
                return;
            }

            const body = document.getElementById('fieldInspectorBody');
            body.innerHTML = '<tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-gray-500">Memuat...</td></tr>';
            openAppModal('fieldInspector');

            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'field_usage_all');
            fd.append('template_id', templateId);
            // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1field_usage
            const resp = await fetch(EZDOC_URLS.fieldUsage || '', { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.success) {
                body.innerHTML = '<tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-red-600">Gagal memuat: ' + (data.message || 'error') + '</td></tr>';
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
                    statusBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Aktif</span>';
                } else if (inTemplate && usedCount === 0) {
                    statusBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Belum dipakai</span>';
                } else {
                    statusBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Orphan</span>';
                }
                rows.push(`
                    <tr>
                        <td class="border border-gray-200 px-2 py-1"><code>${escapeHtml(fn)}</code></td>
                        <td class="border border-gray-200 px-2 py-1">${statusBadge}</td>
                        <td class="border border-gray-200 px-2 py-1">${usedCount} / ${totalDocs} dok</td>
                        <td class="border border-gray-200 px-2 py-1">
                            ${inTemplate ? `<button class="inline-flex items-center py-0 px-1 rounded border border-yellow-500 text-yellow-600 hover:bg-yellow-50" onclick="prefillRename('${escapeHtml(fn)}')" title="Rename"><i class="bi bi-arrow-right"></i></button>` : ''}
                        </td>
                    </tr>
                `);
            });
            body.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="4" class="border border-gray-200 px-2 py-1 text-center text-gray-500">Belum ada field</td></tr>';
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
            if (templateId === '0') { alert('Simpan template dulu'); return; }
            if (!oldName || !newName || oldName === newName) { alert('Nama lama dan nama baru harus diisi berbeda'); return; }
            if (!confirm(`Rename field "${oldName}" → "${newName}" di template + migrate data di semua dokumen? Aksi ini tidak bisa di-undo.`)) return;

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
                showToast(`Rename berhasil. ${data.updated} dokumen ter-update.`);
                document.getElementById('renameFieldOld').value = '';
                document.getElementById('renameFieldNew').value = '';
                showFieldInspector();
            } else {
                alert('Gagal migrate data: ' + (data.message || 'error'));
            }
        }

        // Cleanup orphan data across all documents
        async function runOrphanCleanup() {
            const templateId = document.getElementById('templateId')?.value || '0';
            if (templateId === '0') { alert('Simpan template dulu'); return; }
            if (!confirm('Hapus semua key di field_values yang tidak ada di template lagi?')) return;

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
                showToast(`Cleanup selesai. ${data.updated} dokumen ter-update. Key yang dihapus: ${data.removedKeys.join(', ') || '-'}`);
                showFieldInspector();
            } else {
                alert('Gagal: ' + (data.message || 'error'));
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
                        const ok = confirm(`Template ini LOCKED. Field berikut akan hilang/rename:\n\n${removed.join('\n')}\n\nLanjut? (disarankan pakai "Inspect Fields" untuk rename/migrate dulu)`);
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
            const paperSize = configHeader.paperSize || 'A4';
            const orientation = configHeader.orientation || 'portrait';
            let paperHeight;

            if (paperSize === 'Custom') {
                paperHeight = configHeader.customHeight || 297;
                const paperWidth = configHeader.customWidth || 210;
                if (orientation === 'landscape') {
                    paperHeight = paperWidth; // Swap for landscape
                }
            } else {
                const paper = PAPER_SIZES[paperSize];
                paperHeight = paper?.height || 297;
                if (orientation === 'landscape') {
                    paperHeight = paper?.width || 210; // Swap for landscape
                }
            }
            // Convert mm to pixels (approximate: 1mm = 3.78px at 96dpi)
            return Math.round(paperHeight * 3.78);
        }

        tinymce.init({
            selector: '#editor',
            height: getEditorHeight(),
            width: '100%',
            menubar: false,
            statusbar: false,
            resize: false,
            plugins: 'advlist anchor autolink charmap code fullscreen help hr image insertdatetime lists link nonbreaking pagebreak preview searchreplace table visualblocks visualchars wordcount',
            toolbar: [
                'undo redo | blocks fontfamily fontsize lineheightbtn | bold italic underline strikethrough subscript superscript | forecolor backcolor removeformat',
                'alignleft aligncenter alignright alignjustify | outdent indent | bullist numlist | blockquote hr pagebreak | table link anchor charmap insertdatetime nonbreaking image',
                'insertlogo insertqr insertfield insertttd insertmaterai insertcond inserttable | searchreplace visualblocks visualchars wordcount | code preview fullscreen help'
            ],
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
                body {
                    font-family: "Times New Roman", serif;
                    font-size: 12pt;
                    line-height: 1.6;
                    margin: 0;
                    box-sizing: border-box;
                    position: relative;
                }
                p { margin: 8px 0; }
                table { border-collapse: collapse; width: 100%; }
                td, th { border: 1px solid #ccc; padding: 6px; }
                .field-placeholder {
                    background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 3px;
                    font-family: inherit; font-size: inherit; font-weight: inherit; font-style: inherit;
                    white-space: nowrap; display: inline; border: 1px solid #93c5fd;
                }
                .field-placeholder[data-type="number"] { background: #fef3c7; border-color: #fbbf24; color: #92400e; }
                .field-placeholder[data-type="number"]::before { content: '#'; margin-right: 3px; }
                .field-placeholder[data-type="date"] { background: #e0e7ff; border-color: #818cf8; color: #3730a3; }
                .field-placeholder[data-type="date"]::before { content: '📅'; margin-right: 3px; }
                .field-placeholder[data-type="checkbox"] { background: #dcfce7; border-color: #22c55e; color: #166534; }
                .field-placeholder[data-type="checkbox"]::before { content: '☐'; margin-right: 3px; }
                .field-placeholder[data-type="radio"] { background: #fce7f3; border-color: #ec4899; color: #9d174d; }
                .field-placeholder[data-type="radio"]::before { content: '◉'; margin-right: 3px; }
                .field-placeholder[data-type="select"] { background: #f3e8ff; border-color: #a855f7; color: #6b21a8; }
                .field-placeholder[data-type="select"]::before { content: '▼'; margin-right: 3px; }
                /* Inline logo */
                .logo-placeholder {
                    display: inline-block; border: 2px dashed #94a3b8; background: #f1f5f9;
                    padding: 8px; border-radius: 4px; color: #64748b; font-size: 12px;
                    min-width: 60px; text-align: center; vertical-align: middle;
                }
                /* Logo placeholder with image - minimal padding */
                .logo-placeholder img {
                    display: block; border-radius: 2px;
                }
                /* Floating logo - can be positioned freely */
                .logo-placeholder.floating {
                    position: absolute;
                    cursor: move;
                    border-color: #8b5cf6;
                    background: rgba(255, 255, 255, 0.95);
                    padding: 4px;
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
                /* TTD Placeholder */
                .ttd-placeholder {
                    display: inline-block; border: 2px dashed #10b981; background: #ecfdf5;
                    padding: 15px 25px; border-radius: 4px; color: #065f46; font-size: 11px;
                    min-width: 100px; min-height: 60px; text-align: center;
                    vertical-align: middle;
                }
                .ttd-placeholder.floating {
                    position: absolute;
                    cursor: move;
                    border-color: #10b981;
                    background: rgba(236, 253, 245, 0.95);
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
                /* Conditional Section visual marker (editor only) */
                .conditional-section {
                    border: 1px dashed #06b6d4; background: #ecfeff;
                    padding: 8px; border-radius: 4px; margin: 8px 0;
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
                /* QR Placeholder */
                .qr-placeholder {
                    display: inline-block; border: 2px dashed #6366f1; background: #eef2ff;
                    padding: 8px; border-radius: 4px; color: #4338ca; font-size: 11px;
                    min-width: 60px; min-height: 60px; text-align: center;
                    vertical-align: middle;
                }
                .qr-placeholder.floating {
                    position: absolute;
                    cursor: move;
                    border-color: #6366f1;
                    background: rgba(238, 242, 255, 0.95);
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

                // Logo insert with positioning options
                editor.ui.registry.addMenuButton('insertlogo', {
                    text: '+ Logo',
                    tooltip: 'Insert Logo Placeholder',
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: 'Inline (dalam teks)', onAction: function() { insertLogoPrompt('inline'); }},
                            { type: 'menuitem', text: 'Floating - Di Depan Teks', onAction: function() { insertLogoPrompt('front'); }},
                            { type: 'menuitem', text: 'Floating - Di Belakang Teks', onAction: function() { insertLogoPrompt('behind'); }}
                        ]);
                    }
                });

                function insertLogoPrompt(mode) {
                    const name = prompt('Nama logo (contoh: logo_rs):', 'logo_' + Date.now());
                    if (name && name.trim()) {
                        const cleanName = name.trim().replace(/\s+/g, '_').toLowerCase();
                        const width = prompt('Lebar logo (contoh: 80px, 100px):', '80px') || '80px';
                        configHeader.logoSizes[cleanName] = width;

                        let classes = 'logo-placeholder';
                        let style = '';
                        let posAttrs = '';

                        if (mode === 'front' || mode === 'behind') {
                            classes += ' floating ' + mode;
                            style = ` style="top: 20px; left: 20px;"`;
                            posAttrs = ` data-pos-mode="${mode}" data-pos-x="20" data-pos-y="20"`;
                        }

                        editor.insertContent(`<span class="${classes}" data-logo="${cleanName}" data-width="${width}"${posAttrs}${style} contenteditable="false">[Logo: ${cleanName}]</span>&nbsp;`);
                        setTimeout(scanLogos, 100);

                        // Initialize drag for floating logos
                        if (mode !== 'inline') {
                            setTimeout(initLogoDrag, 200);
                        }
                    }
                }

                // QR Code insert with positioning options
                editor.ui.registry.addMenuButton('insertqr', {
                    text: '+ QR',
                    tooltip: 'Insert QR Code Placeholder',
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: 'Inline (dalam teks)', onAction: function() { insertQrPrompt('inline'); }},
                            { type: 'menuitem', text: 'Floating - Di Depan Teks', onAction: function() { insertQrPrompt('front'); }},
                            { type: 'menuitem', text: 'Floating - Di Belakang Teks', onAction: function() { insertQrPrompt('behind'); }}
                        ]);
                    }
                });

                function insertQrPrompt(mode) {
                    const fieldName = prompt('Nama field untuk data QR (contoh: url_verifikasi, no_dokumen):', 'qr_data');
                    if (fieldName && fieldName.trim()) {
                        const cleanName = fieldName.trim().replace(/\s+/g, '_').toLowerCase();
                        const width = prompt('Ukuran QR (contoh: 80px, 100px):', '80px') || '80px';

                        let classes = 'qr-placeholder';
                        let style = '';
                        let posAttrs = '';

                        if (mode === 'front' || mode === 'behind') {
                            classes += ' floating ' + mode;
                            style = ` style="top: 20px; left: 20px;"`;
                            posAttrs = ` data-pos-mode="${mode}" data-pos-x="20" data-pos-y="20"`;
                        }

                        editor.insertContent(`<span class="${classes}" data-qr="${cleanName}" data-width="${width}"${posAttrs}${style} contenteditable="false">[QR: ${cleanName}]</span>&nbsp;`);

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
                    text: '+ Field',
                    tooltip: 'Insert Field Input',
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: 'Text (default)', onAction: () => insertFieldPrompt('text') },
                            { type: 'menuitem', text: 'Number', onAction: () => insertFieldPrompt('number') },
                            { type: 'menuitem', text: 'Date', onAction: () => insertFieldPrompt('date') },
                            { type: 'menuitem', text: 'Checkbox', onAction: () => insertFieldPrompt('checkbox') },
                            { type: 'menuitem', text: 'Radio (pilihan tunggal)', onAction: () => insertFieldPrompt('radio') },
                            { type: 'menuitem', text: 'Select (dropdown)', onAction: () => insertFieldPrompt('select') }
                        ]);
                    }
                });

                function insertFieldPrompt(fieldType) {
                    const name = prompt('Nama field (contoh: nama_pasien):');
                    if (!name || !name.trim()) return;

                    const cleanName = name.trim().replace(/\s+/g, '_').toLowerCase();
                    let options = '';
                    let label = '';

                    // For checkbox, radio, select - ask for options
                    if (fieldType === 'checkbox') {
                        // Label optional - if user cancels or leaves empty, checkbox has no label
                        const inp = prompt('Label checkbox (kosongkan jika tidak ingin label):', '');
                        label = inp === null ? '' : inp.trim();
                    } else if (fieldType === 'radio' || fieldType === 'select') {
                        const opts = prompt('Pilihan (pisah dengan koma):\nContoh: Ya,Tidak,Mungkin', 'Ya,Tidak');
                        if (!opts) return;
                        options = opts;
                        label = prompt('Label (opsional):', '') || '';
                    }

                    // Ask for default value
                    const defaultVal = prompt('Default value (kosong jika tidak ada):\nContoh: text biasa, date:d F Y, $author_nama', '') || '';

                    let attrs = `data-type="${fieldType}"`;
                    if (options) attrs += ` data-options="${options.replace(/"/g, '&quot;')}"`;
                    if (label) attrs += ` data-label="${label.replace(/"/g, '&quot;')}"`;
                    if (defaultVal) attrs += ` data-default="${defaultVal.replace(/"/g, '&quot;')}"`;

                    // Display text in editor - show field name inside
                    editor.insertContent(`<span class="field-placeholder" ${attrs}>{{${cleanName}}}</span>&nbsp;`);
                }

                // TTD insert with positioning options
                editor.ui.registry.addMenuButton('insertttd', {
                    text: '+ TTD',
                    tooltip: 'Insert Tanda Tangan Placeholder',
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: 'Inline (dalam teks)', onAction: function() { insertTtdPrompt('inline'); }},
                            { type: 'menuitem', text: 'Floating - Di Depan Teks', onAction: function() { insertTtdPrompt('front'); }},
                            { type: 'menuitem', text: 'Floating - Di Belakang Teks', onAction: function() { insertTtdPrompt('behind'); }}
                        ]);
                    }
                });

                function insertTtdPrompt(mode) {
                    const label = prompt('Label TTD (contoh: Dokter Penanggung Jawab):', 'Tanda Tangan');
                    if (label && label.trim()) {
                        const ttdId = 'ttd_' + Date.now();
                        const namaField = prompt('Nama field untuk nama penanda tangan:', 'nama_' + ttdId);

                        // Default nama — kalau di-set, akan muncul otomatis kalau field belum diisi
                        const defaultNama = (prompt('Default nama TTD (opsional, kosongkan kalau tidak perlu):\nContoh: dr. Hilmi K Riskawa, Sp.A., M.J', '') || '').trim();

                        // Ask for TTD modes
                        const ttdModesInput = prompt('Mode TTD (image / qr / image,qr):', 'image');
                        const ttdModes = (ttdModesInput || 'image').trim().toLowerCase();

                        let qrData = '';
                        if (ttdModes.includes('qr')) {
                            qrData = prompt('Data QR (bisa pakai {field_name}):\nContoh: Ditandatangani oleh {nama_dokter} pada {tanggal}', '') || '';
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
                        const content = `
                            <div class="${classes}" data-ttd="${ttdId}" data-label="${label.trim()}" data-nama-field="${namaField}"${posAttrs}${extraAttrs}${style} contenteditable="false">
                                <div style="font-size:10px;margin-bottom:5px;">${label.trim()}</div>
                                <div style="border-bottom:1px solid #065f46;width:80px;margin:20px auto 5px;"></div>
                                <div style="font-size:10px;">(${previewNama})</div>
                            </div>
                        `;
                        editor.insertContent(content + '&nbsp;');

                        setTimeout(() => {
                            scanTtdPlaceholders();
                            if (mode !== 'inline') initTtdDrag();
                        }, 100);

                        renderTtd();
                    }
                }

                // ===== Materai (stamp duty) =====
                editor.ui.registry.addMenuButton('insertmaterai', {
                    text: '+ Materai',
                    tooltip: 'Insert Materai (E-Materai upload / area kosong untuk tempel)',
                    fetch: function(callback) {
                        callback([
                            { type: 'menuitem', text: 'Inline (dalam teks)', onAction: function() { insertMateraiPrompt('inline'); }},
                            { type: 'menuitem', text: 'Floating - Di Depan Teks', onAction: function() { insertMateraiPrompt('front'); }},
                            { type: 'menuitem', text: 'Floating - Di Belakang Teks', onAction: function() { insertMateraiPrompt('behind'); }}
                        ]);
                    }
                });

                function insertMateraiPrompt(mode) {
                    // Label optional (bisa kosong)
                    const labelInput = prompt('Label Materai (kosongkan jika tidak perlu label):', 'Materai 10000');
                    if (labelInput === null) return; // user cancelled
                    const label = (labelInput || '').trim();

                    // Mode: upload (e-materai digital) atau kosong (tempel manual setelah print)
                    const matModeInput = (prompt('Mode Materai (upload / kosong):', 'upload') || 'upload').trim().toLowerCase();
                    const matMode = (matModeInput === 'kosong') ? 'kosong' : 'upload';

                    // Ukuran (default e-materai Peruri ~ 26mm x 36mm; di 96dpi ≈ 98x136px). Pakai 100x140 default.
                    const widthInput = prompt('Lebar materai (px):', '100');
                    if (widthInput === null) return;
                    const heightInput = prompt('Tinggi materai (px):', '140');
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

                    const visualLabel = (matMode === 'kosong') ? '(kosong)' : '(upload)';
                    const escLabel = label.replace(/"/g, '&quot;');

                    const content = `
                        <div class="${classes}" data-materai="${materaiId}" data-label="${escLabel}" data-mode="${matMode}" data-width="${matW}" data-height="${matH}"${posAttrs}${style} contenteditable="false">
                            <div style="border:1px dashed #c2410c; width:${matW}px; height:${matH}px; background:#fff7ed; color:#c2410c; text-align:center; font-size:10px; padding:6px; line-height:1.3;">
                                <strong>MATERAI</strong><br>10000<br>${visualLabel}
                            </div>
                        </div>
                    `;
                    editor.insertContent(content + '&nbsp;');

                    setTimeout(() => {
                        scanMateraiPlaceholders();
                        if (mode !== 'inline') initTtdDrag();
                    }, 100);

                    renderMaterai();
                }

                // ===== Conditional Section (#7) =====
                editor.ui.registry.addButton('insertcond', {
                    text: '+ Kondisi',
                    tooltip: 'Insert Conditional Section (show/hide berdasarkan field value)',
                    onAction: function() {
                        const expr = prompt('Expression (contoh: jenis_kelamin=P, atau umur>=17, atau status_nikah!=lajang):\n\nOperator: = != > < >= <=\nGabungan: AND, OR (mis. jenis_kelamin=P AND umur>=17)', 'jenis_kelamin=P');
                        if (!expr || !expr.trim()) return;
                        const escExpr = expr.trim().replace(/"/g, '&quot;');
                        const placeholder = `<div class="conditional-section" data-cond="${escExpr}" contenteditable="true">
                            <div style="font-size:10px;color:#0e7490;background:#ecfeff;padding:2px 6px;border-radius:3px;margin-bottom:4px;display:inline-block;">⏱ Tampil jika: <strong>${escapeHtml(expr.trim())}</strong></div>
                            <p>Tulis konten conditional di sini... (paragraf ini akan disembunyikan kalau kondisi tidak terpenuhi)</p>
                        </div>`;
                        editor.insertContent(placeholder + '<p></p>');
                    }
                });

                editor.ui.registry.addButton('inserttable', {
                    text: '+ Tabel',
                    tooltip: 'Insert Tabel Label-Value',
                    onAction: function() {
                        const count = prompt('Jumlah baris:', '3');
                        if (count && parseInt(count) > 0) {
                            let rows = '';
                            for (let i = 1; i <= parseInt(count); i++) {
                                rows += `<tr><td style="width:30%;border:none;">Label ${i}</td><td style="width:5px;border:none;">:</td><td style="border:none;"><span class="field-placeholder">{{field_${i}}}</span></td></tr>`;
                            }
                            editor.insertContent(`<table style="width:100%;border:none;"><tbody>${rows}</tbody></table><p></p>`);
                        }
                    }
                });

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
                        scanDynTables();
                    }, 300);
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
                        scanDynTables();
                        if (typeof renderTabledbList === 'function') renderTabledbList();
                        updateAllLogosInEditor(); // Show actual logos
                        initLogoDrag();
                        initTtdDrag();

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
                        // updateAllLogosInEditor tetap async supaya lookup logo dari sidebar
                        // tidak blocking (misal lookup image src by name)
                        setTimeout(updateAllLogosInEditor, 50);
                    } catch (e) { console.warn('SetContent restore failed:', e); }
                });
            }
        });
        <?php endif; ?>

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
                'text': 'Text',
                'number': 'Number',
                'date': 'Date',
                'checkbox': 'Checkbox',
                'radio': 'Radio',
                'select': 'Select'
            };

            const typeColors = {
                'text': '#3b82f6',
                'number': '#f59e0b',
                'date': '#8b5cf6',
                'checkbox': '#22c55e',
                'radio': '#ec4899',
                'select': '#a855f7'
            };

            list.innerHTML = fields.map((field, i) => {
                const needsOptions = ['radio', 'select'].includes(field.type);
                const needsLabel = ['checkbox'].includes(field.type);
                const color = typeColors[field.type] || '#3b82f6';
                const eName = escapeHtml(field.name);
                const eLabel = escapeHtml(field.label || '');
                const eOptions = escapeHtml(field.options || '');
                const eDefault = escapeHtml(field.defaultVal || '');

                const searchText = (eName + ' ' + (field.label || '') + ' ' + field.type).toLowerCase();
                return `
                <div class="mb-2 p-2 bg-gray-100 rounded panel-list-item" data-search-text="${escapeHtml(searchText)}" style="border-left: 3px solid ${color};">
                    <div class="flex justify-between items-center mb-1">
                        <strong class="text-xs" style="color:${color};">{{${eName}}}</strong>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="removeField('${eName}')"><i class="bi bi-trash"></i></button>
                    </div>
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Nama</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eName}" onchange="updateFieldName('${eName}', this.value)">
                    </div>
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateFieldType('${eName}', this.value)">
                        <option value="text" ${field.type === 'text' ? 'selected' : ''}>Text</option>
                        <option value="number" ${field.type === 'number' ? 'selected' : ''}>Number</option>
                        <option value="date" ${field.type === 'date' ? 'selected' : ''}>Date</option>
                        <option value="checkbox" ${field.type === 'checkbox' ? 'selected' : ''}>Checkbox</option>
                        <option value="radio" ${field.type === 'radio' ? 'selected' : ''}>Radio</option>
                        <option value="select" ${field.type === 'select' ? 'selected' : ''}>Select</option>
                    </select>
                    ${needsLabel ? `
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Label</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eLabel}" onchange="updateFieldLabel('${eName}', this.value)" placeholder="(kosongkan jika tanpa label)">
                    </div>
                    <small class="text-gray-500 block mb-1 text-xs">Opsional &mdash; kosongkan untuk checkbox tanpa teks</small>
                    ` : ''}
                    ${needsOptions ? `
                    <div class="flex">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Opsi</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eOptions}" onchange="updateFieldOptions('${eName}', this.value)" placeholder="Ya,Tidak,Mungkin">
                    </div>
                    <small class="text-gray-500 text-xs">Pisah dengan koma</small>
                    ` : ''}
                    <div class="flex mt-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Default</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eDefault}" onchange="updateFieldDefault('${eName}', this.value)" placeholder="text, date:d F Y, $author_nama">
                    </div>
                    <details class="mt-1" style="font-size:11px;" data-field-details="${eName}">
                        <summary style="cursor:pointer;color:#0e7490;">⚙ Validasi (opsional)</summary>
                        <div class="p-1 mt-1" style="background:#f0f9ff;border-radius:3px;" data-field-row="${eName}">
                            <div class="flex items-center gap-2 mb-1">
                                <input class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" type="checkbox" id="req_${eName}" ${field.required ? 'checked' : ''} data-validate-key="required-${eName}" onchange="updateFieldValidation('${eName}', 'required', this.checked ? '1' : '')">
                                <label class="text-xs" for="req_${eName}">Wajib diisi (required)</label>
                            </div>
                            ${['text', 'number'].includes(field.type) ? `
                            <div class="grid grid-cols-2 gap-1 mb-1">
                                <div class="flex"><span class="inline-flex items-center px-1 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50" style="font-size:10px;">Min</span><input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-1 py-1" value="${escapeHtml(field.min || '')}" data-validate-key="min-${eName}" onchange="updateFieldValidation('${eName}', 'min', this.value)" placeholder="${field.type === 'number' ? 'nilai' : 'panjang'}"></div>
                                <div class="flex"><span class="inline-flex items-center px-1 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50" style="font-size:10px;">Max</span><input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-1 py-1" value="${escapeHtml(field.max || '')}" data-validate-key="max-${eName}" onchange="updateFieldValidation('${eName}', 'max', this.value)" placeholder="${field.type === 'number' ? 'nilai' : 'panjang'}"></div>
                            </div>
                            <div class="flex mb-1"><span class="inline-flex items-center px-1 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50" style="font-size:10px;">Regex</span><input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-1 py-1 font-mono" value="${escapeHtml(field.pattern || '')}" data-validate-key="pattern-${eName}" onchange="updateFieldValidation('${eName}', 'pattern', this.value)" placeholder="^[0-9]+$ (opsional)"></div>
                            ` : ''}
                            <div class="flex"><span class="inline-flex items-center px-1 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50" style="font-size:10px;">Pesan error</span><input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-1 py-1" value="${escapeHtml(field.errorMsg || '')}" data-validate-key="errorMsg-${eName}" onchange="updateFieldValidation('${eName}', 'errorMsg', this.value)" placeholder="Pesan kalau tidak valid"></div>
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

        function removeField(name) {
            if (!confirm(`Hapus field {{${name}}}?`)) return;

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
                <div class="mb-2 p-2 bg-gray-100 rounded" style="border-left: 3px solid #06b6d4;">
                    <div class="flex justify-between items-center mb-1">
                        <strong class="text-xs" style="color:#06b6d4;"><i class="bi bi-qr-code mr-1"></i>${eName}</strong>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="removeQr('${eName}')"><i class="bi bi-trash"></i></button>
                    </div>
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Nama</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eName}" onchange="updateQrName('${eName}', this.value)">
                    </div>
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">W</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eWidth}" onchange="updateQrWidth('${eName}', this.value)">
                    </div>
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateQrMode('${eName}', this.value)">
                        <option value="inline" ${mode === 'inline' ? 'selected' : ''}>Inline</option>
                        <option value="front" ${mode === 'front' ? 'selected' : ''}>Floating Depan</option>
                        <option value="behind" ${mode === 'behind' ? 'selected' : ''}>Floating Belakang</option>
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
                    <small class="text-gray-500 text-xs">Drag QR di editor untuk ubah posisi</small>
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

        function removeQr(name) {
            if (!confirm(`Hapus QR "${name}"?`)) return;

            const editor = tinymce.get('editor');
            if (!editor) return;

            let content = editor.getContent();
            const regex = new RegExp(`<span[^>]*class="[^"]*qr-placeholder[^"]*"[^>]*data-qr="${name}"[^>]*>[^<]*<\\/span>(&nbsp;)?`, 'g');
            content = content.replace(regex, '');
            editor.setContent(content);
            scanQrPlaceholders();
        }

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
                    found.set(key, { key, label: labelFromKey(key), source: type, group: 'Field Input' });
                }
            }

            // 2. Plain {{field_name}} yang bukan tabledb & bukan internal
            const plainRe = /\{\{([a-zA-Z0-9_.]+)\}\}/g;
            while ((m = plainRe.exec(content)) !== null) {
                const key = m[1];
                if (!key || key.startsWith('_') || key.startsWith('tabledb.')) continue;
                if (found.has(key)) continue;
                found.set(key, { key, label: labelFromKey(key), source: 'variable', group: 'Field Variabel' });
            }

            // 3. data-nama-field dari TTD (nama penanda tangan)
            const ttdRe = /<div[^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*data-nama-field="([^"]+)"/g;
            while ((m = ttdRe.exec(content)) !== null) {
                const key = m[1];
                if (!key || key.startsWith('_')) continue;
                if (found.has(key)) continue;
                found.set(key, { key, label: labelFromKey(key), source: 'ttd', group: 'Nama Penanda Tangan' });
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
                        <span style="cursor:default;color:#9ca3af;flex-shrink:0;font-size:9px;" title="Urutan: ${i + 1}">
                            <i class="bi bi-grip-vertical"></i>${i + 1}
                        </span>
                        <select class="flex-1 rounded border-gray-300 shadow-sm" onchange="onVerifyFieldKeyChange(${i}, this.value)" style="font-size:11px; padding:2px 20px 2px 4px; height:auto;">
                            <option value="">- Pilih field dari template -</option>
                            ${optionsHtml}
                            <option value="__custom__" ${!isDetected && f.key ? 'selected' : ''}>✎ Ketik manual${f.key ? ': ' + escapeHtml(f.key) : ''}</option>
                        </select>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50 flex-shrink-0" onclick="removeVerifyField(${i})" title="Hapus"><i class="bi bi-x"></i></button>
                    </div>
                    <div class="flex items-center gap-1">
                        <span style="width:16px;"></span>
                        <input type="text" class="flex-1 rounded border-gray-300 shadow-sm" value="${escapeHtml(f.label || '')}" oninput="updateVerifyField(${i}, 'label', this.value)" placeholder="Label yg tampil di verifikasi" style="font-size:11px; padding:2px 4px;">
                        ${i > 0 ? `<button type="button" class="inline-flex items-center py-0 px-1 rounded border border-gray-500 text-gray-700 hover:bg-gray-50 flex-shrink-0" onclick="moveVerifyField(${i}, -1)" title="Naik"><i class="bi bi-arrow-up"></i></button>` : '<span style="width:22px;"></span>'}
                        ${i < fields.length - 1 ? `<button type="button" class="inline-flex items-center py-0 px-1 rounded border border-gray-500 text-gray-700 hover:bg-gray-50 flex-shrink-0" onclick="moveVerifyField(${i}, 1)" title="Turun"><i class="bi bi-arrow-down"></i></button>` : '<span style="width:22px;"></span>'}
                    </div>
                    ${!isDetected && f.key ? `<div class="mt-1" style="font-size:10px;color:#f59e0b;"><i class="bi bi-exclamation-triangle"></i> Key "${escapeHtml(f.key)}" tidak ter-detect di template. Pastikan field ini ada di field_values dokumen.</div>` : ''}
                </div>
            `;
            }).join('');
        }

        // Build <optgroup> options dari detected fields
        function renderFieldOptions(detected, currentKey, usedKeys) {
            if (detected.length === 0) {
                return '<option disabled>-- Tidak ada field di template --</option>';
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
                    html += `<option value="${escapeHtml(d.key)}" ${isSelected ? 'selected' : ''} ${isUsed ? 'disabled' : ''}>${escapeHtml(d.label)}${isUsed ? ' (sudah dipakai)' : ''}</option>`;
                });
                html += `</optgroup>`;
            }
            return html;
        }

        // Preset quick-fill buttons — muncul saat custom_fields masih kosong
        function renderVerifyPresets(detected) {
            if (detected.length === 0) {
                return '<div class="text-center py-2" style="font-size:11px;color:#9ca3af;"><em>Tambahkan field di template dulu (mis. dengan tombol + Field di editor), baru bisa dikonfigurasi di sini.</em></div>';
            }
            let html = '<div class="mb-2 mt-1"><small class="text-gray-500 block mb-1 text-xs"><i class="bi bi-lightning-charge"></i> Preset cepat (klik untuk auto-tambah):</small>';
            let anyPreset = false;
            for (const key in VERIFY_PRESETS) {
                const p = VERIFY_PRESETS[key];
                const matched = p.fields.filter(f => detected.some(d => d.key === f.key));
                if (matched.length === 0) continue;
                anyPreset = true;
                html += `<button type="button" class="inline-flex items-center mr-1 mb-1 rounded border border-blue-600 text-blue-600 hover:bg-blue-50" style="font-size:10.5px;padding:2px 8px;" onclick="applyVerifyPreset('${key}')" title="Tambah ${matched.length} field: ${matched.map(f => f.key).join(', ')}">
                    <i class="bi ${p.icon}"></i> ${escapeHtml(p.label)} <span class="ml-1 inline-flex items-center px-1 rounded-full bg-blue-100 text-blue-800" style="font-size:9px;">${matched.length}</span>
                </button>`;
            }
            if (!anyPreset) {
                html += '<em class="text-gray-500" style="font-size:11px;">Belum ada preset yang cocok dengan field di template.</em>';
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
            showToast('Akses CREATE disalin ke EDIT, LOCK & DELETE');
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
            showToast('Akses config di-reset');
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
            showToast('Semua action dikunci untuk superadmin saja');
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
                const customKey = prompt('Ketik nama key field_values (mis. hasil_pemeriksaan):', verifyConfig.custom_fields[idx].key || '');
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
                loadingEl.innerHTML = '<div class="text-center"><div class="inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" role="status"></div><div class="mt-2 text-xs">Merender preview...</div></div>';
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
                    loadingEl.innerHTML = '<div class="text-center text-red-600 p-3"><i class="bi bi-exclamation-triangle" style="font-size:24px;"></i><div class="mt-2">Gagal memuat preview:<br><small>' + escapeHtml(err.message || String(err)) + '</small></div></div>';
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

            list.innerHTML = configMateraiList.map((m, i) => {
                const eId = escapeHtml(m.id);
                const labelDisp = (m.label && m.label.trim()) ? escapeHtml(m.label) : '<em style="color:#9ca3af;">(tanpa label)</em>';
                const posMode = m.posMode || 'inline';
                const isFloating = posMode !== 'inline';
                const w = parseInt(m.width) || 100;
                const h = parseInt(m.height) || 140;
                const searchText = ((m.label || '') + ' ' + (m.mode || '') + ' ' + posMode + ' ' + m.id).toLowerCase();
                return `
                <div class="mb-2 p-2 bg-gray-100 rounded panel-list-item" data-search-text="${escapeHtml(searchText)}" style="border-left: 3px solid #c2410c;">
                    <div class="flex justify-between mb-1">
                        <strong class="text-xs" style="color:#c2410c;">${labelDisp}</strong>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="deleteMaterai(${i})"><i class="bi bi-trash"></i></button>
                    </div>
                    <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" value="${escapeHtml(m.label || '')}" oninput="updateMateraiAttr('${eId}', 'label', this.value, ${i})" placeholder="Label (kosongkan jika tanpa label)">
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateMateraiAttr('${eId}', 'mode', this.value, ${i})">
                        <option value="upload" ${m.mode === 'upload' ? 'selected' : ''}>Upload e-Materai</option>
                        <option value="kosong" ${m.mode === 'kosong' ? 'selected' : ''}>Kosong (tempel manual)</option>
                    </select>
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateMateraiPosMode('${eId}', this.value, ${i})">
                        <option value="inline" ${posMode === 'inline' ? 'selected' : ''}>Inline (dalam teks)</option>
                        <option value="front" ${posMode === 'front' ? 'selected' : ''}>Floating - Di Depan</option>
                        <option value="behind" ${posMode === 'behind' ? 'selected' : ''}>Floating - Di Belakang</option>
                    </select>
                    <div class="grid grid-cols-2 gap-1 mb-1">
                        <div class="flex"><span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">W</span><input type="number" min="20" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${w}" onchange="updateMateraiSize('${eId}', 'width', this.value, ${i})"></div>
                        <div class="flex"><span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">H</span><input type="number" min="20" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${h}" onchange="updateMateraiSize('${eId}', 'height', this.value, ${i})"></div>
                    </div>
                    ${isFloating ? `
                    <div class="grid grid-cols-2 gap-1">
                        <div class="flex"><span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">X</span><input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${m.posX || 350}" onchange="updateMateraiPos('${eId}', 'x', this.value)"></div>
                        <div class="flex"><span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Y</span><input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${m.posY || 500}" onchange="updateMateraiPos('${eId}', 'y', this.value)"></div>
                    </div>
                    <small class="text-gray-500 text-xs">Drag materai di editor untuk ubah posisi</small>
                    ` : ''}
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
                const visual = (value === 'kosong') ? '(kosong)' : '(upload)';
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

        function deleteMaterai(idx) {
            if (!configMateraiList[idx]) return;
            if (!confirm('Hapus materai placeholder ini?')) return;
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

            list.innerHTML = logos.map(logo => {
                const src = configHeader.logos[logo.name] || '';
                const width = configHeader.logoSizes[logo.name] || logo.width || '80px';
                const mode = logo.mode || 'inline';
                const isFloating = mode !== 'inline';
                const eName = escapeHtml(logo.name);
                const eWidth = escapeHtml(width);

                return `
                <div class="mb-2 p-2 bg-gray-100 rounded">
                    <div class="flex justify-between items-center mb-1">
                        <strong class="text-xs">${eName}</strong>
                        ${src ? `<button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="removeLogo('${eName}')"><i class="bi bi-trash"></i></button>` : ''}
                    </div>
                    ${src ? `<img src="${src}" style="max-width:${eWidth};max-height:60px;display:block;margin-bottom:8px;">` : ''}
                    <div class="flex mb-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">W</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${eWidth}" onchange="updateLogoSize('${eName}', this.value)">
                    </div>
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateLogoMode('${eName}', this.value)">
                        <option value="inline" ${mode === 'inline' ? 'selected' : ''}>Inline</option>
                        <option value="front" ${mode === 'front' ? 'selected' : ''}>Floating Depan</option>
                        <option value="behind" ${mode === 'behind' ? 'selected' : ''}>Floating Belakang</option>
                    </select>
                    ${isFloating ? `
                    <div class="grid grid-cols-2 gap-1 mb-1">
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">X</span>
                            <input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${logo.posX}" onchange="updateLogoPos('${eName}', 'x', this.value)">
                        </div>
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Y</span>
                            <input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${logo.posY}" onchange="updateLogoPos('${eName}', 'y', this.value)">
                        </div>
                    </div>
                    <small class="text-gray-500 text-xs">Drag logo di editor untuk ubah posisi</small>
                    ` : ''}
                    <input type="file" class="w-full text-xs" accept="image/*" onchange="uploadLogo('${eName}', this)">
                </div>`;
            }).join('');
        }

        function uploadLogo(name, input) {
            const file = input.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) { alert('File harus gambar!'); return; }
            if (file.size > 500 * 1024) { alert('Max 500KB!'); return; }

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

        function deleteTtd(i) {
            if (confirm('Hapus TTD ini?')) {
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

            list.innerHTML = configTtd.map((ttd, i) => {
                const mode = ttd.mode || 'inline';
                const isFloating = mode !== 'inline';
                const eLabel = escapeHtml(ttd.label || 'TTD ' + (i + 1));
                const eNamaField = escapeHtml(ttd.nama_field || '');
                const eId = escapeHtml(ttd.id);
                const searchText = ((ttd.label || '') + ' ' + (ttd.nama_field || '') + ' ' + mode + ' ' + (ttd.ttdModes || '')).toLowerCase();

                const verifyActive = (ttd.qrData || '').includes('{verify_url}');
                return `
                <div class="mb-2 p-2 bg-gray-100 rounded panel-list-item" data-search-text="${escapeHtml(searchText)}" style="border-left: 3px solid #10b981;">
                    <div class="flex justify-between mb-1">
                        <strong class="text-xs text-green-600">${eLabel}</strong>
                        <button type="button" class="inline-flex items-center py-0 px-1 rounded border border-red-600 text-red-600 hover:bg-red-50" onclick="deleteTtd(${i})"><i class="bi bi-trash"></i></button>
                    </div>
                    <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" value="${escapeHtml(ttd.label || '')}" oninput="updateTtd(${i}, 'label', this.value)" placeholder="Label">
                    <input type="text" class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" value="${eNamaField}" oninput="updateTtd(${i}, 'nama_field', this.value)" placeholder="Field nama">
                    <select class="w-full rounded border-gray-300 shadow-sm text-xs mb-1 px-2 py-1" onchange="updateTtdMode('${eId}', this.value)">
                        <option value="inline" ${mode === 'inline' ? 'selected' : ''}>Inline</option>
                        <option value="front" ${mode === 'front' ? 'selected' : ''}>Floating Depan</option>
                        <option value="behind" ${mode === 'behind' ? 'selected' : ''}>Floating Belakang</option>
                    </select>
                    ${isFloating ? `
                    <div class="grid grid-cols-2 gap-1">
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">X</span>
                            <input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${ttd.posX || 50}" onchange="updateTtdPos('${eId}', 'x', this.value)">
                        </div>
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Y</span>
                            <input type="number" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${ttd.posY || 100}" onchange="updateTtdPos('${eId}', 'y', this.value)">
                        </div>
                    </div>
                    <small class="text-gray-500 text-xs">Drag TTD di editor untuk ubah posisi</small>
                    ` : ''}
                    <div class="flex mt-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">Mode</span>
                        <select class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" onchange="updateTtdAttr('${eId}', 'ttdModes', this.value, ${i})">
                            <option value="image" ${(ttd.ttdModes || 'image') === 'image' ? 'selected' : ''}>Gambar</option>
                            <option value="qr" ${(ttd.ttdModes || 'image') === 'qr' ? 'selected' : ''}>QR Code</option>
                            <option value="image,qr" ${(ttd.ttdModes || 'image') === 'image,qr' ? 'selected' : ''}>Gambar + QR</option>
                        </select>
                    </div>
                    ${(ttd.ttdModes || 'image').includes('qr') ? `
                    <div class="flex mt-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs">QR</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" id="ttdQrInput_${i}" value="${escapeHtml(ttd.qrData || '')}" onchange="updateTtdAttr('${eId}', 'qrData', this.value, ${i})" placeholder="Data QR, misal: {nama_dokter}">
                    </div>
                    <div class="flex justify-between items-center mt-1 flex-wrap gap-1">
                        <small class="text-gray-500" style="font-size:10.5px;">Pakai {nama_field} untuk data dinamis</small>
                        <button type="button" class="inline-flex items-center py-0 px-2 rounded ${verifyActive ? 'bg-green-600 text-white hover:bg-green-700' : 'border border-blue-600 text-blue-600 hover:bg-blue-50'}" style="font-size:11px;" onclick="setTtdVerifyUrl('${eId}', ${i})" title="QR untuk verifikasi keaslian dokumen — di-scan akan buka halaman verify">
                            <i class="bi bi-shield-check"></i> ${verifyActive ? 'Verifikasi Aktif' : 'Pakai Verifikasi Dokumen'}
                        </button>
                    </div>
                    ` : ''}
                    <div class="flex mt-1">
                        <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50 text-xs" title="Nama yang tampil kalau field belum diisi">Default</span>
                        <input type="text" class="flex-1 rounded-r border border-gray-300 text-xs px-2 py-1" value="${escapeHtml(ttd.defaultNama || '')}" onchange="updateTtdAttr('${eId}', 'defaultNama', this.value, ${i})" placeholder="Default nama, contoh: dr. Hilmi...">
                    </div>
                    <!-- RBAC per-TTD: siapa yang boleh sign TTD ini -->
                    <div class="mt-1 pt-1 border-t border-gray-300">
                        <small class="text-gray-500 block mb-1" style="font-size:10px;"><i class="bi bi-lock"></i> <strong>Akses TTD</strong> (kosong = semua)</small>
                        <div class="flex">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50" style="font-size:10px;">Roles</span>
                            <input type="text" class="flex-1 rounded-r border border-gray-300 px-2 py-1" value="${escapeHtml(ttd.allowedRoles || '')}" onchange="updateTtdAttr('${eId}', 'allowedRoles', this.value, ${i})" placeholder="dokter_dpjp,perawat" style="font-size:11px;">
                        </div>
                        <div class="flex mt-1">
                            <span class="inline-flex items-center px-2 py-1 rounded-l border border-r-0 border-gray-300 bg-gray-50" style="font-size:10px;">Users</span>
                            <input type="text" class="flex-1 rounded-r border border-gray-300 px-2 py-1" value="${escapeHtml(ttd.allowedUsers || '')}" onchange="updateTtdAttr('${eId}', 'allowedUsers', this.value, ${i})" placeholder="42,99 (id_pegawai)" style="font-size:11px;">
                        </div>
                    </div>
                </div>`;
            }).join('');

            // Re-apply filter if search has value
            const ts = document.getElementById('ttdSearch');
            if (ts && ts.value) filterPanel('ttdList', ts.value);
        }

        function confirmDelete(id, name) {
            if (confirm('Hapus template "' + name + '"?')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
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
<?php if (!$__ezdoc_isFragment): ?>
</body>
</html>
<?php endif; ?>
