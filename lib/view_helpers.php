<?php

/**
 * Dua pembantu terpendek yang dipakai hampir setiap berkas tampilan.
 *
 * Keduanya sebelumnya dideklarasikan di dalam `views/document/generate.php`,
 * dengan penjaga `function_exists`. Itu bekerja selama hanya berkas itu yang
 * membutuhkannya — dan berhenti bekerja begitu perakitan PDF dipindah keluar
 * (lihat docs/260811-beku-pdf-tiap-tanda-tangan.md).
 *
 * Dipindah ke sini apa adanya, tanpa satu huruf pun berubah. Penjaga
 * `function_exists` di tempat lamanya jadi tidak pernah menyala, dan itu memang
 * yang diinginkan: satu definisi, bukan dua yang kebetulan sama hari ini.
 *
 * PHP 7.4+ compatible.
 */

// Setiap deklarasi di berkas ini dibungkus function_exists.
//
// Bukan supaya definisi lain boleh menang — tapi karena penjaga `return` di
// kepala berkas TIDAK BISA menolong di sini. Deklarasi fungsi tingkat-atas yang
// tak bersyarat diikat saat berkasnya dikompilasi, sebelum baris mana pun
// dijalankan; pemuatan kedua sudah menabrak "Cannot redeclare" sebelum penjaganya
// sempat berbicara. Yang di dalam `if` diikat saat dijalankan, jadi aman.
//
// lib/actor_names.php memakai penjaga `return` dan itu bekerja di sana karena
// setiap pemuatnya memakai require_once. Berkas ini juga dimuat lewat autoload
// `files` composer, dan asumsi itu tidak selalu dipegang pemanggil lain.

// Local helper — escape output (keeps existing h() call sites working)
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Local helper — translate a dot-notation i18n key (see lang/id/*.php).
// $default preserves the original Indonesian copy so a missing/mistyped key
// still displays correct text instead of a raw key or blank string.
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $default = null): string {
        $translator = $GLOBALS['translator'] ?? null;
        if (!($translator instanceof \Ezdoc\UI\Translator)) {
            return $default !== null ? $default : $key;
        }
        return $translator->t($key, $params, $default);
    }
}


/**
 * Gambar QR sebagai data URI, lewat satu pintu.
 *
 * ## Kenapa ada
 *
 * Sebelumnya kode view memanggil `generateQrForDompdf()` langsung — nama fungsi
 * global milik aplikasi pemakai, bukan milik library. Di `generate_qr.php` ia
 * dijaga `function_exists()`; di tiga tempat lain di view **tidak**.
 *
 * Yang membuatnya berbahaya bukan QR-nya. Perender isi PDF juga dipanggil jalur
 * pembekuan dokumen, dan aturannya: pembekuan gagal berarti tanda tangannya ikut
 * gagal. Satu fungsi host yang kebetulan tidak termuat berubah dari "QR tidak
 * tampil" jadi "tidak ada yang bisa menandatangani".
 *
 * ## Urutannya
 *
 * 1. {@see \Ezdoc\Rendering\QrRenderer} dari Context — jalur yang seharusnya
 * 2. `generateQrForDompdf()` kalau ada — jalur USANG, dipertahankan supaya
 *    pemasangan yang sudah terlanjur memakainya tidak rusak saat naik versi
 * 3. string kosong — QR-nya hilang, dokumennya tetap jadi
 *
 * Urutan dan alasannya sama persis dengan {@see ezdoc_actor_names()}.
 *
 * Tidak pernah melempar. Ini jalur tampilan: generator yang rusak boleh
 * menghilangkan QR-nya, bukan halamannya.
 */
if (!function_exists('ezdoc_qr_data_uri')) {
function ezdoc_qr_data_uri(string $data, int $size = 200, int $margin = 5): string
{
    if ($data === '') {
        return '';
    }

    try {
        $ctx = \Ezdoc\Context::default();
        if ($ctx->qr !== null) {
            return $ctx->qr->render($data, $size, $margin);
        }
    } catch (\Throwable $e) {
        // Context belum disetel, atau generatornya melempar. Coba jalur usang.
    }

    if (function_exists('generateQrForDompdf')) {
        try {
            return (string) generateQrForDompdf($data, $size, $margin);
        } catch (\Throwable $e) {
            return '';
        }
    }

    return '';
}
}


// ─── Penyaring nilai gambar, dipindah dari views/document/generate.php ───
//
// Verbatim, termasuk penjaga function_exists-nya. Alasannya sama dengan h()
// dan t() dulu: begitu perakitan PDF dipanggil dari luar view, deklarasi yang
// hanya hidup DI DALAM view berhenti ada — dan penjaga di sana membuat
// absennya tidak berisik. Jalur materai cuma diam, dan gambarnya hilang dari
// dokumen beku tanpa satu pun galat.

if (!function_exists('safeDataImg')) {
    /**
     * Aman untuk dipasang ke `<img src>`.
     *
     * Hanya data URI gambar. Dipakai di tempat yang benar-benar mengeluarkan
     * atribut src — BUKAN untuk menyaring nilai slot tanda tangan, lihat
     * safeSignatureValue() di bawah.
     */
    function safeDataImg(string $s): string {
        if ($s === '') return '';

        // Dijangkar di KEDUA ujung, dengan alfabet base64 yang eksplisit.
        // Sebelumnya hanya awalannya yang diperiksa, jadi apa pun boleh mengikuti
        // `base64,` — termasuk `AAA" onerror="alert(1)`. Keluarannya selalu lewat
        // h(), jadi itu bukan XSS yang hidup, tapi "sesuatu yang bukan gambar
        // diterima sebagai gambar" tetap tidak boleh berhenti pada satu lapis.
        return preg_match('#^data:image/(png|jpe?g|gif);base64,[A-Za-z0-9+/=\r\n]+$#', $s) ? $s : '';
    }
}

if (!function_exists('safeSignatureValue')) {
    /**
     * Nilai slot tanda tangan yang dikenali, atau string kosong.
     *
     * Dulu penyaringnya safeDataImg(), dan itu benar selama nilai slot cuma bisa
     * berupa gambar. Kosakatanya bertambah dua kali sejak itu — penanda mode QR
     * (`qr:v1`) dan referensi objek (`blob:sha256-…`) — dan penyaringnya tidak
     * ikut diperbarui. Keduanya bukan data URI, jadi keduanya **dihapus diam-diam
     * saat halaman dirender**.
     *
     * Akibatnya dua hal yang terlihat sangat berbeda tapi berakar sama:
     *
     *   1. Tanda tangan mode QR "selalu hilang". `qr:v1` tersimpan, lalu lenyap
     *      di render berikutnya, dan tombol "Tanda tangani (QR)" muncul lagi.
     *   2. Tombol QR: OFF/ON menolak dengan "tanda tangan tidak bisa dihapus".
     *      Input tersembunyinya kosong, jadi menyimpan tampilan terbaca sebagai
     *      MENGHAPUS tanda tangan yang sudah tercatat.
     *
     * Yang kedua justru bukti guard §7b bekerja: tanpanya, mengubah tampilan akan
     * menghapus tanda tangan sungguhan dan meninggalkan recordnya menunjuk
     * ketiadaan.
     *
     * Tetap daftar-putih, bukan "apa saja yang tidak kosong". Nilai ini masuk ke
     * atribut HTML dan ke `signature_values`; melonggarkannya jadi bebas berarti
     * apa pun yang bisa di-POST orang bisa mendarat di kedua tempat itu.
     */
    function safeSignatureValue(string $s): string {
        $s = trim($s);
        if ($s === '') return '';

        // Bentuk data URI-nya dipinjam dari SignatureImageStore, bukan ditulis
        // ulang: dua pemeriksa dengan pola berbeda untuk nilai yang sama akan
        // berpisah diam-diam, dan yang longgar jadi jalan masuknya.
        if (\Ezdoc\Storage\SignatureImageStore::isDataUri($s)) return $s;
        if ($s === \Ezdoc\Signature\SignatureValue::QR_ACT) return $s;
        if (\Ezdoc\Storage\Blob::isReference($s)) return $s;

        return '';
    }
}
