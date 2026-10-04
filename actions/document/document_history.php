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
    'SELECT d.id, d.template_id, d.template_uuid, d.subject_id, d.context_id, d.label, t.access_config
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

/**
 * Dua tingkat. Yang boleh membuka dokumen melihat siapa, kapan, dan apa. Alamat
 * IP dan daftar role orang lain butuh izin tersendiri — lihat
 * ezdoc_can_view_audit_detail() dan docs/260804-pengungkapan-identitas.md.
 *
 * Disaring di sini, bukan di tampilan. Yang bocor adalah JSON-nya; menyembunyikan
 * kolom di JavaScript meninggalkan datanya utuh satu klik di balik tab Network.
 */
$showAuditDetail = ezdoc_can_view_audit_detail();

// Semua versi di slot ini. Versi yang sudah dihapus ikut: penghapusan itu sendiri
// bagian dari riwayat, dan menyembunyikannya membuat garis waktunya berlubang
// tanpa penjelasan.
$slotRows = $db->fetchAll(
    'SELECT id, version FROM ezdoc_documents
     WHERE template_uuid = ? AND subject_id = ? AND context_id = ? AND label = ?
     ORDER BY version',
    [$doc['template_uuid'], $doc['subject_id'], $doc['context_id'], $doc['label']]
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

// ─── Nomor yang menerima OTP, untuk tanda tangan yang memakainya ───
//
// Record tanda tangan tidak menyimpannya; tantangan OTP-nya yang menyimpan.
// Keduanya dikaitkan lewat slot yang sama: tantangan terverifikasi terakhir
// untuk (dokumen, slot) yang terjadi SEBELUM tanda tangannya tercatat.
//
// Nomor HP data pribadi. Mengikuti aturan yang sama dengan IP dan role di panel
// ini: lengkap hanya untuk yang berhak melihat detail audit, sisanya disamarkan
// sampai empat angka terakhir — cukup untuk mengenali "nomor yang itu" tanpa
// membeberkannya. Pola yang sama dipakai sertifikat penyelesaian DocuSign.
$otpPerSlot = [];
try {
    $otpRows = $db->fetchAll(
        'SELECT `doc_id`, `ttd_id`, `phone`, `verified_at`
         FROM `ezdoc_ttd_otp_challenges`
         WHERE `doc_id` IN (' . $in . ') AND `verified_at` IS NOT NULL
         ORDER BY `verified_at`',
        $ids
    );
    foreach ($otpRows as $o) {
        $otpPerSlot[(int) $o['doc_id'] . '|' . (string) $o['ttd_id']][] = $o;
    }
} catch (\Throwable $e) {
    // Pemasangan tanpa OTP tidak punya tabelnya. Riwayatnya tetap utuh.
    $otpPerSlot = [];
}

$samarkanNomor = static function (string $nomor) use ($showAuditDetail): string {
    $nomor = trim($nomor);
    if ($nomor === '' || $showAuditDetail) {
        return $nomor;
    }

    // Alamat email, bukan nomor. Tanpa cabang ini preg_replace('/\D/') di
    // bawah membuang SELURUH alamat, lalu str_repeat('*', 0) mengembalikan
    // string kosong — jadi riwayat tidak kehilangan kodenya, ia kehilangan
    // keterangan bahwa kode itu pernah dikirim ke mana pun. Domain tetap utuh
    // supaya auditor bisa menilai tujuannya; nama lokalnya yang disembunyikan.
    if (strpos($nomor, '@') !== false) {
        $at     = strrpos($nomor, '@');
        $local  = substr($nomor, 0, $at);
        $domain = substr($nomor, $at);
        $head   = (strlen($local) <= 2) ? '' : substr($local, 0, 2);

        return $head . '***' . $domain;
    }

    $angka = preg_replace('/\D/', '', $nomor) ?? '';
    if (strlen($angka) <= 4) {
        return str_repeat('*', strlen($angka));
    }

    return str_repeat('*', strlen($angka) - 4) . substr($angka, -4);
};

foreach ($sigRows as $row) {
    $meta = json_decode((string) ($row['metadata'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];

    // Tantangan terverifikasi terakhir untuk slot ini, sebelum tanda tangannya.
    $nomorOtp = null;
    $kunciSlot = (int) ($row['document_id'] ?? 0) . '|' . (string) ($meta['ttd_id'] ?? '');
    foreach ($otpPerSlot[$kunciSlot] ?? [] as $o) {
        if (strcmp((string) $o['verified_at'], (string) ($row['signed_at'] ?? '')) <= 0) {
            $nomorOtp = (string) ($o['phone'] ?? '');
        }
    }

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
            'otp_phone'   => ($nomorOtp !== null && $nomorOtp !== '') ? $samarkanNomor($nomorOtp) : null,
        ],
    ];
}

// ─── Sumber 3: arsip tampilan ───
//
// Tiap tanda tangan menghasilkan satu siklus PDF beku. Yang sudah digantikan
// ikut ditampilkan: siklus 1 adalah jawaban atas "apa yang dilihat penanda
// tangan pertama", dan pertanyaan itu muncul justru saat ia sudah tidak berlaku.
//
// Dibaca dari tabel artefaknya langsung, bukan dari log audit — dengan begitu
// arsip yang dibuat sebelum pencatatan ini ada tetap terlihat, dan sumber
// kebenarannya satu.
try {
    $artRows = $db->fetchAll(
        'SELECT `document_id`, `cycle`, `frozen_at`, `frozen_by`, `superseded_at`, `manifest`
         FROM `ezdoc_document_artifacts`
         WHERE `document_id` IN (' . $in . ')',
        $ids
    );
} catch (\Throwable $e) {
    // Pemasangan yang belum memigrasikan tabel artefak.
    $artRows = [];
}

foreach ($artRows as $row) {
    $manifest = json_decode((string) ($row['manifest'] ?? ''), true);

    $entries[] = [
        'at'      => (string) ($row['frozen_at'] ?? ''),
        'event'   => 'doc.frozen',
        'version' => $versionOf[(int) ($row['document_id'] ?? 0)] ?? null,
        'actor'   => (string) ($row['frozen_by'] ?? ''),
        'roles'   => '',
        'ip'      => '',
        'result'  => ($row['superseded_at'] ?? null) !== null ? 'superseded' : 'ok',
        'message' => '',
        'detail'  => [
            'doc_id' => (int) ($row['document_id'] ?? 0),
            'cycle'  => (int) ($row['cycle'] ?? 0),
            'sealed' => is_array($manifest) && isset($manifest['seal']),
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

// ─── Nama pelaku ───
//
// Diselesaikan SETELAH pemotongan, jadi yang ditanyakan cuma baris yang benar-
// benar ditampilkan. Sekali tanya untuk semuanya: enam puluh baris tidak boleh
// jadi enam puluh query.
//
// Library tidak tahu di mana nama pengguna disimpan — itu urusan aplikasi
// pemakainya, lewat Ezdoc\Auth\ActorDirectory. Tanpa itu, tampilannya jatuh ke
// `#18` seperti sebelumnya.
$names = ezdoc_actor_names(array_column($entries, 'actor'));
if ($names !== []) {
    foreach ($entries as $i => $entry) {
        $actor = (int) $entry['actor'];
        if (isset($names[$actor])) {
            $entries[$i]['actor_name'] = $names[$actor];
        }
    }
}

// Field yang tidak boleh dilihat DIHILANGKAN, bukan dikosongkan. Kunci yang ada
// tapi bernilai '' tetap memberi tahu bahwa ada sesuatu di sana — dan mengundang
// pertanyaan berikutnya, yaitu apa isinya.
if (!$showAuditDetail) {
    foreach ($entries as $i => $entry) {
        unset($entries[$i]['ip'], $entries[$i]['roles']);
    }
    $entries = array_values($entries);
}

ezdoc_respond_success([
    'entries'   => $entries,
    // Dinyatakan, bukan dibiarkan tersirat. Tanpa penanda ini, klien tidak bisa
    // membedakan "server menahan field-nya" dari "server versi lama yang memang
    // belum pernah mengirimnya" — dan library ini dipasang orang lain, yang
    // versinya tidak selalu sejalan dengan tampilannya.
    //
    // Alasan yang lebih dekat: pertanyaan "kenapa kolom IP hilang?" bisa dijawab
    // dari respons, tanpa harus membaca PHP-nya.
    'audit_detail' => $showAuditDetail,
    // Dinyatakan, bukan dibiarkan tersirat. Daftar yang dipotong diam-diam
    // terbaca sebagai "cuma segini yang pernah terjadi".
    'truncated' => $truncated,
]);
