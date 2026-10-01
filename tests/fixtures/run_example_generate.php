<?php

declare(strict_types=1);

/**
 * Buka contoh di halaman pembuatan dokumen, di proses terpisah, lalu cetak
 * HTML-nya.
 *
 *   php run_example_generate.php [template_id] [ya|tidak] [query tambahan]
 *
 * Argumen kedua: apakah pembukanya punya peran pengelola template.
 *
 * Tanpa sambungan database sama sekali — itu justru yang diuji: mode contoh
 * tidak boleh menyentuh database, jadi ketiadaannya tidak boleh terasa.
 */

$templateId = (string) ($argv[1] ?? 'example');
$boleh = (($argv[2] ?? 'ya') === 'ya');
$GLOBALS['ezdocBoleh'] = $boleh;

$akar = dirname(__DIR__, 2);

// Folder tempat kop tersimpan, kalau kasusnya memang menguji kop.
if (isset($argv[4]) && $argv[4] !== '') {
    define('EZDOC_STORAGE_PATH', (string) $argv[4]);
}

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
/**
 * Pembuat QR milik "aplikasi pemakai".
 *
 * Di dunia nyata konsumen yang menyediakannya (lewat Context::withQr() atau
 * fungsi global ini). Dipasang di sini supaya jalur QR ikut terjalani —
 * gambarnya sendiri tidak penting, yang diuji apakah datanya sampai.
 */
function generateQrForDompdf($data, $size = 200, $margin = 5)
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

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

// Parameter tambahan, mis. "view=pdf&raw=1&debug=html".
//
// Boleh juga "b64:<base64 dari query itu>". Di Windows, escapeshellarg()
// mengganti tanda persen dengan spasi untuk mencegah pemekaran variabel, jadi
// nilai ber-persen-encode tidak pernah sampai utuh lewat baris perintah —
// dan nilai berisi petik atau ampersand justru itu yang perlu diuji.
if (isset($argv[3]) && $argv[3] !== '') {
    $mentah = (string) $argv[3];
    if (strncmp($mentah, 'b64:', 4) === 0) {
        $mentah = (string) base64_decode(substr($mentah, 4), true);
    }
    $tambahan = [];
    parse_str($mentah, $tambahan);
    foreach ($tambahan as $kunci => $nilai) {
        $_GET[$kunci] = $nilai;
    }
}

ob_start();
include $akar . '/views/document/generate.php';
echo ob_get_clean();
