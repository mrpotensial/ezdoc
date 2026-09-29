<?php
/**
 * Jalankan `new_version` lewat dispatcher, di prosesnya sendiri.
 *
 *   php jalankan_versi_baru.php <boleh_kosong:0|1> <source_version> [versi_awal]
 *
 * `versi_awal` = berapa versi yang sudah ada di slot itu sebelum permintaan
 * dikirim (0 = slot masih kosong). Tiap versi diisi penanda yang berbeda, jadi
 * yang tersalin bisa ditunjuk: `{"nama":"v2"}` hanya bisa datang dari v2.
 *
 * Keluarannya dua bagian dipisah baris `---`: jawaban JSON action-nya, lalu
 * keadaan basis data sesudahnya (jawabannya sendiri tidak menyebut isi).
 *
 * Dipakai tests/Document/VersiKosongSetelanTest.php.
 */

$bolehKosong = ($argv[1] ?? '1') === '1';
$sourceVersion = (string) ($argv[2] ?? '0');
$versiAwal = (int) ($argv[3] ?? 1);

$root = dirname(__DIR__, 2);

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

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles'])
));
$GLOBALS['author_id'] = 7;

$db->execute(
    "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, signature_config, is_active)
     VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', '[]', 1)"
);

for ($v = 1; $v <= $versiAwal; $v++) {
    $db->execute(
        "INSERT INTO ezdoc_documents (uuid, template_id, template_uuid, template_version, title,
                                      norm, nopen, subject_id, context_id, label, version,
                                      field_values, signature_values, created_by)
         VALUES (?, 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', ?, ?, '{}', 7)",
        ['dok-' . $v, $v, json_encode(['nama' => 'v' . $v])]
    );
}

// Setelan pemasangan. Dispatcher memakai yang sudah ada di scope ini.
$config = \Ezdoc\UI\Config::fromArray(['version.allow_blank' => $bolehKosong]);

// Action menjawab lalu exit, jadi keadaan basis datanya dicetak dari sini.
register_shutdown_function(static function () use ($db) {
    $baris = $db->fetchOne(
        'SELECT version, field_values FROM ezdoc_documents
         WHERE norm = ? AND nopen = ? AND label = ? ORDER BY version DESC LIMIT 1',
        ['1', '11', '-']
    );
    $audit = $db->fetchOne(
        "SELECT message, metadata FROM ezdoc_audit_log
         WHERE event_type = 'doc.version_created' ORDER BY id DESC LIMIT 1"
    );
    echo "\n---\n" . json_encode([
        'version'  => $baris ? (int) $baris['version'] : 0,
        'isi'      => $baris ? (string) $baris['field_values'] : '',
        'metadata' => $audit ? (string) $audit['metadata'] : '',
        'catatan'  => $audit ? (string) $audit['message'] : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

$_POST = [
    '_doc_action'    => 'new_version',
    'template_id'    => '1',
    'norm'           => '1',
    'nopen'          => '11',
    'label'          => '-',
    'source_version' => $sourceVersion,
];

require $root . '/actions/_dispatcher.php';
