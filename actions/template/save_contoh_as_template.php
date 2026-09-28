<?php

/**
 * POST ajax=1 action=save_contoh, body: contoh=<nama-folder>, nama_template=<opsional>
 *
 * Simpan contoh yang sedang dilihat menjadi template sungguhan.
 *
 * ## Kenapa begini bentuknya
 *
 * Diminta pemakai: "aku ingin langsung bisa lihat hasil AI tanpa aksi, aksi ku
 * hanya lihat hasil aja, jika aku merasa cocok simpan ke template".
 *
 * Jadi rancangan template hidup sebagai BERKAS lebih dulu — bisa dibuka di
 * halaman Contoh, di halaman pembuatan dokumen, dan di designer tanpa
 * menyentuh database sama sekali. Yang menilai cukup melihat. Aksi satu-satunya
 * ada di sini: satu tombol, satu baris baru.
 *
 * ## Yang dijaga
 *
 * - **INSERT saja.** Tidak ada cabang UPDATE di berkas ini, jadi tidak ada
 *   template lama yang bisa tertimpa — sekalipun ada yang mengirim id.
 * - **Nama yang sudah dipakai ditolak**, bukan ditimpa dan bukan digandakan
 *   diam-diam. Yang menekan tombol memutuskan namanya.
 * - Hak yang sama dengan menyimpan template dari designer.
 * - Tercatat di log audit, dengan nama folder contohnya.
 *
 * Auth: template manager.
 *
 * Response: { success, id, nama, url_designer, url_generate }
 */

use Ezdoc\Context;
use Ezdoc\Template\Contoh;
use Ezdoc\Template\FloatingExtractor;

global $author_id;

ezdoc_require_manage_templates('Tidak berhak menyimpan template');

$slug = trim((string) ($_POST['contoh'] ?? ''));
// Kopnya ikut konfigurasi aplikasi ini, sama dengan yang dilihat di
// layar — kalau tidak, yang tersimpan berbeda dari yang dinilai.
$contoh = Contoh::muat($slug !== '' ? $slug : Contoh::BAWAAN, $config ?? null);
if ($contoh === null) {
    ezdoc_respond_error(t('response.contoh_not_found', [], 'Example not found'), 404);
}

$nama = trim((string) ($_POST['nama_template'] ?? ''));
if ($nama === '') {
    // Tanpa nama sendiri, dipakai nama contohnya — dikurangi kata "(contoh)",
    // karena yang tersimpan bukan contoh lagi.
    $nama = trim(preg_replace('/\s*\(contoh\)\s*$/iu', '', (string) $contoh['nama_template']));
}
if ($nama === '') {
    ezdoc_respond_error(t('response.template_name_required', [], 'Template name is required'));
}

$db = Context::default()->connection();

// Nama yang sudah dipakai ditolak. Menimpa berarti menghapus pekerjaan orang
// lain; menggandakan berarti dua template bernama sama, dan yang memilih dari
// daftar tidak punya cara membedakannya.
$sudahAda = $db->fetchOne(
    'SELECT id FROM ezdoc_templates WHERE name = ? AND deleted_at IS NULL LIMIT 1',
    [$nama]
);
if ($sudahAda) {
    ezdoc_respond_error(
        t('response.template_name_taken', ['nama' => $nama], 'A template named "{nama}" already exists — choose another name.'),
        409
    );
}

// Elemen mengapung (stempel, cap, gambar bebas) disimpan di kolomnya sendiri,
// sama seperti kalau template ini disimpan dari designer. Kalau penandanya
// dibiarkan tertanam di isi HTML, template hasil simpan berbeda bentuk dari
// template biasa — dan yang membukanya di designer melihat stempelnya dua kali.
$isi = (string) $contoh['template_html'];
$dipetik = FloatingExtractor::extract($isi);
$isi = $dipetik['html'];
$floating = FloatingExtractor::toJson($dipetik['floating']);
if ($floating === '[]') {
    $floating = null;
}

$uuid = ezdoc_uuid_v7();
$slugBaru = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($nama)), '_') ?: 'template';
$slugBaru = substr($slugBaru . '_' . substr(bin2hex(random_bytes(4)), 0, 8), 0, 120);
$ownerId = (int) ($author_id ?? 0) ?: null;

try {
    $db->execute(
        'INSERT INTO ezdoc_templates
         (uuid, slug, version, is_current, name, category, scope, content, floating_elements,
          signature_config, layout_config, verify_config, access_config,
          owner_id, is_active, is_locked)
         VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)',
        [
            $uuid,
            $slugBaru,
            $nama,
            (string) $contoh['category'],
            ((string) $contoh['doc_scope'] === 'general') ? 'general' : 'patient',
            $isi,
            $floating,
            (string) $contoh['config_ttd'],
            (string) $contoh['config_header'],
            $contoh['verify_config'] !== null ? (string) $contoh['verify_config'] : null,
            null,
            $ownerId,
        ]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error($e->getMessage());
}

$idBaru = (int) $db->lastInsertId();
if ($idBaru <= 0) {
    ezdoc_respond_error(t('response.template_save_failed', [], 'The template could not be saved'));
}

ezdoc_audit_log('template.created_from_contoh', [
    'target_type' => 'template',
    'target_id'   => (string) $idBaru,
    'template_id' => $idBaru,
    'metadata'    => [
        'contoh'   => (string) $contoh['slug'],
        'nama'     => $nama,
        'uuid'     => $uuid,
    ],
    'message' => "Template #{$idBaru} ({$nama}) dibuat dari contoh {$contoh['slug']}",
]);

ezdoc_respond_raw([
    'success' => true,
    'id'      => $idBaru,
    'nama'    => $nama,
    'message' => t('response.template_created_from_contoh', ['nama' => $nama], 'Saved as template "{nama}"'),
]);
