<?php
/**
 * Ezdoc starter — document list view (Tailwind CSS).
 *
 * STARTER TEMPLATE — publish + edit untuk customization:
 *   php cli/publish.php views /app/resources/views/vendor/ezdoc
 * See docs/UI-CUSTOMIZATION.md.
 *
 * Expected vars:
 *   @var \Ezdoc\UI\Config                    $config
 *   @var \Ezdoc\UI\Theme                     $theme
 *   @var \Ezdoc\Document\Document[]          $documents
 *   @var \Ezdoc\Template\Template[]          $templates List all templates untuk filter dropdown
 *   @var array<string,mixed>                 $filters   Current filter state (q, status, template_id)
 *   @var string                              $baseUrl   Base for filter form action (search/status)
 *
 * URL patterns — configure di Config supaya match consumer routing.
 * Use `{uuid}` placeholder — akan di-replace saat rendering per row.
 *   Config keys:
 *     urls.view_pattern  — default: 'document/view?uuid={uuid}'
 *     urls.print_pattern — default: 'document/print?uuid={uuid}'
 *     urls.new           — default: 'document/new'
 */

$pageTitle = (string) $config->get('pages.list.title', 'Documents');
$emptyMsg  = (string) $config->get('pages.list.empty_message', 'No documents yet. Click "Create Document" to get started.');
$createBtn = (string) $config->get('pages.list.create_button', 'Create Document');
$searchLabel = (string) $config->get('pages.list.search_label', 'Search');
$searchPlaceholder = (string) $config->get('pages.list.search_placeholder', 'Search title / reference / subject...');
$templateLabel = (string) $config->get('pages.list.template_label', 'Template');
$allTemplatesLabel = (string) $config->get('pages.list.all_templates', 'All templates');
$statusLabel = (string) $config->get('pages.list.status_label', 'Status');
$allStatusesLabel = (string) $config->get('pages.list.all_statuses', 'All statuses');
$filterButton = (string) $config->get('pages.list.filter_button', 'Filter');
$clearFilterTitle = (string) $config->get('pages.list.clear_filter_title', 'Clear filter');
$filterLabel = (string) $config->get('pages.list.filter_label', 'Filter:');
$documentsSuffix = (string) $config->get('pages.list.documents_suffix', 'document(s)');
$printButton = (string) $config->get('pages.list.print_button', 'Print');

$urlView   = (string) $config->get('urls.view_pattern', 'document/view?uuid={uuid}');
$urlPrint  = (string) $config->get('urls.print_pattern', 'document/print?uuid={uuid}');
$urlNew    = (string) $config->get('urls.new', 'document/new');

// Small helper to interpolate {uuid} placeholder — allows consumer to define
// URL patterns like '?page=my_app&action=view&uuid={uuid}' OR '/docs/{uuid}'
$buildUrl = function (string $pattern, array $vars = []) use ($baseUrl): string {
    $out = $pattern;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . $k . '}', rawurlencode((string) $v), $out);
    }
    // Kalau pattern relative (tidak start dengan / atau http atau ?), prepend baseUrl
    if ($out !== '' && $out[0] !== '/' && $out[0] !== '?' && strpos($out, 'http') !== 0) {
        $out = rtrim($baseUrl, '/') . '/' . $out;
    }
    return $out;
};

$statusStyles = [
    'draft'  => 'bg-gray-100 text-gray-700 ring-gray-300',
    'issued' => 'bg-blue-100 text-blue-700 ring-blue-300',
    'signed' => 'bg-emerald-100 text-emerald-700 ring-emerald-300',
    'void'   => 'bg-red-100 text-red-700 ring-red-300',
];
?>
<section>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
            <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <a href="<?= htmlspecialchars($buildUrl($urlNew), ENT_QUOTES, 'UTF-8') ?>"
           class="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
           style="background-color: var(--ezdoc-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                <path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
            </svg>
            <?= htmlspecialchars($createBtn, ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>

    <?php
    // Preserve App orchestrator routing prefix (ezdoc_page=list) di form action
    // supaya GET submit tidak fallback ke default_page. Semua param non-filter
    // yg exist di URL sekarang di-forward via hidden inputs.
    $qk = 'ezdoc_page';
    $preserveKeys = [$qk, 'ezdoc_asset'];
    $preserveHidden = [];
    foreach ($preserveKeys as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k])) {
            $preserveHidden[$k] = $_GET[$k];
        }
    }
    $templates    = $templates ?? [];
    $selectedTid  = (int) ($filters['template_id'] ?? 0);
    ?>
    <form method="get" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-5 items-end">
        <?php foreach ($preserveHidden as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>">
        <?php endforeach; ?>

        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars($searchLabel, ENT_QUOTES, 'UTF-8') ?></label>
            <input type="search" name="q"
                   class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm"
                   placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8') ?>"
                   value="<?= htmlspecialchars((string)($filters['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars($templateLabel, ENT_QUOTES, 'UTF-8') ?></label>
            <select name="template_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
                <option value=""><?= htmlspecialchars($allTemplatesLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($templates as $tpl):
                    $tid = $tpl->getId();
                    $tname = $tpl->getName();
                    $tcat = $tpl->getCategory();
                    $labelDisplay = $tname . ($tcat !== '' ? ' — ' . $tcat : '');
                ?>
                    <option value="<?= $tid ?>" <?= ($selectedTid === $tid) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($labelDisplay, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></label>
            <select name="status" class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
                <option value=""><?= htmlspecialchars($allStatusesLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach (['draft','published','issued','signed','locked','archived','void'] as $st): ?>
                    <option value="<?= $st ?>"
                        <?= (isset($filters['status']) && $filters['status'] === $st) ? 'selected' : '' ?>>
                        <?= ucfirst($st) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?= \Ezdoc\UI\Slot::render('document-list:filters-extra') ?>

        <div class="flex gap-2">
            <button type="submit"
                    class="flex-1 rounded-md border border-transparent px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                    style="background-color: var(--ezdoc-primary);">
                <?= htmlspecialchars($filterButton, ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php
            // "Clear" tombol — hanya tampil kalau ada filter aktif
            $hasFilter = ($filters['q'] ?? '') !== '' || ($filters['status'] ?? '') !== '' || (int)($filters['template_id'] ?? 0) > 0;
            if ($hasFilter):
                // Build clean URL (only preserve routing prefix)
                $clearParams = [];
                foreach ($preserveHidden as $k => $v) $clearParams[$k] = $v;
                $clearHref = '?' . http_build_query($clearParams);
            ?>
                <a href="<?= htmlspecialchars($clearHref, ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400"
                   title="<?= htmlspecialchars($clearFilterTitle, ENT_QUOTES, 'UTF-8') ?>">
                    &times;
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($selectedTid > 0):
        // Show breadcrumb pill saat filter active per-template — Airtable/Linear pattern
        $activeTpl = null;
        foreach ($templates as $tpl) if ($tpl->getId() === $selectedTid) { $activeTpl = $tpl; break; }
        if ($activeTpl):
    ?>
        <div class="mb-3 flex items-center gap-2 text-xs">
            <span class="text-gray-500"><?= htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium ring-1 ring-inset"
                  style="background:color-mix(in srgb, var(--ezdoc-primary) 10%, white); color: var(--ezdoc-primary); border-color: color-mix(in srgb, var(--ezdoc-primary) 25%, transparent);">
                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm4 3a1 1 0 100 2h4a1 1 0 100-2H8zm-1 4a1 1 0 011-1h4a1 1 0 110 2H8a1 1 0 01-1-1z"/></svg>
                <?= htmlspecialchars($activeTpl->getName(), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="text-gray-500"><?= count($documents) ?> <?= htmlspecialchars($documentsSuffix, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif;
    endif;
    ?>

    <?php if (empty($documents)): ?>
        <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-3 text-sm text-gray-500"><?= htmlspecialchars($emptyMsg, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!empty($debugMsg)): ?>
                <p class="mt-4 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 inline-block font-mono">
                    <strong>Diagnostic:</strong> <?= htmlspecialchars($debugMsg, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Title</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Subject</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Created At</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($documents as $doc):
                    $status = $doc->getStatus();
                    $badgeClass = isset($statusStyles[$status]) ? $statusStyles[$status] : 'bg-gray-100 text-gray-700 ring-gray-300';
                ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a class="font-medium hover:underline"
                               style="color: var(--ezdoc-primary);"
                               href="<?= htmlspecialchars($buildUrl($urlView, ['uuid' => $doc->getUuid()]), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string) ($doc->getTitle() ?? '(untitled)'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php if ($ref = $doc->getReferenceNumber()): ?>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($sid = $doc->getSubjectId()): ?>
                                <span class="text-xs text-gray-500"><?= htmlspecialchars((string) $doc->getSubjectType(), ENT_QUOTES, 'UTF-8') ?>:</span>
                                <span class="font-mono text-xs"><?= htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset <?= $badgeClass ?>">
                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            <?= htmlspecialchars((string) ($doc->getCreatedAt() ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                               href="<?= htmlspecialchars($buildUrl($urlPrint, ['uuid' => $doc->getUuid()]), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($printButton, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?= \Ezdoc\UI\Slot::render('document-list:actions-extra', ['document' => $doc]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
