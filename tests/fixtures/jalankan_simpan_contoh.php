<?php
/**
 * Jalankan "simpan contoh jadi template" lewat dispatcher, di prosesnya sendiri.
 *
 * Action-nya mengakhiri permintaan dengan `exit`, jadi tidak bisa dipanggil dari
 * dalam proses PHPUnit. Berkas ini menyiapkan basis data SQLite kosong, mengirim
 * POST-nya, lalu mencetak JSON jawabannya — disusul satu baris `---` dan
 * cuplikan isi tabelnya, supaya yang memanggil bisa memeriksa yang benar-benar
 * tersimpan, bukan cuma yang dikatakan jawabannya.
 *
 *   php jalankan_simpan_contoh.php <skenario> [nama-folder-contoh]
 *
 *   baru           contoh disimpan di basis data yang belum punya template
 *   nama-dipakai   sudah ada template bernama sama
 *   nama-terhapus  template bernama sama tapi sudah dihapus (deleted_at terisi)
 *   nama-sendiri   nama ditentukan yang menekan tombol
 *   tanpa-hak      yang menekan tidak berhak merancang template
 *   tak-ada        nama folder contohnya tidak ada
 *   render         sesudah tersimpan, template hasilnya dirender ulang dan
 *                  elemen yang diletakkan bebas dicetak di bagian `---render---`
 *
 * Dipakai tests/UI/SimpanContohJadiTemplateTest.php.
 */

$skenario = $argv[1] ?? 'baru';
$slug     = $argv[2] ?? ($skenario === 'tak-ada' ? 'tidak-ada-folder-ini' : 'skor-bishop');
$root     = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'POST';

// Hak template hanya benar-benar ditegakkan kalau aplikasi pemakainya memang
// mendaftarkan rolenya — tanpa daftar itu, pustaka mengizinkan semua orang
// (kompatibilitas ke belakang). Skenario "tanpa-hak" memasang keduanya supaya
// yang diuji penjaganya, bukan bawaan permisifnya.
if ($skenario === 'tanpa-hak') {
    define('EZDOC_TEMPLATE_MANAGER_ROLES', ['superadmin']);
    define('EZDOC_ENFORCE_RBAC', true);
}

// Folder tempat kop tersimpan, kalau kasusnya memang menguji kop.
if (isset($argv[3]) && $argv[3] !== '') {
    define('EZDOC_STORAGE_PATH', (string) $argv[3]);
}

require $root . '/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_01_01_000001_create_ezdoc_templates.sql',
    '2026_01_01_000004_create_ezdoc_audit_log.sql',
] as $f) {
    $pdo->exec((string) file_get_contents($root . '/migrations/sqlite/' . $f));
}
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

$boleh = ($skenario !== 'tanpa-hak');
$provider = [
    'has_role'           => static function ($r) use ($boleh) {
        return $boleh && in_array('superadmin', (array) $r, true);
    },
    'current_user_id'    => static function () { return 7; },
    'current_user_roles' => static function () use ($boleh) { return $boleh ? ['superadmin'] : ['perawat']; },
];
ezdoc_set_role_provider($provider);

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles'])
));

$GLOBALS['author_id'] = 7;

// ── Data yang sudah ada sebelumnya ───────────────────────────────────────
$contoh = \Ezdoc\Template\Contoh::muat($slug);
$namaContoh = $contoh !== null
    ? trim((string) preg_replace('/\s*\(contoh\)\s*$/iu', '', (string) $contoh['nama_template']))
    : 'Entah';

if ($skenario === 'nama-dipakai' || $skenario === 'nama-terhapus') {
    $db->execute(
        "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active, deleted_at)
         VALUES (1, 'tpl-lama', 'lama', 1, 1, ?, 'umum', 'patient', '<p>punya orang lain</p>', 1, ?)",
        [$namaContoh, $skenario === 'nama-terhapus' ? '2026-09-01 08:00:00' : null]
    );
}

// ── Isi tabelnya dicetak sesudah jawabannya, apa pun yang terjadi ────────
register_shutdown_function(static function () use ($db) {
    $baris = $db->fetchAll(
        'SELECT id, name, category, scope, content, floating_elements, signature_config,
                layout_config, verify_config, owner_id, is_active, is_locked, version, is_current
         FROM ezdoc_templates ORDER BY id'
    );
    $log = $db->fetchAll('SELECT event_type, target_id, template_id, actor_id FROM ezdoc_audit_log ORDER BY id');

    // Kop contohnya memuat logo base64 belasan ribu huruf, dan yang diperiksa
    // dari kolom itu cuma "ada isinya" — jadi dipotong supaya keluarannya masih
    // bisa dibaca orang waktu tesnya gagal.
    foreach ($baris as $i => $b) {
        // Logo kop juga ikut di sini, dan panjangnya yang membedakan logo
        // contoh dari logo yang diatur pemakainya.
        $kepala = json_decode((string) $b['layout_config'], true);
        $baris[$i]['logo_kop_panjang'] = is_array($kepala)
            ? strlen((string) ($kepala['logos']['kop'] ?? ''))
            : 0;
        $baris[$i]['layout_config'] = $b['layout_config'] === null
            ? null
            : substr((string) $b['layout_config'], 0, 90) . '…';
    }

    echo "\n---\n" . json_encode(['templates' => $baris, 'audit' => $log]);
});

// Template hasil simpan dirender ulang, seperti halaman cetak melakukannya.
//
// Elemen mengapung disimpan di kolomnya sendiri dan penandanya dibuang dari isi
// HTML — sama seperti simpan dari designer. Kalau pemasangan ulangnya tidak
// jalan, stempelnya hilang dari template hasil simpan padahal contohnya
// menampilkannya. Itu jenis kerusakan yang baru ketahuan di kertas.
if ($skenario === 'render') {
    register_shutdown_function(static function () use ($db) {
        $baris = $db->fetchOne(
            'SELECT id, uuid, version, name AS nama_template, category,
                    content AS template_html, layout_config AS config_header,
                    signature_config AS config_ttd, scope AS doc_scope,
                    floating_elements, verify_config
             FROM ezdoc_templates ORDER BY id DESC LIMIT 1'
        );
        if ($baris === null) {
            echo "\n---render---\n" . json_encode(['gagal' => 'tidak ada template tersimpan']);
            return;
        }

        // Penandanya TIDAK dipasang ulang di sini: perakit dokumen yang
        // melakukannya sendiri dari kolom `floating_elements`. Memasangnya dua
        // kali mencetak stempelnya dua kali — itu pernah terjadi saat berkas ini
        // ditulis.
        try {
            $html = ezdoc_document_pdf_html([
                'template'   => $baris,
                'doc_id'     => 0,
                'fields'     => [],
                'ttd_values' => [],
                'conn'       => null,
            ]);
        } catch (\Throwable $e) {
            echo "\n---render---\n" . json_encode(['gagal' => $e->getMessage()]);
            return;
        }

        // Yang dibandingkan bukan seluruh dokumen — itu akan pecah tiap kali
        // ada perubahan gaya — tapi elemen yang diletakkan bebas: itu yang
        // lewat kolom terpisah dan bisa hilang di jalan.
        $mengapung = static function (string $html): array {
            preg_match_all('/<[a-z]+[^>]*position:\s*absolute[^>]*>/i', $html, $m);
            return array_map(static function ($t) {
                return preg_replace('/base64,[^"\']{40,}/', 'base64,…', $t);
            }, $m[0]);
        };

        echo "\n---render---\n" . json_encode([
            'panjang'   => strlen($html),
            'mengapung' => $mengapung($html),
        ]);
    });
}

// ── Permintaan ───────────────────────────────────────────────────────────
$_POST = [
    'ajax'   => '1',
    'action' => 'save_contoh',
    'contoh' => $slug,
];
if ($skenario === 'nama-sendiri') {
    $_POST['nama_template'] = 'Lembar Skor Bishop VK';
}

require $root . '/actions/_dispatcher.php';
