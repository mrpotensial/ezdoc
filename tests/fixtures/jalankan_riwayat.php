<?php
/**
 * Jalankan actions/document/document_history.php di prosesnya sendiri.
 *
 * Action itu mengakhiri permintaan dengan `exit`, jadi tidak bisa dipanggil dari
 * dalam proses PHPUnit. Berkas ini menyiapkan basis data SQLite berisi satu
 * dokumen dengan dua tanda tangan (satu lewat OTP), dua siklus arsip tampilan,
 * lalu mencetak JSON jawaban action-nya.
 *
 *   php jalankan_riwayat.php superadmin|biasa
 *
 * Dipakai tests/Document/HistoryArchiveOtpTest.php.
 */

$peran = $argv[1] ?? 'superadmin';
$root  = dirname(__DIR__, 2);

require $root . '/autoload.php';
foreach (['responses', 'role_provider', 'authorization', 'actor_names', 'view_helpers'] as $lib) {
    require_once $root . '/lib/' . $lib . '.php';
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    '2026_01_01_000001_create_ezdoc_templates.sql',
    '2026_01_01_000002_create_ezdoc_documents.sql',
    '2026_01_01_000004_create_ezdoc_audit_log.sql',
    '2026_01_01_000005_create_ezdoc_signatures.sql',
    '2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql',
    '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
] as $f) {
    $pdo->exec((string) file_get_contents($root . '/migrations/sqlite/' . $f));
}
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

$isSuper = $peran === 'superadmin';

// Sama seperti pemasangan sungguhan: consumer menyatakan siapa yang boleh melihat
// detail audit. Tanpa konstanta ini TIDAK seorang pun boleh — bawaan yang aman.
define('EZDOC_AUDIT_DETAIL_ROLES', json_encode(['superadmin']));

$provider = [
    // ezdoc_has_role() meneruskan string ATAU array peran.
    'has_role'           => static function ($r) use ($isSuper) { return $isSuper && in_array('superadmin', (array) $r, true); },
    'current_user_id'    => static function () { return 7; },
    'current_user_roles' => static function () use ($isSuper) { return $isSuper ? ['superadmin'] : ['perawat']; },
];
ezdoc_set_role_provider($provider);

\Ezdoc\Context::setDefault(new \Ezdoc\Context(
    $db,
    new \Ezdoc\Auth\CallableRoleProvider($provider['has_role'], $provider['current_user_id'], $provider['current_user_roles'])
));

// ── Data ─────────────────────────────────────────────────────────────────
$db->execute(
    "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
     VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', 1)"
);
$db->execute(
    "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                  norm, nopen, subject_id, context_id, label, version)
     VALUES (5, 'dok-5', 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', 1)"
);

// Tanda tangan pertama tanpa OTP, kedua lewat OTP.
foreach ([
    ['sig-1', 'ttd_a', '2026-09-21 14:09:39.142'],
    ['sig-2', 'ttd_b', '2026-09-21 14:10:18.366'],
] as $i => [$uuid, $ttd, $pada]) {
    $db->execute(
        'INSERT INTO ezdoc_signatures (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                                       envelope_format, envelope, content_hash, signed_at, verify_status, metadata)
         VALUES (?, 5, ?, \'7\', \'hmac\', 1, \'hmac\', \'e\', \'h\', ?, \'valid\', ?)',
        [$uuid, $i + 1, $pada, json_encode(['ttd_id' => $ttd, 'ttd_label' => 'Slot ' . $ttd])]
    );
}

// Tantangan OTP: satu gagal (tidak terverifikasi), satu terverifikasi untuk ttd_b.
$db->execute(
    "INSERT INTO ezdoc_ttd_otp_challenges (challenge_id, doc_id, template_id, ttd_id, phone, otp_hash,
                                           attempts, max_attempts, expires_at, created_at)
     VALUES ('otp-gagal', 5, 1, 'ttd_b', '081299990000', 'x', 3, 3, '2026-09-21 14:20:00', '2026-09-21 14:09:40')"
);
$db->execute(
    "INSERT INTO ezdoc_ttd_otp_challenges (challenge_id, doc_id, template_id, ttd_id, phone, otp_hash,
                                           attempts, max_attempts, verified_at, expires_at, created_at)
     VALUES ('otp-ok', 5, 1, 'ttd_b', '081248664458', 'x', 1, 3, '2026-09-21 14:10:16', '2026-09-21 14:20:00', '2026-09-21 14:09:50')"
);

// Dua siklus arsip: yang pertama digantikan.
$db->execute(
    "INSERT INTO ezdoc_document_artifacts (uuid, document_id, cycle, blob_key, manifest, frozen_at, frozen_by, superseded_at)
     VALUES ('art-1', 5, 1, 'k1', '{}', '2026-09-21 14:09:39', '7', '2026-09-21 14:10:18')"
);
$db->execute(
    "INSERT INTO ezdoc_document_artifacts (uuid, document_id, cycle, blob_key, manifest, frozen_at, frozen_by)
     VALUES ('art-2', 5, 2, 'k2', '{}', '2026-09-21 14:10:18', '7')"
);

$_POST = ['doc_id' => '5'];
$_SERVER['REQUEST_METHOD'] = 'POST';

require $root . '/actions/document/document_history.php';
