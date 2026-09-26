<?php

declare(strict_types=1);

/**
 * Buka contoh di halaman pembuatan dokumen, di proses terpisah, lalu cetak
 * HTML-nya.
 *
 *   php jalankan_contoh_generate.php [template_id] [ya|tidak]
 *
 * Argumen kedua: apakah pembukanya punya peran pengelola template.
 *
 * Tanpa sambungan database sama sekali — itu justru yang diuji: mode contoh
 * tidak boleh menyentuh database, jadi ketiadaannya tidak boleh terasa.
 */

$templateId = (string) ($argv[1] ?? 'contoh');
$boleh = (($argv[2] ?? 'ya') === 'ya');
$GLOBALS['ezdocBoleh'] = $boleh;

$akar = dirname(__DIR__, 2);

define('EZDOC_DEBUG', true);
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

// Sambungan yang ada TAPI tidak dipakai: mode contoh tidak boleh menyentuh
// database, jadi SQLite kosong di memori sudah cukup. Kalau ada jalur yang
// diam-diam bertanya ke database, ia akan gagal di sini — dan itu memang
// yang ingin ketahuan.
\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    new \PDO('sqlite::memory:'),
    new class implements \Ezdoc\Auth\RoleProvider {
        public function hasRole($roles): bool { return (bool) ($GLOBALS['ezdocBoleh'] ?? true); }
        public function currentUserId(): int { return 1; }
        public function currentUserRoles(): array { return ['pembuat_template']; }
    }
));

$config = \Ezdoc\UI\Config::fromArray([
    'brand' => ['app_name' => 'ezdoc'],
    'app' => ['base_path' => 'index.php', 'query_key' => 'ezdoc_page', 'locale' => 'id'],
    'document' => ['locale' => 'id'],
]);
$theme = new \Ezdoc\UI\Theme($config);
$ctx = \Ezdoc\Context::default();

$_GET['template_id'] = $templateId;
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include $akar . '/views/document/generate.php';
echo ob_get_clean();
