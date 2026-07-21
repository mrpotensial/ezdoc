<?php
/**
 * Ezdoc starter — template list view.
 *
 * Extracted from `designer.php` di v0.9.11 (view separation milestone).
 * Sebelumnya designer.php mixed template LIST + template EDIT/CREATE di
 * 1 file (5534 lines). Split per industry-standard MVC convention:
 * Laravel `resources/views/documents/index.blade.php`, Filament
 * `ListResource`, Symfony `templates/{controller}/index.html.twig`.
 *
 * ## Expected vars (inherited dari parent scope via `require`)
 *
 * @var array                    $templates          List of template rows
 * @var string|null              $message            Optional flash message
 * @var string                   $messageType        'success' | 'error'
 * @var string                   $designerListTitle  Page title
 * @var string                   $urlCreate          URL untuk create new template
 * @var string                   $urlPrint           URL pattern (dengan {id}) untuk print
 * @var string                   $urlEditPattern     URL pattern untuk edit
 * @var string                   $urlDelete          URL untuk delete form target
 * @var string                   $urlToggle          AJAX endpoint untuk toggle lock
 * @var string                   $urlCopy            AJAX endpoint untuk copy template
 *
 * Shared JS (confirmDelete, ezdocAlert, ezdocConfirm, t()) provided by
 * designer.php's shared `<script>` block loaded in both list + editor modes.
 *
 * ## Slots
 * - `template_list:header-extra` — consumer UI hook top of list (v1.0 canonical)
 * - `template_list:row-actions-extra` — per-row extra action buttons (v1.0 canonical)
 *
 * Backward-compat aliases (registered di `App::registerLegacySlotAliases()`):
 * - `designer:list-header-extra`      → `template_list:header-extra`
 * - `designer:list-row-actions-extra` → `template_list:row-actions-extra`
 * Existing consumer registrations against old names tetap works.
 *
 * ## Backward-compat
 * File called via `require` dari designer.php's list conditional. Consumer
 * dispatchers yang route ke designer.php dgn `$action='list'` tetap work.
 * Future v1.0 mungkin add direct routing ke template_list.php sebagai view
 * resolver target.
 */
?>
    <section>
        <?= \Ezdoc\UI\Slot::render('template_list:header-extra', ['templates' => $templates]) ?>
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
                <?= h(t('toolbar.create_template', [], 'Create Template')) ?>
            </a>
        </div>

        <?php if (empty($templates)): ?>
        <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-3 text-sm text-gray-500">
                <?= h(t('list.no_templates_yet', [], 'No templates yet.')) ?> <a href="<?= h($urlCreate) ?>" class="hover:underline" style="color: var(--ezdoc-primary);"><?= h(t('list.create_new_template', [], 'Create new template')) ?></a>
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
                <label class="block text-xs font-medium text-gray-700 mb-1"><?= h(t('list.search_label', [], 'Search')) ?></label>
                <input type="search" id="tplSearchInput"
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm"
                       placeholder="<?= h(t('placeholder.search_template_name', [], 'Search template name...')) ?>" oninput="filterTemplateList()">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1"><?= h(t('list.category_label', [], 'Category')) ?></label>
                <select id="catFilterSelect" onchange="setCategoryFilter(this.value)"
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
                    <option value="__all__"><?= h(t('list.all_categories', ['count' => $catCounts['__all__']], 'All categories ({count})')) ?></option>
                    <?php if ($catCounts['__none__'] > 0): ?>
                    <option value="__none__"><?= h(t('list.no_category', ['count' => $catCounts['__none__']], '(No category) ({count})')) ?></option>
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
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><?= h(t('list.col_template_name', [], 'Template Name')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><?= h(t('list.category_label', [], 'Category')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><?= h(t('list.col_status', [], 'Status')) ?></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><?= h(t('list.col_update', [], 'Update')) ?></th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500"><?= h(t('list.col_actions', [], 'Actions')) ?></th>
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
                                <?= $locked ? h(t('list.badge_locked', [], 'Locked')) : h(t('list.badge_open', [], 'Open')) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= date('d M Y H:i', strtotime($t['updated_at'])) ?></td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center gap-1">
                                <a href="<?= h(str_replace('{id}', (string)$t['id'], $urlPrint . (strpos($urlPrint,'?') !== false ? '&' : '?') . 'template_id=' . $t['id'])) ?>" class="inline-flex items-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900" title="<?= h(t('title.print', [], 'Print')) ?>"><i class="bi bi-printer"></i></a>
                                <a href="<?= h(str_replace('{id}', (string)$t['id'], $urlEditPattern)) ?>" class="inline-flex items-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900" title="<?= h(t('actions.edit', [], 'Edit')) ?>"><i class="bi bi-pencil"></i></a>
                                <button type="button" id="lockBtn<?= $t['id'] ?>" class="inline-flex items-center rounded-md border p-1.5 <?= $locked ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-gray-300 text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>" onclick="toggleLock(<?= $t['id'] ?>, <?= $locked ? 1 : 0 ?>)" title="<?= $locked ? h(t('title.unlock', [], 'Unlock')) : h(t('title.lock', [], 'Lock')) ?>">
                                    <i class="bi <?= $locked ? 'bi-lock-fill' : 'bi-unlock' ?>"></i>
                                </button>
                                <button type="button" class="inline-flex items-center rounded-md border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50 hover:text-gray-900" onclick="copyTemplate(<?= $t['id'] ?>)" title="<?= h(t('title.duplicate', [], 'Duplicate')) ?>"><i class="bi bi-files"></i></button>
                                <button type="button" class="inline-flex items-center rounded-md border border-red-300 bg-white p-1.5 text-red-600 hover:bg-red-50" onclick="confirmDelete(<?= $t['id'] ?>, '<?= h($t['nama_template']) ?>')" title="<?= h(t('actions.delete', [], 'Delete')) ?>"><i class="bi bi-trash"></i></button>
                                <?= \Ezdoc\UI\Slot::render('template_list:row-actions-extra', ['template' => $t]) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="tplEmptyMsg" class="mt-3 text-center py-4 text-sm text-gray-500" style="display:none;"><i class="bi bi-funnel mr-1"></i><?= h(t('list.no_filter_match', [], 'No templates match the filter.')) ?></div>
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
                            ezdocAlert(t('alert.failed', {message: data.message || 'error'}, 'Failed: {message}'), { title: 'Error', variant: 'error' });
                        }
                    }

                    async function copyTemplate(id) {
                        if (!(await ezdocConfirm(t('confirm.duplicate_template', {}, 'Duplicate this template?'), { title: 'Duplicate Template', variant: 'info', confirmText: 'Duplicate' }))) return;
                        const fd = new FormData();
                        fd.append('ajax', '1');
                        fd.append('action', 'copy_template');
                        fd.append('template_id', id);
                        // spec: ezdoc-spec/openapi.yaml#/paths/~1template~1copy
                        const resp = await fetch(EZDOC_LIST_URLS.copy, { method: 'POST', body: fd });
                        const data = await resp.json();
                        if (data.success) {
                            ezdocAlert(t('alert.created', {name: data.nama}, 'Created: {name}'), { title: 'Created', variant: 'success' });
                            location.reload();
                        } else {
                            ezdocAlert(t('alert.failed', {message: data.message || 'error'}, 'Failed: {message}'), { title: 'Error', variant: 'error' });
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
