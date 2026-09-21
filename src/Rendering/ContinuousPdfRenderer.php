<?php

declare(strict_types=1);

namespace Ezdoc\Rendering;

/**
 * Kemampuan TAMBAHAN perender PDF: satu halaman setinggi isinya.
 *
 * Dipakai template yang tata letaknya `continuous`. Dokumen seperti itu disimpan
 * dan dibaca secara digital, dan memotongnya per lembar A4 cuma menambahkan batas
 * yang tidak ada di layar.
 *
 * ## Kenapa antarmuka terpisah, bukan metode baru di PdfRenderer
 *
 * Menambah metode ke PdfRenderer akan mematahkan setiap perender yang sudah
 * disuntik host lewat Context::withPdf(). Kemampuan ini justru opsional: perender
 * yang tidak mengimplementasikannya tetap dipakai, dan dokumennya dirender per
 * halaman seperti biasa.
 *
 * ## Kenapa bisa gagal
 *
 * Tinggi halaman PDF punya batas praktis — Acrobat berhenti di 200 inci
 * (±5,08 m). Dokumen yang lebih panjang dari itu tidak bisa jadi satu halaman,
 * dan implementasinya mengembalikan null supaya pemanggil jatuh ke per halaman.
 */
interface ContinuousPdfRenderer
{
    /**
     * @param string $html           Dokumen HTML lengkap
     * @param float  $widthMm        Lebar kertas, sudah memperhitungkan orientasi
     * @param float  $marginBottomMm Margin bawah yang ditambahkan di bawah isi
     * @param float  $minHeightMm    Tinggi paling sedikit — biasanya tinggi kertas
     *                               templatenya. Dokumen yang muat selembar tetap
     *                               berukuran persis selembar, bukan pita sempit.
     * @return string|null Byte PDF satu halaman, atau null kalau isinya terlalu
     *                     panjang untuk satu halaman
     */
    public function renderContinuous(string $html, float $widthMm, float $marginBottomMm, float $minHeightMm = 0.0): ?string;
}
