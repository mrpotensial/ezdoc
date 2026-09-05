<?php

declare(strict_types=1);

namespace Ezdoc\Rendering;

/**
 * QR renderer contract — consumer app menyuntikkan backend QR-nya sendiri lewat
 * `Context::withQr()`.
 *
 * ## Kenapa antarmuka, bukan implementasi bawaan
 *
 * Sama alasannya dengan {@see PdfRenderer}: menghasilkan QR butuh pustaka, dan
 * memaksakan satu pilihan ke setiap pemasangan bukan urusan library dokumen.
 * Host yang sudah punya generatornya sendiri tinggal membungkusnya.
 *
 * ## Kenapa ini ada
 *
 * Sebelumnya kode view memanggil nama fungsi global `generateQrForDompdf()`
 * langsung — fungsi yang didefinisikan aplikasi pemakai, bukan library. Di satu
 * tempat ia dijaga `function_exists()`; di tiga tempat lain **tidak**.
 *
 * Yang membuatnya berbahaya bukan QR-nya. Perender isi PDF dipanggil juga oleh
 * jalur pembekuan dokumen, dan aturannya: pembekuan gagal berarti tanda
 * tangannya ikut gagal. Jadi satu fungsi host yang kebetulan tidak termuat
 * berubah dari "QR tidak tampil" menjadi "tidak ada yang bisa menandatangani".
 *
 * Sekarang absennya generator berarti QR-nya dilewati dengan bersih, dan sisa
 * dokumennya tetap jadi.
 */
interface QrRenderer
{
    /**
     * Hasilkan gambar QR sebagai data URI, siap dipasang di `<img src="...">`.
     *
     * Data URI, bukan berkas atau URL: keluarannya masuk ke HTML yang dirender
     * jadi PDF, dan PDF yang menunjuk berkas luar berhenti bisa dibuktikan
     * begitu berkas itu pindah. Yang tertanam ikut terbawa selamanya.
     *
     * @param string $data   Isi yang dikodekan — URL verifikasi, nomor, teks apa pun
     * @param int    $size   Panjang sisi dalam piksel
     * @param int    $margin Zona sunyi, dalam satuan modul QR
     * @return string Data URI (mis. `data:image/png;base64,...`), atau string
     *                kosong kalau QR-nya tidak bisa dibuat. String kosong, bukan
     *                pengecualian: ini jalur tampilan, dan generator yang rusak
     *                boleh menghilangkan QR-nya — bukan dokumennya.
     */
    public function render(string $data, int $size = 200, int $margin = 5): string;
}
