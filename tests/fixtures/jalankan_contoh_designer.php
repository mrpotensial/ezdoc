<?php

declare(strict_types=1);

/**
 * Buka contoh di designer, di proses terpisah, lalu cetak HTML-nya.
 *
 *   php jalankan_contoh_designer.php [id]
 *
 * Tanpa database: mode contoh tidak boleh menyentuhnya, jadi ketiadaannya
 * tidak boleh terasa. Daftar template dan kategori dititipkan kosong lewat
 * jalur injeksi yang memang disediakan view-nya.
 */

$idTemplate = (string) ($argv[1] ?? 'contoh');

$akar = dirname(__DIR__, 2);

define('EZDOC_DEBUG', true);
define('EZDOC_TEMPLATE_MANAGER_ROLES', ['pembuat_template']);

require_once $akar . '/autoload.php';
require_once $akar . '/lib/role_provider.php';
require_once $akar . '/lib/authorization.php';

ezdoc_set_role_provider([
    'has_role' => function ($roles): bool {
        return true;
    },
    'current_user_id' => function (): int {
        return 1;
    },
    'current_user_roles' => function (): array {
        return ['pembuat_template'];
    },
]);

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    new \PDO('sqlite::memory:'),
    new class implements \Ezdoc\Auth\RoleProvider {
        public function hasRole($roles): bool { return true; }
        public function currentUserId(): int { return 1; }
        public function currentUserRoles(): array { return ['pembuat_template']; }
    }
));

$config = \Ezdoc\UI\Config::fromArray([
    'brand' => ['app_name' => 'ezdoc'],
    'app' => ['base_path' => 'index.php', 'query_key' => 'ezdoc_page', 'locale' => 'id'],
]);
$theme = new \Ezdoc\UI\Theme($config);
$ctx = \Ezdoc\Context::default();
$conn = $ctx->db;

// Dititipkan supaya view tidak bertanya ke database.
$templates = [];
$existingCategories = [];

$_GET['action'] = 'edit';
$_GET['id'] = $idTemplate;
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include $akar . '/views/document/designer.php';
echo ob_get_clean();
