<?php
/**
 * Ezdoc starter — template picker view untuk document generation.
 *
 * Extracted from `generate.php` di v0.9.11 (view separation milestone).
 * Sebelumnya generate.php mixed template PICKER + document generate + PDF
 * export di 1 file (4666 lines). Split per industry-standard MVC one-view-
 * per-action convention: Laravel `index.blade.php`, Filament `ListResource`,
 * Symfony `templates/{controller}/index.html.twig`.
 *
 * Rendered saat user visit `?ezdoc_page=generate` tanpa `template_id` param
 * — user pilih template dari list untuk mulai generate document.
 *
 * ## Expected vars (inherited dari parent scope via `require`)
 *
 * @var array   $templates             List of available templates
 * @var bool    $__ezdoc_isFragment    Fragment mode (nested inside layout)
 * @var string  $pickerPageTitle       Standalone page <title>
 * @var string  $pickerHeader          H1 header text
 * @var string  $pickerEmptyMsg        Empty state message
 * @var string  $pickerCreateLabel     Create template link label
 * @var string  $pickerManageLabel     Manage templates button label
 * @var string  $urlSelf               URL untuk self-append template_id
 * @var string  $urlDesigner           URL to template designer (empty = hidden)
 * @var string  $urlDesignerCreate     URL to create template action
 *
 * ## Slots
 * - `generate:list-header-extra` — consumer UI hook top of picker header
 *
 * ## Backward-compat
 * File called via `require` dari generate.php's picker conditional block.
 * Consumer dispatchers yang route ke generate.php tanpa template_id tetap work.
 */
?>
    <?php if (!$__ezdoc_isFragment): ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title><?= h($pickerPageTitle) ?></title>
        <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
        <!-- Alpine.js NOT loaded here — template picker uses zero interactivity, keep bundle lean. -->
    </head>
    <body class="bg-gray-50 min-h-screen">
    <?php endif; ?>
        <section class="<?= $__ezdoc_isFragment ? '' : 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8' ?>">
            <!-- Header — matches list.php pattern -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900"><?= h($pickerHeader) ?></h1>
                <div class="flex items-center gap-2">
                    <?= \Ezdoc\UI\Slot::render('generate:list-header-extra', ['templates' => $templates]) ?>
                    <?php if ($urlDesigner !== ''): ?>
                    <a href="<?= h($urlDesigner) ?>" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"><?= h($pickerManageLabel) ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($templates)): ?>
            <!-- Empty state — matches list.php dashed border pattern -->
            <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p class="mt-3 text-sm text-gray-500">
                    <?= h($pickerEmptyMsg) ?>
                    <?php if ($urlDesignerCreate !== ''): ?>
                    <a href="<?= h($urlDesignerCreate) ?>" class="hover:underline" style="color: var(--ezdoc-primary);"><?= h($pickerCreateLabel) ?></a>
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <!-- Template list — matches list.php table pattern -->
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <ul class="divide-y divide-gray-100">
                    <?php
                    // Preserve routing prefix — kalau $urlSelf udah punya `?` (mis. `?ezdoc_page=generate`),
                    // append template_id dengan `&`, else `?`. Tanpa ini, `?template_id=X` bakal wipe
                    // `ezdoc_page=` param dan App fallback ke default page.
                    $__pickerJoiner = strpos($urlSelf, '?') !== false ? '&' : '?';
                    foreach ($templates as $t):
                    ?>
                    <li>
                        <a href="<?= h($urlSelf . $__pickerJoiner . 'template_id=' . $t['id']) ?>"
                           class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 group">
                            <span class="text-sm font-medium text-gray-900 group-hover:text-gray-950"><?= h($t['nama_template']) ?></span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 group-hover:text-gray-600" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </section>
    <?php if (!$__ezdoc_isFragment): ?>
    </body>
    </html>
    <?php endif; ?>
