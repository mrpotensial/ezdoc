<?php

declare(strict_types=1);

/**
 * POST _doc_action=document_history
 *   body: doc_id
 *
 * Riwayat satu dokumen — gabungan DUA sumber, mencakup SELURUH slot.
 *
 * ## Kenapa dua sumber
 *
 * `ezdoc_audit_log` mencatat apa yang dilakukan orang: dokumen dibuat, disimpan,
 * versi baru, OTP dikirim dan diverifikasi. Yang TIDAK ada di sana adalah
 * penandatanganan itu sendiri — dan itu bukan kelalaian: datanya tersimpan lebih
 * lengkap di `ezdoc_signatures`, dengan peran penanda tangan saat itu, waktu
 * sampai milidetik, dan algoritma hash yang dipakai.
 *
 * Menyalinnya ke log audit berarti menyimpan fakta yang sama dua kali, dan dua
 * salinan bisa berselisih. Jadi digabung saat dibaca, bukan saat ditulis.
 *
 * ## Kenapa seluruh slot, bukan satu versi
 *
 * Tiap versi punya barisnya sendiri di `ezdoc_documents` dengan id sendiri. Mencari
 * lewat satu doc_id cuma memberi kejadian pada versi itu — membuka v21 akan
 * menampilkan satu baris, "versi baru dibuat", seolah tidak pernah ada apa-apa
 * sebelumnya.
 *
 * Yang orang maksud dengan "dokumen ini" adalah slotnya: satu surat untuk satu
 * pasien, yang kebetulan sudah direvisi beberapa kali. Confluence dan Google Docs
 * menampilkan riwayat sepanjang halaman, bukan sepanjang satu revisi.
 *
 * ## Bentuknya
 *
 * Setiap baris dinormalkan ke bentuk yang sama supaya sisi tampilan tidak perlu
 * tahu asalnya. Deskripsi TIDAK dirakit di sini — server mengirim `event` dan
 * datanya, sisi tampilan yang menerjemahkan. Kalau kalimatnya dirakit di sini,
 * riwayat berbahasa Indonesia akan tetap berbahasa Indonesia saat dibuka dengan
 * antarmuka bahasa Inggris.
 *
 * Response: { success, entries: [...], truncated: bool }
 */

use Ezdoc\Context;

$docId = (int) ($_POST['doc_id'] ?? 0);
if ($docId <= 0) {
    ezdoc_respond_error(t('response.incomplete_parameters', [], 'Incomplete parameters'));
}

$db = Context::default()->connection();

// Dokumen harus ada dan boleh dilihat orang ini. Riwayat menyebut siapa
// menandatangani apa dan dari IP mana — bukan sesuatu yang dibagikan hanya
// karena penebak id-nya beruntung.
$doc = $db->fetchOne(
    'SELECT d.id, d.template_id, d.template_uuid, d.norm, d.nopen, d.label, t.access_config
     FROM ezdoc_documents d
     LEFT JOIN ezdoc_templates t ON t.id = d.template_id
     WHERE d.id = ? LIMIT 1',
    [$docId]
);
if (!$doc) {
    ezdoc_respond_error(t('response.document_not_found', [], 'Document not found'), 404);
}

$accessConfig = ezdoc_parse_access_config($doc['access_config'] ?? null);
ezdoc_require_on_template($accessConfig, 'view', 'Tidak berhak melihat dokumen dari template ini');

/** Batas atas. Slot yang sering disimpan bisa punya ratusan baris. */
const EZDOC_HISTORY_LIMIT = 60;

// Semua versi di slot ini. Versi yang sudah dihapus ikut: penghapusan itu sendiri
// bagian dari riwayat, dan menyembunyikannya membuat garis waktunya berlubang
// tanpa penjelasan.
$slotRows = $db->fetchAll(
    'SELECT id, version FROM ezdoc_documents
     WHERE template_uuid = ? AND norm = ? AND nopen = ? AND label = ?
     ORDER BY version',
    [$doc['template_uuid'], $doc['norm'], $doc['nopen'], $doc['label']]
);

/** @var array<int,int> id dokumen => nomor versi */
$versionOf = [];
foreach ($slotRows as $row) {
    $versionOf[(int) $row['id']] = (int) $row['version'];
}
if ($versionOf === []) {
    $versionOf[$docId] = 0;
}

$ids = array_keys($versionOf);
$in = implode(',', array_fill(0, count($ids), '?'));

$entries = [];

// ─── Sumber 1: log audit ───
//
// Diambil lebih banyak dari batas tampilnya, karena dua sumber digabung lalu
// diurutkan ulang — memotong di sini akan membuang kejadian yang seharusnya
// muncul setelah penggabungan.
$auditRows = $db->fetchAll(
    'SELECT doc_id, occurred_at, event_type, actor_id, actor_roles, ip_address, result, message
     FROM ezdoc_audit_log
     WHERE doc_id IN (' . $in . ')
     ORDER BY occurred_at DESC
     LIMIT ' . (EZDOC_HISTORY_LIMIT * 2),
    $ids
);

foreach ($auditRows as $row) {
    $entries[] = [
        'at'      => (string) ($row['occurred_at'] ?? ''),
        'event'   => (string) ($row['event_type'] ?? ''),
        'version' => $versionOf[(int) ($row['doc_id'] ?? 0)] ?? null,
        'actor'   => (string) ($row['actor_id'] ?? ''),
        'roles'   => (string) ($row['actor_roles'] ?? ''),
        'ip'      => (string) ($row['ip_address'] ?? ''),
        // `result` membedakan yang berhasil dari yang ditolak. Percobaan OTP yang
        // gagal justru baris paling menarik di riwayat keamanan — jangan dibuang.
        'result'  => (string) ($row['result'] ?? 'ok'),
        'message' => (string) ($row['message'] ?? ''),
        'detail'  => null,
    ];
}

// ─── Sumber 2: record tanda tangan ───
try {
    $sigRows = $db->fetchAll(
        'SELECT `document_id`, `signer_id`, `signer_role`, `signed_at`, `verify_status`, `level`, `metadata`
         FROM `ezdoc_signatures`
         WHERE `document_id` IN (' . $in . ') AND `deleted_at` IS NULL
         ORDER BY `signed_at` DESC',
        $ids
    );
} catch (\Throwable $e) {
    // Consumer yang belum migrasi tetap dapat riwayatnya, tanpa bagian ini.
    @error_log('[ezdoc:history] tabel tanda tangan tidak terbaca: ' . $e->getMessage());
    $sigRows = [];
}

foreach ($sigRows as $row) {
    $meta = json_decode((string) ($row['metadata'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];

    $entries[] = [
        'at'      => (string) ($row['signed_at'] ?? ''),
        'event'   => 'signature.recorded',
        'version' => $versionOf[(int) ($row['document_id'] ?? 0)] ?? null,
        'actor'   => (string) ($row['signer_id'] ?? ''),
        'roles'   => (string) ($row['signer_role'] ?? ''),
        'ip'      => '',
        'result'  => (string) ($row['verify_status'] ?? 'valid') === 'revoked' ? 'revoked' : 'ok',
        'message' => '',
        'detail'  => [
            'ttd_label'   => (string) ($meta['ttd_label'] ?? ($meta['ttd_id'] ?? '')),
            'signer_name' => isset($meta['signer_name']) && $meta['signer_name'] !== ''
                ? (string) $meta['signer_name']
                : null,
            'level'       => (int) ($row['level'] ?? 1),
        ],
    ];
}

// Urut menurun berdasarkan waktu. Dibandingkan sebagai string karena keduanya
// 'Y-m-d H:i:s[.v]' — bentuk yang urutan leksikalnya sama dengan urutan waktunya.
// Milidetik pada `signed_at` ikut terbaca, jadi dua kejadian dalam detik yang
// sama tidak kehilangan urutannya.
usort($entries, static function (array $a, array $b): int {
    return strcmp($b['at'], $a['at']);
});

$truncated = count($entries) > EZDOC_HISTORY_LIMIT;
if ($truncated) {
    $entries = array_slice($entries, 0, EZDOC_HISTORY_LIMIT);
}

ezdoc_respond_success([
    'entries'   => $entries,
    // Dinyatakan, bukan dibiarkan tersirat. Daftar yang dipotong diam-diam
    // terbaca sebagai "cuma segini yang pernah terjadi".
    'truncated' => $truncated,
]);
