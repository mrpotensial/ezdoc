<?php
/**
 * Ezdoc starter layout — minimal HTML5 shell.
 *
 * Consumer boleh publish + edit file ini via:
 *   php cli/publish.php views /app/resources/views/vendor/ezdoc
 * Lihat docs/UI-CUSTOMIZATION.md untuk detail.
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
<html lang="<?= htmlspecialchars((string) $config->get('brand.lang', 'en'), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($theme->assetUrl('css/ezdoc.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ($theme->getCustomCssPaths() as $cssPath): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>

<style>
    :root {
        --ezdoc-primary: <?= htmlspecialchars($theme->getPrimaryColor(), ENT_QUOTES, 'UTF-8') ?>;
        --ezdoc-secondary: <?= htmlspecialchars($theme->getSecondaryColor(), ENT_QUOTES, 'UTF-8') ?>;
    }
</style>

<?= \Ezdoc\UI\Slot::render('layout:head-extra') ?>
</head>
<body class="ezdoc-body">
    <header class="ezdoc-header">
        <div class="container d-flex align-items-center py-2">
            <?php if ($logo = $theme->getLogoUrl()): ?>
                <img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="" class="ezdoc-logo me-2">
            <?php endif; ?>
            <strong><?= htmlspecialchars((string) $config->get('brand.app_name', 'Ezdoc'), ENT_QUOTES, 'UTF-8') ?></strong>
            <?= \Ezdoc\UI\Slot::render('layout:header-extra') ?>
        </div>
    </header>

    <main class="container my-4">
        <?= $content ?>
    </main>

    <footer class="ezdoc-footer container text-muted small py-3">
        <?= \Ezdoc\UI\Slot::render('layout:footer-extra') ?>
    </footer>

    <script src="<?= htmlspecialchars($theme->assetUrl('js/ezdoc.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php foreach ($theme->getCustomJsPaths() as $jsPath): ?>
    <script src="<?= htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endforeach; ?>
</body>
</html>
