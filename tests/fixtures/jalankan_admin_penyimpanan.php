<?php
/**
 * Jalankan aksi penyimpanan halaman admin, di prosesnya sendiri.
 *
 *   php jalankan_admin_penyimpanan.php <superadmin|biasa> <aksi> <apply> <berkas.sqlite> <folder>
 *
 * Aksinya menjawab JSON lalu `exit`, jadi tidak bisa dipanggil dari dalam
 * proses PHPUnit. Isi basis datanya sama dengan runner CLI: dua objek dirujuk
 * dokumen, satu PDF beku yang hanya dirujuk arsip, satu objek yatim.
 *
 * Mencetak jawaban aksinya, lalu `@@HASIL@@{json}`: sidik jari seluruh isi
 * basis data sebelum/sesudah, dan berkas di folder.
 *
 * Dipakai tests/UI/AdminStorageTest.php.
 */

[$peran, $aksi, $apply, $berkasDb, $folder, $konfirmasi] = array_slice($argv, 1, 6) + ['superadmin', 'ringkas', '0', '', '', ''];
$root = dirname(__DIR__, 2);

require $root . '/autoload.php';
foreach (['responses', 'role_provider', 'authorization', 'view_helpers'] as $lib) {
    require_once $root . '/lib/' . $lib . '.php';
}

$baru = !is_file($berkasDb);
$pdo = new PDO('sqlite:' . $berkasDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

if ($baru) {
    foreach ([
        '2026_01_01_000001_create_ezdoc_templates.sql',
        '2026_01_01_000002_create_ezdoc_documents.sql',
        '2026_07_30_000001_create_ezdoc_blobs.sql',
        '2026_01_01_000004_create_ezdoc_audit_log.sql',
        '2026_01_01_000005_create_ezdoc_signatures.sql',
        '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
    ] as $f) {
        $pdo->exec((string) file_get_contents($root . '/migrations/sqlite/' . $f));
    }

    $lama = '2026-01-01 00:00:00';
    $kunci = [];
    foreach (['A' => 'ttd-a', 'B' => 'ttd-b', 'E' => 'yatim'] as $nama => $byte) {
        $kunci[$nama] = hash('sha256', $byte);
        $db->execute(
            "INSERT INTO ezdoc_blobs (\"key\", checksum, byte_size, content_type, backend, content, created_at)
             VALUES (?, ?, ?, 'image/png', 'database', ?, ?)",
            [$kunci[$nama], 'sha256:' . $kunci[$nama], strlen($byte), $byte, $lama]
        );
    }
    $pdf = '%PDF-1.7 beku';
    $kunci['D'] = hash('sha256', $pdf);
    @mkdir($folder . '/' . substr($kunci['D'], 0, 2), 0777, true);
    file_put_contents($folder . '/' . substr($kunci['D'], 0, 2) . '/' . $kunci['D'], $pdf);
    $db->execute(
        "INSERT INTO ezdoc_blobs (\"key\", checksum, byte_size, content_type, backend, content, created_at)
         VALUES (?, ?, ?, 'application/pdf', 'disk', NULL, ?)",
        [$kunci['D'], 'sha256:' . $kunci['D'], strlen($pdf), $lama]
    );
    $db->execute(
        "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
         VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', 1)"
    );
    $db->execute(
        "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                      norm, nopen, subject_id, context_id, label, version, signature_values)
         VALUES (1, 'dok-1', 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', 1, ?)",
        [json_encode([
            'a' => 'blob:sha256-' . $kunci['A'],
            'b' => 'blob:sha256-' . $kunci['B'],
            'c' => 'data:image/png;base64,' . base64_encode('gambar-inline'),
        ])]
    );
    $db->execute(
        "INSERT INTO ezdoc_document_artifacts (uuid, document_id, cycle, blob_key, frozen_at)
         VALUES ('art-1', 1, 1, ?, ?)",
        [$kunci['D'], $lama]
    );

    // Template kedua punya aturan aksesnya sendiri; yang pertama tidak.
    $db->execute(
        "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active, access_config)
         VALUES (2, 'tpl-2', 'uji2', 1, 1, 'Dengan aturan', 'umum', 'patient', '<p>x</p>', 1, ?)",
        [json_encode(['mode' => 'strict', 'edit' => ['roles' => ['perawat'], 'users' => []]])]
    );

    // Satu tanda tangan, dan dua kejadian audit: satu berhasil, satu ditolak.
    $db->execute(
        "INSERT INTO ezdoc_signatures (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                                       envelope_format, envelope, content_hash, signed_at, verify_status, metadata)
         VALUES ('sig-1', 1, 1, '7', 'hmac', 1, 'hmac', 'e', 'h', ?, 'valid', '{}')",
        [$lama]
    );
    foreach ([['doc.created', 'success', '7'], ['authz.denied', 'denied', '9']] as $i => [$jenis, $hasil, $pelaku]) {
        $db->execute(
            "INSERT INTO ezdoc_audit_log (event_type, event_uuid, actor_id, result, doc_id, ip_address, occurred_at)
             VALUES (?, ?, ?, ?, 1, '10.0.0.7', datetime('now', '-1 hour'))",
            [$jenis, 'ev-' . $i, $pelaku, $hasil]
        );
    }
}

$isSuper = $peran === 'superadmin';
$provider = [
    'has_role'           => static function ($r) use ($isSuper) { return $isSuper && in_array('superadmin', (array) $r, true); },
    'current_user_id'    => static function () { return 7; },
    'current_user_roles' => static function () use ($isSuper) { return $isSuper ? ['superadmin'] : ['perawat']; },
];
ezdoc_set_role_provider($provider);
\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles'])
));

$sidik = static function () use ($pdo): string {
    $semua = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $t) {
        $semua[$t['name']] = $pdo->query('SELECT * FROM "' . $t['name'] . '" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }

    return hash('sha256', (string) json_encode($semua));
};
$sebelum = $sidik();

register_shutdown_function(static function () use ($sidik, $sebelum, $folder): void {
    $berkas = [];
    if (is_dir($folder)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $berkas[] = (string) file_get_contents($f->getPathname());
        }
    }
    sort($berkas);
    echo "\n@@HASIL@@" . json_encode(['db_sama' => $sidik() === $sebelum, 'berkas' => $berkas]) . "\n";
});

define('EZDOC_STORAGE_PATH', $folder);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'storage_action' => $aksi,
    'apply'          => $apply,
    'after_id'       => '0',
    'konfirmasi'     => $konfirmasi,
    // Untuk aksi uji_adapter: yang diuji disebut di argumen konfirmasi.
    'adapter'        => $konfirmasi,
];
$config = new \Ezdoc\UI\Config([]);

require $root . '/views/admin/migrate.php';
