<?php

/**
 * POST ajax=1 action=save_kop
 *   body: nama, alamat (satu baris per baris teks), kota,
 *         logo (berkas gambar, opsional), hapus_logo=1, reset=1
 *
 * Simpan kop surat yang dipakai contoh template.
 *
 * ## Kenapa di sini, bukan di berkas config
 *
 * Diminta pemakai: "maksudku konfigurasi di halaman example, karena tiap
 * consumer kan beda beda". Nilai di berkas mount tetap berlaku sebagai nilai
 * awal — pemasangan baru tidak perlu mengisi apa pun dulu — dan yang diketik di
 * halaman contoh menimpanya.
 *
 * ## Yang dijaga
 *
 * - **Tidak menyentuh basis data.** Kop adalah pengaturan satu pemasangan,
 *   bukan data dokumen: ia tinggal sebagai satu berkas JSON di folder
 *   penyimpanan. Tidak ada tabel baru, dan mengembalikannya ke semula cukup
 *   dengan menghapus berkas itu.
 * - **Kolom yang dikosongkan kembali ke nilai config**, bukan jadi kosong.
 * - Logo dikecilkan lewat jalur yang sama dengan pemuat contoh, jadi tidak ada
 *   dua cara mengecilkan gambar yang bisa berbeda hasilnya.
 *
 * Auth: template manager.
 *
 * Response: { success, message, kop }
 */

use Ezdoc\Template\Contoh;

ezdoc_require_manage_templates('Tidak berhak mengatur kop contoh');

if (Contoh::berkasKop() === null) {
    ezdoc_respond_error(
        t('response.kop_no_storage', [], 'This installation has no storage folder, so the letterhead cannot be saved from here.'),
        409
    );
}

// ── Kembalikan ke bawaan ─────────────────────────────────────────────────
if (($_POST['reset'] ?? '') === '1') {
    if (!Contoh::simpanKop([])) {
        ezdoc_respond_error(t('response.kop_save_failed', [], 'The letterhead could not be saved'));
    }

    ezdoc_audit_log('contoh.letterhead_reset', [
        'target_type' => 'setting',
        'target_id'   => 'letterhead',
        'message'     => 'Kop contoh dikembalikan ke bawaan',
    ]);

    ezdoc_respond_raw([
        'success' => true,
        'kop'     => Contoh::kopTersimpan(),
        'message' => t('response.kop_reset', [], 'Letterhead returned to its default'),
    ]);
}

$lama = Contoh::kopTersimpan();
$kop = [];

$nama = trim((string) ($_POST['nama' ] ?? ''));
if (mb_strlen($nama) > 200) {
    $nama = mb_substr($nama, 0, 200);
}
if ($nama !== '') {
    $kop['name'] = $nama;
}

// Satu baris teks = satu baris di kop. Lebih dari empat baris bukan kop lagi.
$alamat = [];
foreach (preg_split('/\r\n|\r|\n/', (string) ($_POST['alamat'] ?? '')) as $baris) {
    $baris = trim((string) $baris);
    if ($baris === '') {
        continue;
    }
    $alamat[] = mb_strlen($baris) > 300 ? mb_substr($baris, 0, 300) : $baris;
    if (count($alamat) >= 4) {
        break;
    }
}
if ($alamat !== []) {
    $kop['address'] = $alamat;
}

$kota = trim((string) ($_POST['kota'] ?? ''));
if (mb_strlen($kota) > 100) {
    $kota = mb_substr($kota, 0, 100);
}
if ($kota !== '') {
    $kop['city'] = $kota;
}

// ── Logo ─────────────────────────────────────────────────────────────────
//
// Tanpa berkas baru, logo yang sudah tersimpan dipertahankan: yang mengubah
// alamat tidak sedang meminta logonya hilang.
$logoLama = (string) ($lama['logo'] ?? '');
$hapusLogo = ($_POST['hapus_logo'] ?? '') === '1';

$unggah = $_FILES['logo'] ?? null;
if (is_array($unggah) && (int) ($unggah['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $sementara = (string) ($unggah['tmp_name'] ?? '');
    if (!is_uploaded_file($sementara)) {
        ezdoc_respond_error(t('response.kop_logo_invalid', [], 'That file could not be read as an image'));
    }
    if ((int) ($unggah['size'] ?? 0) > 5 * 1024 * 1024) {
        ezdoc_respond_error(t('response.kop_logo_too_big', [], 'The logo is larger than 5 MB'));
    }

    $ukuran = @getimagesize($sementara);
    if (!is_array($ukuran) || (int) $ukuran[0] <= 0) {
        ezdoc_respond_error(t('response.kop_logo_invalid', [], 'That file could not be read as an image'));
    }

    // Jalur yang sama dengan pemuat contoh: ia yang mengecilkan dan mengubahnya
    // jadi data URI. Nama sementara tanpa ekstensi ditolak pembaca jenisnya,
    // jadi disalin dulu dengan ekstensi yang sesuai.
    $ext = image_type_to_extension((int) $ukuran[2], false);
    $bersama = $sementara . '.' . ($ext !== '' ? $ext : 'png');
    if (!@copy($sementara, $bersama)) {
        ezdoc_respond_error(t('response.kop_logo_invalid', [], 'That file could not be read as an image'));
    }
    $logo = Contoh::logoKop($bersama);
    @unlink($bersama);

    if ($logo === '') {
        ezdoc_respond_error(t('response.kop_logo_invalid', [], 'That file could not be read as an image'));
    }
    $kop['logo'] = $logo;
} elseif (!$hapusLogo && $logoLama !== '') {
    $kop['logo'] = $logoLama;
}

if (!Contoh::simpanKop($kop)) {
    ezdoc_respond_error(t('response.kop_save_failed', [], 'The letterhead could not be saved'));
}

ezdoc_audit_log('contoh.letterhead_saved', [
    'target_type' => 'setting',
    'target_id'   => 'letterhead',
    'metadata'    => [
        'nama'      => $kop['name'] ?? '',
        'kota'      => $kop['city'] ?? '',
        'baris'     => count($alamat),
        'ada_logo'  => isset($kop['logo']),
    ],
    'message' => 'Kop contoh disimpan',
]);

ezdoc_respond_raw([
    'success' => true,
    'kop'     => $kop,
    'message' => t('response.kop_saved', [], 'Letterhead saved'),
]);
