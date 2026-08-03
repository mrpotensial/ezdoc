<?php
/**
 * Ezdoc starter layout — minimal HTML5 shell dengan Tailwind CSS (industry standard).
 *
 * Consumer boleh publish + edit file ini via:
 *   php cli/publish.php views /app/resources/views/vendor/ezdoc
 * Lihat docs/UI-CUSTOMIZATION.md untuk detail.
 *
 * CSS strategy:
 *   1. Tailwind CSS via Play CDN (zero build step) — production consumer boleh
 *      swap ke compiled Tailwind untuk tree-shaking + smaller bundle
 *   2. CSS variables (--ezdoc-*) di-inject dari Theme untuk brand colors
 *   3. ezdoc.css defines component tokens + Tailwind @apply patterns
 *   4. Custom CSS paths dari config appended terakhir untuk override
 *
 * Expected vars in scope:
 *   @var \Ezdoc\UI\Theme  $theme    Theming/branding accessor
 *   @var \Ezdoc\Config    $config   App-level config bag
 *   @var string           $content  Rendered inner page HTML
 *   @var string           $title    Optional page <title> override
 */

$pageTitle = isset($title) && $title !== ''
    ? (string) $title
    : (string) $config->get('brand.app_name', 'Ezdoc');
?>
<!doctype html>
<html lang="<?= htmlspecialchars((string) $config->get('brand.lang', 'en'), ENT_QUOTES, 'UTF-8') ?>" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

<!-- Tailwind CSS Play CDN — production consumer boleh swap ke compiled build -->
<script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<script>
    // Tailwind config — sync CSS vars ke theme color scale
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'ezdoc-primary': 'var(--ezdoc-primary)',
                    'ezdoc-secondary': 'var(--ezdoc-secondary)',
                    'ezdoc-surface': 'var(--ezdoc-surface)',
                    'ezdoc-muted': 'var(--ezdoc-muted)',
                },
                borderRadius: {
                    'ezdoc': 'var(--ezdoc-radius)',
                },
                fontFamily: {
                    'ezdoc': 'var(--ezdoc-font)'.split(','),
                },
            }
        }
    };
</script>

<!-- Alpine.js — declarative interactivity (modals, dropdowns, tabs, toggles).
     Consumer views yang butuh state management pakai x-data / x-show / @click.
     Standard stack industri: Tailwind (styling) + Alpine (behavior). -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.13.5/dist/cdn.min.js"></script>

<!-- Bootstrap Icons — dipakai designer + generate icons (bi bi-*) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

<!-- Ezdoc component tokens + @apply patterns -->
<link rel="stylesheet" href="<?= htmlspecialchars($theme->assetUrl('css/ezdoc.css'), ENT_QUOTES, 'UTF-8') ?>">

<!-- Consumer custom CSS (loaded terakhir untuk override) -->
<?php foreach ($theme->getCustomCssPaths() as $cssPath): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>

<style>
    :root {
        --ezdoc-primary: <?= htmlspecialchars($theme->getPrimaryColor(), ENT_QUOTES, 'UTF-8') ?>;
        --ezdoc-secondary: <?= htmlspecialchars($theme->getSecondaryColor(), ENT_QUOTES, 'UTF-8') ?>;
        --ezdoc-surface: <?= htmlspecialchars((string) $config->get('brand.surface_color', '#ffffff'), ENT_QUOTES, 'UTF-8') ?>;
        --ezdoc-muted: <?= htmlspecialchars((string) $config->get('brand.muted_color', '#6b7280'), ENT_QUOTES, 'UTF-8') ?>;
        --ezdoc-radius: <?= htmlspecialchars((string) $config->get('brand.radius', '0.5rem'), ENT_QUOTES, 'UTF-8') ?>;
        --ezdoc-font: <?= htmlspecialchars((string) $config->get('brand.font', "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"), ENT_QUOTES, 'UTF-8') ?>;
    }
</style>

<?= \Ezdoc\UI\Slot::render('layout:head-extra') ?>
</head>
<body class="min-h-full bg-gray-50 text-gray-900 antialiased" style="font-family: var(--ezdoc-font);">
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto flex items-stretch px-4 sm:px-6 lg:px-8">
            <?php
            // Brand rendering — Vercel/Linear/Filament-inspired: logo mark + product name
            // + optional tagline pill. Auto-split "Foo (Bar)" or "Foo · Bar" untuk
            // backward-compat kalau consumer lama pack semua di brand.app_name.
            $appName = (string) $config->get('brand.app_name', 'Ezdoc');
            $tagline = (string) $config->get('brand.tagline', '');
            $badge   = (string) $config->get('brand.badge', '');
            if ($tagline === '') {
                if (preg_match('/^(.+?)\s*\((.+)\)\s*$/', $appName, $m)) {
                    $appName = trim($m[1]);
                    $tagline = trim($m[2]);
                } elseif (preg_match('/^(.+?)\s+[·—–]\s+(.+)$/u', $appName, $m)) {
                    $appName = trim($m[1]);
                    $tagline = trim($m[2]);
                }
            }
            // Fallback logo mark — colored square dgn initial huruf pertama (Vercel-style).
            $logoInitial = mb_strtoupper(mb_substr($appName, 0, 1));
            ?>
            <a href="<?= htmlspecialchars((string) $config->get('app.base_path', ''), ENT_QUOTES, 'UTF-8') ?>"
               class="flex items-center gap-2.5 py-4 pr-6 group focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-300 rounded-md"
               style="focus-ring-color: var(--ezdoc-primary);">
                <?php if ($logo = $theme->getLogoUrl()): ?>
                    <img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-8 w-8 rounded-lg object-cover shadow-sm ring-1 ring-gray-200">
                <?php else: ?>
                    <span class="inline-flex items-center justify-center h-8 w-8 rounded-lg text-white text-[13px] font-bold shadow-sm ring-1 ring-black/5 select-none"
                          style="background: linear-gradient(135deg, var(--ezdoc-primary) 0%, color-mix(in srgb, var(--ezdoc-primary) 60%, black 25%) 100%);"
                          aria-hidden="true">
                        <?= htmlspecialchars($logoInitial, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <div class="flex flex-col leading-tight min-w-0">
                    <span class="text-[15px] font-semibold tracking-tight text-gray-900 group-hover:text-gray-700 transition-colors truncate">
                        <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ($tagline !== ''): ?>
                        <span class="text-[10px] text-gray-500 font-medium tracking-wide mt-px truncate max-w-[28ch]">
                            <?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($badge !== ''): ?>
                    <span class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider ring-1 ring-inset"
                          style="background:color-mix(in srgb, var(--ezdoc-primary) 12%, white); color: var(--ezdoc-primary); border-color: color-mix(in srgb, var(--ezdoc-primary) 30%, transparent);">
                        <?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </a>

            <?php
            // Primary nav — industri pattern minimalist (Notion / Linear / Adminer):
            // clean sans-serif labels, subtle underline for active state, no emoji.
            // Consumer bisa hide via layout.nav.enabled=false, custom items via
            // layout.nav.items, atau replace via slot 'layout:nav-replace'.
            $navEnabled = (bool) $config->get('layout.nav.enabled', true);
            if ($navEnabled):
                $qk       = (string) $config->get('app.query_key', 'ezdoc_page');
                $base     = (string) $config->get('app.base_path', '');
                $join     = (strpos($base, '?') === false) ? '?' : '&';
                $navItems = $config->get('layout.nav.items');
                if (!is_array($navItems)) {
                    $navItems = [
                        ['label' => 'Documents', 'page' => 'list'],
                        ['label' => 'Templates', 'page' => 'designer'],
                        ['label' => 'Create',    'page' => 'generate'],
                    ];
                }
                $currentPage = '';
                if (isset($_GET[$qk]) && is_string($_GET[$qk])) {
                    $currentPage = $_GET[$qk];
                }
                $navReplace = \Ezdoc\UI\Slot::render('layout:nav-replace');
                if ($navReplace !== ''): ?>
                    <nav class="ml-8 flex items-center gap-6"><?= $navReplace ?></nav>
                <?php else: ?>
                    <nav class="ml-8 hidden md:flex items-center gap-1 self-stretch" aria-label="Primary">
                        <?php foreach ($navItems as $item):
                            $itemPage  = (string) ($item['page'] ?? '');
                            $itemLabel = (string) ($item['label'] ?? $itemPage);
                            $itemHref  = isset($item['url'])
                                ? (string) $item['url']
                                : ($base . $join . $qk . '=' . rawurlencode($itemPage));
                            $active    = ($currentPage === $itemPage);
                            $cls       = $active
                                ? 'text-gray-900 border-b-2'
                                : 'text-gray-500 hover:text-gray-900 border-b-2 border-transparent';
                            $activeStyle = $active ? 'border-color: var(--ezdoc-primary);' : '';
                        ?>
                            <a href="<?= htmlspecialchars($itemHref, ENT_QUOTES, 'UTF-8') ?>"
                               class="inline-flex items-center px-3 text-sm font-medium transition-colors focus:outline-none focus:ring-1 focus:ring-gray-400 <?= $cls ?>"
                               style="<?= $activeStyle ?>"
                               <?= $active ? 'aria-current="page"' : '' ?>>
                                <?= htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endforeach; ?>
                        <?= \Ezdoc\UI\Slot::render('layout:nav-extra') ?>
                    </nav>
                <?php endif;
            endif;
            ?>

            <div class="ml-auto flex items-center gap-3">
                <?= \Ezdoc\UI\Slot::render('layout:header-extra') ?>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?= $content ?>
    </main>

    <footer class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 text-sm text-gray-500">
        <?= \Ezdoc\UI\Slot::render('layout:footer-extra') ?>
    </footer>

    <script src="<?= htmlspecialchars($theme->assetUrl('js/ezdoc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php foreach ($theme->getCustomJsPaths() as $jsPath): ?>
    <script src="<?= htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endforeach; ?>

    <?php include __DIR__ . '/_partials/dialog_helper.php'; ?>

</body>
</html>
