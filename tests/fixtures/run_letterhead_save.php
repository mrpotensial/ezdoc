<?php
/**
 * Jalankan pengaturan kop contoh lewat dispatcher, di prosesnya sendiri.
 *
 *   php run_letterhead_save.php <skenario> <folder-penyimpanan> [berkas-logo]
 *
 *   simpan        nama, alamat dua baris, dan kota diisi
 *   kosong        semuanya dikosongkan — kembali ke nilai config
 *   logo          logo diunggah (butuh argumen ketiga)
 *   logo-bukan    berkas yang bukan gambar diunggah
 *   hapus-logo    logo yang sudah tersimpan dibuang
 *   reset         seluruh pengaturannya dihapus
 *   tanpa-hak     yang menekan tidak berhak merancang template
 *   tanpa-tempat  pemasangannya tidak punya folder penyimpanan
 *
 * Sesudah jawabannya, dicetak baris `---` lalu isi berkas tersimpannya dan kop
 * yang benar-benar dipakai contoh — yang diperiksa bukan apa yang dikatakan
 * jawabannya, melainkan apa yang jadi di dokumen.
 *
 * Dipakai tests/UI/ExampleLetterheadUiTest.php.
 */

$skenario = $argv[1] ?? 'simpan';
$tempat   = (string) ($argv[2] ?? '');
$logo     = (string) ($argv[3] ?? '');
$root     = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'POST';

if ($skenario === 'tanpa-hak') {
    define('EZDOC_TEMPLATE_MANAGER_ROLES', ['superadmin']);
    define('EZDOC_ENFORCE_RBAC', true);
}
if ($skenario !== 'tanpa-tempat' && $tempat !== '') {
    define('EZDOC_STORAGE_PATH', $tempat);
}

require $root . '/bootstrap.php';

$boleh = ($skenario !== 'tanpa-hak');
$provider = [
    'has_role'           => static function ($r) use ($boleh) {
        return $boleh && in_array('superadmin', (array) $r, true);
    },
    'current_user_id'    => static function () { return 7; },
    'current_user_roles' => static function () use ($boleh) { return $boleh ? ['superadmin'] : ['perawat']; },
];
ezdoc_set_role_provider($provider);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents($root . '/migrations/sqlite/2026_01_01_000004_create_ezdoc_audit_log.sql'));
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles'])
));

$GLOBALS['author_id'] = 7;

// Apa yang tersimpan, dan apa yang jadi kop di contohnya.
register_shutdown_function(static function () use ($db) {
    $contoh = \Ezdoc\Template\Example::load('skor-bishop');
    $kepala = json_decode((string) $contoh['config_header'], true);
    $isi = (string) $contoh['template_html'];

    $ambil = static function (string $pola) use ($isi): string {
        return preg_match($pola, $isi, $m) === 1 ? trim($m[1]) : '';
    };

    echo "\n---\n" . json_encode([
        'tersimpan' => \Ezdoc\Template\Example::storedLetterhead(),
        'di_dokumen' => [
            'nama' => $ambil('/<p class="ezdoc-kop-nama"[^>]*>(.*?)<\/p>/s'),
            'alamat' => $ambil('/<p class="ezdoc-kop-alamat"[^>]*>(.*?)<\/p>/s'),
            'kota' => $ambil('/<span class="ezdoc-kop-kota"[^>]*>(.*?)<\/span>/s'),
            'logo_panjang' => strlen((string) ($kepala['logos']['kop'] ?? '')),
        ],
        'audit' => $db->fetchAll('SELECT event_type FROM ezdoc_audit_log ORDER BY id'),
    ]);
});

// ── Permintaan ───────────────────────────────────────────────────────────
$_POST = ['ajax' => '1', 'action' => 'save_letterhead'];

switch ($skenario) {
    case 'reset':
        $_POST['reset'] = '1';
        break;
    case 'kosong':
        $_POST += ['nama' => '  ', 'alamat' => "\n\n", 'kota' => ''];
        break;
    case 'hapus-logo':
        $_POST += ['nama' => 'RS UJI', 'alamat' => 'Jalan Uji 1', 'kota' => 'Kota Uji', 'hapus_logo' => '1'];
        break;
    default:
        $_POST += [
            'nama'   => 'RUMAH SAKIT UJI COBA',
            'alamat' => "Jalan Uji No. 1\n\nTelp (021) 000-0000",
            'kota'   => 'Kota Uji',
        ];
}

if (($skenario === 'logo' || $skenario === 'logo-bukan') && $logo !== '') {
    // Berkas unggahan sungguhan tidak ada di CLI; is_uploaded_file() menolak
    // apa pun di sini. Jadi jalurnya dipendekkan sampai titik itu: yang diuji
    // pemeriksaan jenis berkas dan pengecilannya, bukan SAPI-nya.
    $_FILES['logo'] = [
        'name' => basename($logo),
        'type' => 'image/png',
        'tmp_name' => $logo,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($logo),
    ];
}

require $root . '/actions/_dispatcher.php';
