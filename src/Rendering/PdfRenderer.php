<?php

declare(strict_types=1);

namespace Ezdoc\Rendering;

/**
 * PDF renderer contract — consumer app can inject any PDF rendering backend
 * (dompdf, mPDF, wkhtmltopdf, Weasyprint) via Context::withPdf() atau
 * dynamic property `$ctx->pdf`.
 *
 * Default implementation: {@see DompdfRenderer} (zero-dep, dompdf composer).
 *
 * spec: docs/PDF-RENDERING.md
 */
interface PdfRenderer
{
    /**
     * Hasilkan byte PDF-nya, tanpa mengirim apa pun ke browser.
     *
     * Ini yang dibutuhkan setiap kali PDF-nya harus disimpan alih-alih
     * ditampilkan: pembekuan dokumen, penyegelan dengan sertifikat, lampiran
     * surel, dan pengiriman ke penyedia tanda tangan.
     *
     * Dulu antarmuka ini hanya punya {@see stream()}, dan itu membuat
     * pembekuan mustahil — byte-nya sudah terlanjur pergi ke browser sebelum
     * ada yang bisa memegangnya. Bentuk dua metode ini mengikuti mesinnya
     * sendiri: dompdf punya `output()` dan `stream()`, mPDF punya
     * `Output()` dengan tujuan `STRING_RETURN`.
     *
     * @param string             $html        Dokumen HTML lengkap
     * @param array<int,float|int> $paperMm   `[lebar, tinggi]` dalam milimeter
     * @param string             $orientation `'portrait'` | `'landscape'`
     * @return string Byte PDF mentah
     */
    public function render(string $html, array $paperMm, string $orientation): string;

    /**
     * Stream/inline PDF response ke browser dari HTML input.
     *
     * Sends HTTP headers (Content-Type: application/pdf) + PDF binary body.
     * Does NOT call exit — caller responsible for terminating request.
     *
     * @param string $html         Full HTML document (dgn `<!doctype>`, `<html>`, `<head>`, `<body>`)
     * @param string $filename     Suggested filename (e.g. "document_123.pdf")
     * @param array  $paperMm      `[width, height]` in millimeters (e.g. `[210, 297]` for A4 portrait)
     * @param string $orientation  `'portrait'` | `'landscape'`
     */
    public function stream(string $html, string $filename, array $paperMm, string $orientation): void;
}
