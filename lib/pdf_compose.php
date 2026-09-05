<?php

/**
 * Rakit dokumen HTML lengkap yang akan diserahkan ke mesin PDF.
 *
 * Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md §4 (Tahap 1).
 *
 * ## Kenapa dipindah keluar dari view
 *
 * Perakitan ini sebelumnya hidup di dalam `views/document/generate.php`, dan
 * karena itu hanya bisa dijalankan oleh permintaan yang membuka halaman cetak.
 * Membekukan dokumen saat tanda tangan masuk membutuhkan HTML yang sama, dari
 * tempat yang sama sekali berbeda.
 *
 * ## Yang dipindah, dan yang belum
 *
 * Yang pindah ke sini: **kerangka dokumennya** — CSS, `@page`, tiga titik
 * sisipan consumer, blok tanda tangan format lama, dan penutupnya.
 *
 * Yang belum: perenderan ISI (`renderContentForPdf()` beserta rantai fungsinya)
 * masih tinggal di view. Tapi sejak Tahap 1b ia tidak lagi membaca permintaan
 * yang sedang berjalan — nilainya diserahkan pemanggil, dan
 * {@see ezdoc_pdf_request_values()} di bawah yang merakitnya.
 * Isinya karena itu diterima sebagai masukan, sudah jadi.
 *
 * ## Aturan pemindahan ini
 *
 * **Dipindah apa adanya. Tidak ada yang dirapikan.** Termasuk komentar yang
 * panjang, penamaan yang tidak konsisten, dan spasi yang ganjil. Merapikan
 * sambil memindahkan membuat tidak ada yang bisa menjawab "kalau keluarannya
 * berubah, karena pemindahannya atau karena perapiannya".
 *
 * Buktinya: HTML yang dihasilkan harus **sama byte per byte** dengan sebelum
 * pemindahan, diperiksa atas dokumen sungguhan lewat `page/ezdoc_capture_pdf.php`.
 *
 * PHP 7.4+ compatible.
 */

require_once __DIR__ . '/view_helpers.php';

/**
 * @param array{
 *     template: array<string,mixed>,
 *     doc_id: int,
 *     paper: array{width: float|int, height: float|int},
 *     pad_top: float|int, pad_right: float|int,
 *     pad_bottom: float|int, pad_left: float|int,
 *     content: string,
 *     config_ttd: array<int,array<string,mixed>>,
 *     ttd_values: array<string,mixed>,
 *     pdf_fields: array<string,mixed>,
 *     has_ttd_placeholders: bool,
 *     conn?: mixed
 * } $in
 */
function ezdoc_compose_pdf_html(array $in): string
{
    $template            = $in['template'];
    $doc_id              = (int) $in['doc_id'];
    $paperDim            = $in['paper'];
    $padTop              = $in['pad_top'];
    $padRight            = $in['pad_right'];
    $padBottom           = $in['pad_bottom'];
    $padLeft             = $in['pad_left'];
    $pdfContent          = (string) $in['content'];
    $configTtd           = $in['config_ttd'];
    $ttdValues           = $in['ttd_values'];
    $pdfFields           = $in['pdf_fields'];
    $hasTtdPlaceholders  = (bool) $in['has_ttd_placeholders'];
    $conn                = $in['conn'] ?? null;

    // Build clean HTML for PDF (no toolbar, no scripts, no form elements)
    $pdfHtml = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>' . h($template['nama_template']) . '</title>
    <style>
        /* @page margin: reserves padT/padR/padB/padL di SETIAP physical page
           (CSS Paged Media Level 3 spec). dompdf paginator apply per-page.
           Sebelumnya .page{padding} approach cuma apply padding di element
           start+end (once), middle pages tidak dapat margin → content flush
           against physical edge.
           Fix: @page margin + .page padding=0 + .page shrunk ke printable area.
           Floating elements need translate compensation (see rule below). */
        @page {
            size: ' . $paperDim['width'] . 'mm ' . $paperDim['height'] . 'mm;
            margin: ' . $padTop . 'mm ' . $padRight . 'mm ' . $padBottom . 'mm ' . $padLeft . 'mm;
        }
        /* Global box-sizing only. Selective reset (body, .page margin/padding
           handled below). Blanket "* { margin: 0; padding: 0 }" reset removed —
           previously stripped ol/ul/li/p margins that we want per screen behavior. */
        * { box-sizing: border-box; }
        /* Font-family: "Times" first (native PDF core font, dompdf renders exact
           Times metrics). "Times New Roman" (browser name) + serif fallbacks.
           Sebelumnya "Times New Roman" first → dompdf tidak resolve → fallback
           DejaVu Sans (SANS-SERIF) → sangat beda visual dari screen. */
        body {
            font-family: "Times", "Times New Roman", serif;
            font-size: 12pt;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        /* Heading defaults — dompdf tidak apply browser default h1-h6 sizes/margins.
           Explicit sesuai browser standard (h1 2em, h2 1.5em, dst.) supaya render
           sesuai designer/screen. */
        h1, h2, h3, h4, h5, h6 { line-height: 1.25; page-break-after: avoid; }
        h1 { font-size: 2em; font-weight: bold; margin: 0.67em 0; }
        h2 { font-size: 1.5em; font-weight: bold; margin: 0.75em 0; }
        h3 { font-size: 1.17em; font-weight: bold; margin: 0.83em 0; }
        h4 { font-size: 1em; font-weight: bold; margin: 1.12em 0; }
        h5 { font-size: 0.83em; font-weight: bold; margin: 1.5em 0; }
        h6 { font-size: 0.75em; font-weight: bold; margin: 1.67em 0; }
        strong, b { font-weight: bold; }
        em, i { font-style: italic; }
        /* Inheritance safeguard — semua content dari .content, .page, body
           inherit line-height 1.6 kecuali override eksplisit (heading). */
        .page, .content { line-height: inherit; }
        /* .page shrunk ke printable area (paperW - padL - padR × paperH - padT -
           padB). @page margin di atas sudah reserve per-page margin. .page padding
           = 0 supaya no double margin. */
        .page {
            width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
            min-height: ' . ($paperDim['height'] - $padTop - $padBottom) . 'mm;
            margin: 0;
            padding: 0;
            position: relative;
        }
        /* Floating position compensation — @page margin shifts .page origin ke
           (padL, padT) di physical page coord. Floating stored di designer coord
           (from .page top-left = paper corner). Translate back ke visual paper
           corner position: -padL horizontal, -padT vertical. */
        .logo-floating, .ttd-item-floating, .qr-item-floating,
        .qr-behind, .qr-front, .materai-floating,
        .materai-behind, .materai-front {
            transform: translate(-' . $padLeft . 'mm, -' . $padTop . 'mm);
        }
        /* Content baseline — shared via Ezdoc\UI\ContentCss (single source of
           truth designer + generate + PDF). Historically these rules duplicated
           and drifted. Centralized now. */
        .content {
            line-height: 1.6;
            /* EXPLICIT WIDTH — dompdf tidak fully respect box-sizing: border-box
               untuk padding calc. Bypass dgn set .content width eksplisit
               = paper width - horizontal padding. Ensures text wraps at exact
               same width as designer (170mm untuk A4 dgn 20mm padding). */
            width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
            max-width: ' . ($paperDim['width'] - $padLeft - $padRight) . 'mm;
        }
        /* Semua descendant `.content` inherit overflow protection. */
        .content * { max-width: 100%; }
        ' . \Ezdoc\UI\ContentCss::render() . '
        /* Logo images inline-block (max-width sudah declared di .content img rule
           di block sebelumnya). */
        .content .logo-img { display: inline-block; }

        /* Field values - just show text */
        .f-wrap { display: inline; }
        .f, .f-pdf {
            font-family: inherit;
            font-size: inherit;
            font-weight: inherit;
            font-style: inherit;
            color: inherit;
            line-height: inherit;
            display: inline;
        }

        /* Logo */
        .logo-img { height: auto; vertical-align: middle; }
        .logo-floating { position: absolute; }
        .logo-behind { z-index: -1; }
        .logo-front { z-index: 100; }
        .logo-empty { display: none; }

        /* TTD — sync dgn screen generate rules exactly */
        .ttd-item-inline {
            display: inline-block;
            text-align: center;
            min-width: 120px;
            vertical-align: top;
            margin: 5px;
        }
        .ttd-item-floating {
            position: absolute;
            text-align: center;
            min-width: 120px;
        }
        .ttd-behind { z-index: -1; }
        .ttd-front { z-index: 100; }
        .ttd-label { font-size: 12pt; margin-bottom: 5px; }
        .ttd-img { min-height: 50px; text-align: center; }
        .ttd-signature { max-height: 60px; max-width: 120px; }
        .ttd-name { font-size: 11pt; margin-top: 3px; }
        .ttd-canvas-placeholder { display: none; }
        .ttd-actions { display: none; }

        /* TTD wrap for old format */
        .ttd-wrap { margin-top: 40px; text-align: center; }
        .ttd-item { display: inline-block; min-width: 130px; text-align: center; margin: 0 20px; vertical-align: top; }

        /* QR Code */
        .qr-item-inline { display: inline-block; text-align: center; vertical-align: top; }
        .qr-item-floating { position: absolute; text-align: center; }
        .qr-behind { z-index: -1; }
        .qr-front { z-index: 100; }
        .qr-preview { text-align: center; }
        .qr-img { max-width: 100%; height: auto; }
        .qr-input { display: none; }
        .qr-canvas-placeholder { display: none; }

        /* Dynamic Tables */
        .dyntable-rendered { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        .dyntable-rendered th, .dyntable-rendered td { padding: 4px 8px; vertical-align: top; }
        .dyntable-rendered th { font-weight: bold; }
        .dyntable-rendered thead { display: table-header-group; }
        .dyntable-rendered tr { page-break-inside: avoid; }
        .dyntable-empty { color: #999; font-style: italic; text-align: center; padding: 10px; }
    </style>';
    // Slot: pdf-head-extra — consumer may inject additional CSS, @page rules, embedded fonts, or PDF metadata tags
    // spec: ezdoc-spec/slots/generate.md#pdf-head-extra
    $pdfHtml .= \Ezdoc\UI\Slot::render('generate:pdf-head-extra', [
        'template' => $template,
        'doc_id'   => (int)$doc_id,
        'paper'    => $paperDim,
    ]);
    $pdfHtml .= '
</head>
<body>
    <div class="page">';
    // Slot: pdf-body-start — top of every rendered PDF page (letterhead, security banner, top stamp/QR)
    // spec: ezdoc-spec/slots/generate.md#pdf-body-start
    $pdfHtml .= \Ezdoc\UI\Slot::render('generate:pdf-body-start', [
        'template' => $template,
        'doc_id'   => (int)$doc_id,
    ]);
    $pdfHtml .= '
        <div class="content">';

    $pdfHtml .= $pdfContent;

    $pdfHtml .= '</div>';

    // Old format TTD section
    if (!$hasTtdPlaceholders && !empty($configTtd)) {
        $pdfHtml .= '<div class="ttd-wrap">';
        foreach ($configTtd as $ttd) {
            $ttdId = $ttd['id'];
            $label = h($ttd['label'] ?? t('fallback.signature', [], 'Signature'));
            $namaField = $ttd['nama_field'] ?? 'nama_' . $ttdId;
            $namaValue = h($pdfFields[$namaField] ?? '');
            $ttdImg = $ttdValues[$ttdId] ?? '';

            $pdfHtml .= '<div class="ttd-item">';
            $pdfHtml .= '<div class="ttd-label">' . $label . '</div>';
            $pdfHtml .= '<div class="ttd-img">';
            // Byte-nya dibuka inline: dompdf tidak membawa sesi, jadi URL yang
            // dilindungi kontrol akses tidak akan bisa diambilnya.
            $ttdSrcPdf = ezdoc_resolve_signature_image($conn ?? null, (string) $ttdImg);
            if ($ttdSrcPdf !== '') {
                $pdfHtml .= '<img src="' . h($ttdSrcPdf) . '" class="ttd-signature" alt="TTD">';
            } else {
                $pdfHtml .= '<div style="height:50px;"></div>';
            }
            $pdfHtml .= '</div>';
            $pdfHtml .= '<div class="ttd-name">(' . ($namaValue ?: '..................') . ')</div>';
            $pdfHtml .= '</div>';
        }
        $pdfHtml .= '</div>';
    }

    // Slot: pdf-body-end — bottom of every rendered PDF (page number, disclaimer, verify footer, QR verify text)
    // spec: ezdoc-spec/slots/generate.md#pdf-body-end
    $pdfHtml .= \Ezdoc\UI\Slot::render('generate:pdf-body-end', [
        'template' => $template,
        'doc_id'   => (int)$doc_id,
    ]);
    $pdfHtml .= '</div></body></html>';

    return $pdfHtml;
}

/**
 * Nilai-nilai dari permintaan HTTP, dirakit jadi satu peta datar.
 *
 * Aturannya sama persis dengan yang dulu tertanam di `renderContentForPdf()`:
 * telusuri sumber sesuai urutan, ambil yang PERTAMA tidak kosong. Yang kosong
 * dilewati, bukan menang — `?nama=` di URL tidak boleh menghapus nilai yang
 * sudah ada di `$_POST`.
 *
 * Dipakai pemanggil, bukan oleh perendernya. Itu intinya: perender bukti tidak
 * boleh punya pendapat tentang permintaan mana yang sedang berjalan.
 *
 * @param array<array-key,mixed> ...$sumber urutan menentukan; yang lebih dulu menang
 * @return array<array-key,mixed>
 */
function ezdoc_pdf_request_values(array ...$sumber): array
{
    $hasil = [];

    foreach ($sumber as $satu) {
        foreach ($satu as $kunci => $nilai) {
            if ($nilai === '' || array_key_exists($kunci, $hasil)) {
                continue;
            }
            $hasil[$kunci] = $nilai;
        }
    }

    return $hasil;
}
