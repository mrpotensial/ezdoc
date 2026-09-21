<?php
/**
 * Jalankan penyimpanan dokumen lewat dispatcher, di prosesnya sendiri.
 *
 * Action-nya mengakhiri permintaan dengan `exit`, jadi tidak bisa dipanggil dari
 * dalam proses PHPUnit. Berkas ini menyiapkan basis data SQLite berisi satu
 * dokumen di slot (No RM 1, No Pendaftaran 11), lalu mengirim POST simpan dan
 * mencetak JSON jawabannya.
 *
 *   php jalankan_simpan.php <skenario>
 *
 *   baru-bertanda  form BARU (tanpa _doc_id) di slot dokumen bertanda tangan
 *   buka-bertanda  dokumen itu dibuka (_doc_id=5), field bertanda tangan diubah
 *   baru-kosong    form BARU di slot dokumen yang belum ditandatangani
 *
 * Dipakai tests/Document/SlotTakenSignedTest.php.
 */

$skenario = $argv[1] ?? 'baru-bertanda';
$root     = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'POST';

require $root . '/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_01_01_000001_create_ezdoc_templates.sql',
    '2026_01_01_000002_create_ezdoc_documents.sql',
    '2026_01_01_000004_create_ezdoc_audit_log.sql',
    '2026_01_01_000005_create_ezdoc_signatures.sql',
    '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
] as $f) {
    $pdo->exec((string) file_get_contents($root . '/migrations/sqlite/' . $f));
}
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

$provider = [
    'has_role'           => static function ($r) { return in_array('superadmin', (array) $r, true); },
    'current_user_id'    => static function () { return 7; },
    'current_user_roles' => static function () { return ['superadmin']; },
];
ezdoc_set_role_provider($provider);

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles'])
));

// ── Data ─────────────────────────────────────────────────────────────────
$isi = '<p>Nama: {{nama}}</p>'
     . '<div class="ttd-placeholder" data-ttd="ttd_a" data-label="Petugas" data-nama-field="nama_petugas"></div>';
$db->execute(
    "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
     VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', ?, 1)",
    [$isi]
);

$gambar = 'data:image/png;base64,iVBORw0KGgo=';
$db->execute(
    "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                  norm, nopen, subject_id, context_id, label, version,
                                  field_values, signature_values)
     VALUES (5, 'dok-5', 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', 2, ?, ?)",
    [
        json_encode(['nama' => 'Budi', 'nama_petugas' => 'Sari']),
        $skenario === 'baru-kosong' ? '{}' : json_encode(['ttd_a' => $gambar]),
    ]
);

if ($skenario !== 'baru-kosong') {
    $db->execute(
        "INSERT INTO ezdoc_signatures (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                                       envelope_format, envelope, content_hash, signed_at, verify_status, metadata)
         VALUES ('sig-1', 5, 1, '7', 'hmac', 1, 'hmac', 'e', 'h', '2026-09-21 10:00:00', 'valid', ?)",
        [json_encode(['ttd_id' => 'ttd_a', 'ttd_label' => 'Petugas', 'covered_fields' => ['nama']])]
    );
}

// ── Permintaan ───────────────────────────────────────────────────────────
// Form baru: orangnya mengetik data pasiennya sendiri, tanpa tahu dokumen #5 ada.
$_POST = [
    '_ajax'       => '1',
    'template_id' => '1',
    '_norm'       => '1',
    '_nopen'      => '11',
    '_label'      => '-',
    '_doc_id'     => $skenario === 'buka-bertanda' ? '5' : '0',
    'nama'        => 'Ani',
];
if ($skenario === 'buka-bertanda') {
    // Form yang dibuka mengirim ulang tanda tangan tersimpan pada tiap simpan.
    $_POST['ttd_a']        = $gambar;
    $_POST['nama_petugas'] = 'Sari';
}

require $root . '/actions/_dispatcher.php';
