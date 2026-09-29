<?php
/**
 * POST _doc_action=list_versions
 *   body: template_id, norm, nopen, label
 *
 * List semua versi (active only) di slot dokumen — dari template family
 * via JOIN template_uuid.
 *
 * Response: { success: true, slot_total, versions: [{ id, version, is_locked,
 *              updated_at, is_hidden, hidden_at, signed_count, created_by_name }, ...] }
 *
 * `signed_count` dan `created_by_name` ada supaya daftar versi bisa menjawab
 * pertanyaan yang sebenarnya ditanyakan orang — "yang mana yang sudah lengkap?"
 * — tanpa membuka satu per satu. Keduanya diambil sekaligus untuk semua versi,
 * bukan per baris: satu slot bisa punya belasan versi, dan satu kueri per baris
 * adalah N+1 yang sama yang melahirkan eager loading di Doctrine dan Eloquent.
 *
 * Note: response backward-compat pakai key `versions` top-level (frontend
 * akses `data.versions`).
 *
 * ## v0.9.9 refactor
 *
 * JOIN query di raw SQL — QueryBuilder MVP belum handle JOIN table lookup
 * pattern ini clean, jadi tetap raw SQL via Connection::fetchAll.
 */

use Ezdoc\Context;

$tid = (int)($_POST['template_id'] ?? 0);
// Menerima subject_id/context_id maupun nama lamanya — lihat ezdoc_slot_identity().
[$n, $np] = ezdoc_slot_identity($_POST);
$lb  = trim($_POST['label'] ?? '-');

if ($tid <= 0 || $n === '' || $np === '') {
    // Backward-compat: existing frontend expect versions=[] even on error
    ezdoc_respond_raw(['success' => false, 'versions' => []]);
}

$db = Context::default()->connection();

// ─── Versi tersembunyi: hanya kalau diminta, dan hanya untuk yang berhak ───
//
// Dua penjaga, bukan satu. `include_hidden` menjaga daftar biasa tetap bersih —
// pemanggil yang tidak meminta tidak akan tiba-tiba melihat versi yang sudah
// disingkirkan. Pemeriksaan haknya menjaga keberadaan versi tersembunyi tidak
// bocor ke orang yang tidak boleh mengurusnya.
//
// Haknya sama persis dengan menyembunyikan — satu aturan di
// lib/authorization.php, dipakai empat tempat.
// Izinnya dihitung SELALU, bukan hanya saat versi tersembunyi diminta.
//
// Versi pertama berkas ini menghitungnya di dalam `if ($minta)`, dan itu
// berputar: pemuatan pertama tidak mengirim `include_hidden`, jadi jawabannya
// selalu 0, jadi tombolnya tidak pernah muncul — padahal tombol itulah
// satu-satunya yang bisa mengirim `include_hidden`.
//
// "Boleh mengurus versi tersembunyi" adalah sifat penggunanya, bukan sifat
// permintaan ini.
$tpl = $db->fetchOne('SELECT access_config, signature_config FROM ezdoc_templates WHERE id = ? LIMIT 1', [$tid]);
$bolehMengurus = ezdoc_can_hide_versions(ezdoc_parse_access_config($tpl['access_config'] ?? null));

// Berapa slot tanda tangan yang dipunyai templatenya. Sama untuk semua versi,
// jadi dihitung sekali di sini dan dikirim di tingkat atas jawabannya.
$__slot = json_decode((string) ($tpl['signature_config'] ?? '[]'), true);
$slotTotal = is_array($__slot) ? count($__slot) : 0;

// Barisnya baru ikut kalau DIMINTA dan BOLEH. Keduanya, bukan salah satu:
// yang pertama menjaga daftar biasa tetap bersih, yang kedua menjaga
// keberadaan versi tersembunyi tidak bocor ke yang tidak berhak mengurusnya.
$sertakan = !empty($_POST['include_hidden']) && $bolehMengurus;

$saring = $sertakan ? '' : ' AND d.deleted_at IS NULL';

$rows = $db->fetchAll(
    'SELECT d.id, d.version, d.is_locked, d.updated_at, d.deleted_at, d.deleted_by, d.created_by
     FROM ezdoc_documents d
     INNER JOIN ezdoc_templates t ON t.uuid = d.template_uuid
     WHERE t.id = ? AND d.subject_id = ? AND d.context_id = ? AND d.label = ?' . $saring . '
     ORDER BY d.version DESC',
    [$tid, $n, $np, $lb]
);

// ── Kemajuan tanda tangan tiap versi, sekali jalan ───────────────────────
//
// Yang dihitung SLOT yang terisi, bukan baris tanda tangan: satu slot yang
// ditandatangani ulang meninggalkan lebih dari satu baris, dan "4 dari 3"
// bukan kalimat yang menolong siapa pun.
$idDokumen = [];
foreach ($rows as $row) {
    $idDokumen[] = (int) $row['id'];
}

$terisi = [];
if ($idDokumen !== []) {
    $tanda = implode(',', array_fill(0, count($idDokumen), '?'));
    $barisTtd = $db->fetchAll(
        'SELECT `document_id`, `metadata` FROM `ezdoc_signatures`
         WHERE `document_id` IN (' . $tanda . ') AND `deleted_at` IS NULL',
        $idDokumen
    );
    foreach ($barisTtd as $b) {
        $meta = json_decode((string) ($b['metadata'] ?? ''), true);
        if (is_array($meta) && !empty($meta['ttd_id'])) {
            $terisi[(int) $b['document_id']][(string) $meta['ttd_id']] = true;
        }
    }
}

// Nama pembuat, juga sekaligus. Sumber namanya disambung aplikasi pemakai;
// yang tidak ketemu tidak menghentikan apa pun — daftarnya cuma kehilangan
// satu baris keterangan.
$idPembuat = [];
foreach ($rows as $row) {
    if ((int) ($row['created_by'] ?? 0) > 0) {
        $idPembuat[] = (int) $row['created_by'];
    }
}
$namaPembuat = $idPembuat !== [] ? ezdoc_actor_names($idPembuat) : [];

$versions = array_map(function ($row) use ($terisi, $namaPembuat) {
    return [
        'id'         => (int) $row['id'],
        'version'    => (int) $row['version'],
        'is_locked'  => (int) $row['is_locked'],
        'updated_at' => $row['updated_at'],
        // Dikirim selalu, bukan hanya saat diminta. Pemanggil yang menerima
        // daftar campuran harus bisa membedakan tanpa menebak dari panjangnya.
        'is_hidden'  => empty($row['deleted_at']) ? 0 : 1,
        'hidden_at'  => $row['deleted_at'] ?? null,
        'signed_count' => isset($terisi[(int) $row['id']]) ? count($terisi[(int) $row['id']]) : 0,
        'created_by_name' => (string) ($namaPembuat[(int) ($row['created_by'] ?? 0)] ?? ''),
    ];
}, $rows);

ezdoc_respond_success([
    'versions' => $versions,
    // Penyebut "x dari y". Dikirim sekali, bukan diulang tiap baris.
    'slot_total' => $slotTotal,
    // Supaya frontend tahu apakah menampilkan penyaringnya sama sekali. Tombol
    // yang muncul lalu selalu ditolak lebih buruk daripada tombol yang tidak ada.
    'may_hide' => $bolehMengurus ? 1 : 0,
]);
