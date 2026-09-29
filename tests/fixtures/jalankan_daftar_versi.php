<?php
/**
 * Jalankan `list_versions` lewat dispatcher, di prosesnya sendiri.
 *
 *   php jalankan_daftar_versi.php [dengan-tersembunyi]
 *
 * Satu slot berisi tiga versi yang sengaja berbeda keadaan:
 *   v1  tiga slot tanda tangan terisi  → lengkap
 *   v2  satu slot terisi               → berjalan
 *   v3  belum ada tanda tangan         → draf, dan disembunyikan dari daftar
 *
 * Satu slot di v1 ditandatangani DUA KALI dengan sengaja: yang dihitung harus
 * slot yang terisi, bukan baris tanda tangan. "4 dari 3" bukan kalimat yang
 * menolong siapa pun.
 *
 * Dipakai tests/UI/PanelVersiTest.php.
 */

$dengan = ($argv[1] ?? '') === 'dengan-tersembunyi';
$root   = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'POST';

require $root . '/bootstrap.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_01_01_000001_create_ezdoc_templates.sql',
    '2026_01_01_000002_create_ezdoc_documents.sql',
    '2026_01_01_000004_create_ezdoc_audit_log.sql',
    '2026_01_01_000005_create_ezdoc_signatures.sql',
] as $f) {
    $pdo->exec((string) file_get_contents($root . '/migrations/sqlite/' . $f));
}
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

$provider = [
    'has_role'           => static function ($r) { return true; },
    'current_user_id'    => static function () { return 7; },
    'current_user_roles' => static function () { return ['superadmin']; },
];
ezdoc_set_role_provider($provider);

// Sumber nama pelaku, seperti yang disambung aplikasi pemakai. Tanpa ini
// jalur namanya tidak pernah teruji — dan "nama pembuat" justru salah satu
// alasan daftar versinya dibuat.
$direktori = new class implements \Ezdoc\Auth\ActorDirectory {
    public function displayNames(array $ids): array
    {
        $peta = [7 => 'Bidan Ratna'];
        $keluar = [];
        foreach ($ids as $id) {
            if (isset($peta[(int) $id])) {
                $keluar[(int) $id] = $peta[(int) $id];
            }
        }

        return $keluar;
    }
};

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles']),
    null,
    null,
    $direktori
));
$GLOBALS['author_id'] = 7;

// ── Template dengan tiga slot tanda tangan ───────────────────────────────
$slot = json_encode([
    ['id' => 'pasien', 'label' => 'Pasien'],
    ['id' => 'perawat', 'label' => 'Perawat'],
    ['id' => 'dokter', 'label' => 'Dokter'],
]);
$db->execute(
    "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, signature_config, is_active)
     VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', ?, 1)",
    [$slot]
);

// ── Tiga versi di satu slot ──────────────────────────────────────────────
$buat = static function (int $id, int $versi, ?string $dihapus) use ($db) {
    $db->execute(
        "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                      norm, nopen, subject_id, context_id, label, version,
                                      field_values, signature_values, created_by, updated_at, deleted_at)
         VALUES (?, ?, 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', ?, '{}', '{}', 7, ?, ?)",
        [$id, 'dok-' . $id, $versi, '2026-09-2' . $versi . ' 09:0' . $versi . ':00', $dihapus]
    );
};
$buat(11, 1, null);
$buat(12, 2, null);
$buat(13, 3, '2026-09-24 10:00:00');

$ttd = static function (string $uuid, int $doc, int $urut, string $slotId) use ($db) {
    $db->execute(
        "INSERT INTO ezdoc_signatures (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                                       envelope_format, envelope, content_hash, signed_at, verify_status, metadata)
         VALUES (?, ?, ?, '7', 'hmac', 1, 'hmac', 'e', 'h', '2026-09-21 10:00:00', 'valid', ?)",
        [$uuid, $doc, $urut, json_encode(['ttd_id' => $slotId])]
    );
};
$ttd('s1', 11, 1, 'pasien');
$ttd('s2', 11, 2, 'perawat');
$ttd('s3', 11, 3, 'dokter');
$ttd('s4', 11, 4, 'dokter');   // slot yang sama, ditandatangani ulang
$ttd('s5', 12, 1, 'pasien');

$_POST = [
    '_doc_action' => 'list_versions',
    'template_id' => '1',
    'norm'        => '1',
    'nopen'       => '11',
    'label'       => '-',
];
if ($dengan) {
    $_POST['include_hidden'] = '1';
}

require $root . '/actions/_dispatcher.php';
