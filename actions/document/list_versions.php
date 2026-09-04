<?php
/**
 * POST _doc_action=list_versions
 *   body: template_id, norm, nopen, label
 *
 * List semua versi (active only) di slot dokumen — dari template family
 * via JOIN template_uuid.
 *
 * Response: { success: true, versions: [{ id, version, is_locked, updated_at }, ...] }
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
$tpl = $db->fetchOne('SELECT access_config FROM ezdoc_templates WHERE id = ? LIMIT 1', [$tid]);
$bolehMengurus = ezdoc_can_hide_versions(ezdoc_parse_access_config($tpl['access_config'] ?? null));

// Barisnya baru ikut kalau DIMINTA dan BOLEH. Keduanya, bukan salah satu:
// yang pertama menjaga daftar biasa tetap bersih, yang kedua menjaga
// keberadaan versi tersembunyi tidak bocor ke yang tidak berhak mengurusnya.
$sertakan = !empty($_POST['include_hidden']) && $bolehMengurus;

$saring = $sertakan ? '' : ' AND d.deleted_at IS NULL';

$rows = $db->fetchAll(
    'SELECT d.id, d.version, d.is_locked, d.updated_at, d.deleted_at, d.deleted_by
     FROM ezdoc_documents d
     INNER JOIN ezdoc_templates t ON t.uuid = d.template_uuid
     WHERE t.id = ? AND d.subject_id = ? AND d.context_id = ? AND d.label = ?' . $saring . '
     ORDER BY d.version DESC',
    [$tid, $n, $np, $lb]
);

$versions = array_map(function ($row) {
    return [
        'id'         => (int) $row['id'],
        'version'    => (int) $row['version'],
        'is_locked'  => (int) $row['is_locked'],
        'updated_at' => $row['updated_at'],
        // Dikirim selalu, bukan hanya saat diminta. Pemanggil yang menerima
        // daftar campuran harus bisa membedakan tanpa menebak dari panjangnya.
        'is_hidden'  => empty($row['deleted_at']) ? 0 : 1,
        'hidden_at'  => $row['deleted_at'] ?? null,
    ];
}, $rows);

ezdoc_respond_success([
    'versions' => $versions,
    // Supaya frontend tahu apakah menampilkan penyaringnya sama sekali. Tombol
    // yang muncul lalu selalu ditolak lebih buruk daripada tombol yang tidak ada.
    'may_hide' => $bolehMengurus ? 1 : 0,
]);
