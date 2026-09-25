<?php

declare(strict_types=1);

/**
 * Render halaman contoh di proses terpisah lalu cetak HTML-nya.
 *
 * Proses sendiri karena halaman ini memuat perakit dokumen beserta fungsi
 * globalnya, dan karena gerbang aksesnya diuji dengan jawaban yang berbeda-beda
 * — keduanya tidak boleh menular ke berkas uji lain.
 *
 *   php jalankan_contoh.php [ya|tidak]
 *
 * Argumen: apakah yang membuka halaman punya peran pengelola template.
 */

$boleh = (($argv[1] ?? 'ya') === 'ya');

$akar = dirname(__DIR__, 2);

// Gerbang halaman diuji lewat jalur yang sesungguhnya: daftar peran pengelola
// template + penyedia peran. Bukan dengan mendeklarasikan ulang fungsinya.
define('EZDOC_TEMPLATE_MANAGER_ROLES', ['pembuat_template']);

require_once $akar . '/autoload.php';
require_once $akar . '/lib/role_provider.php';
require_once $akar . '/lib/authorization.php';

ezdoc_set_role_provider([
    'has_role' => function ($roles) use ($boleh): bool {
        return $boleh;
    },
    'current_user_id' => function (): int {
        return 1;
    },
    'current_user_roles' => function () use ($boleh): array {
        return $boleh ? ['pembuat_template'] : ['pembaca'];
    },
]);

require_once $akar . '/lib/view_helpers.php';
require_once $akar . '/lib/doc_template_helpers.php';
require_once $akar . '/lib/pdf_compose.php';

$config = \Ezdoc\UI\Config::fromArray([
    'brand' => ['app_name' => 'ezdoc'],
    'app' => ['base_path' => 'index.php', 'query_key' => 'ezdoc_page'],
]);
$theme = new \Ezdoc\UI\Theme($config);

ob_start();
include $akar . '/views/contoh.php';
echo ob_get_clean();
