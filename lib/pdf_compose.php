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
<html lang="' . h(function_exists('ezdoc_document_locale') ? ezdoc_document_locale() : 'en') . '">
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
            ' . (!empty($in['continuous'])
                // Mode continuous: tanpa `size`. Di dompdf, `@page size` menang atas
                // setPaper(), dan tinggi halamannya justru ditentukan perender
                // sesudah ia mengukur isinya.
                ? ''
                : 'size: ' . $paperDim['width'] . 'mm ' . $paperDim['height'] . 'mm;') . '
            margin: ' . $padTop . 'mm ' . $padRight . 'mm ' . $padBottom . 'mm ' . $padLeft . 'mm;
        }' . (!empty($in['continuous']) ? '
        /* Mode continuous: satu halaman, jadi tidak ada pemisah halaman sama sekali.
           Pemisah paksa dari template akan membuat halaman kedua. */
        * { page-break-before: auto !important; page-break-after: auto !important;
            break-before: auto !important; break-after: auto !important; }' : '') . '
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

// ─────────────────────────────────────────────────────────────────────────
// Perenderan ISI, dipindah dari views/document/generate.php (Tahap 2a).
//
// Verbatim: teksnya apa adanya, termasuk docblock masing-masing. Yang berubah
// cuma tempatnya — pembuktiannya membandingkan keluaran sebelum dan sesudah,
// dan itu hanya berarti kalau tidak ada satu huruf pun yang ikut berubah.
//
// Kenapa dipindah: jalur pembekuan dokumen memanggil perender yang sama dari
// save_document.php, dan selama fungsi-fungsi ini tinggal di dalam view,
// memanggilnya berarti menjalankan seluruh halamannya.
// Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md §4.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Execute a dynamic table query with parameter binding.
 * Parameters like {nopen}, {norm}, {field_name} are replaced with prepared statement placeholders.
 */
function executeDynQuery($query, $param_norm, $param_nopen, $dbFields, $conn) {
    // Tanpa sambungan database tidak ada baris yang bisa diambil.
    //
    // Dulu ini bersandar pada `@mysqli_prepare()` yang mengembalikan false
    // untuk sambungan kosong. Sejak PHP 8 argumen bertipe salah melempar
    // TypeError, dan `@` tidak menahan lemparan — pemanggil yang tidak punya
    // sambungan (pratinjau, perakitan di luar permintaan HTTP) mati total
    // alih-alih menghasilkan tabel kosong.
    if (!($conn instanceof \mysqli)) {
        return [];
    }

    // Validate SELECT only
    $normalized = preg_replace('/\s+/', ' ', strtoupper(ltrim($query)));
    if (strpos($normalized, 'SELECT') !== 0 && strpos($normalized, 'WITH') !== 0) {
        return [];
    }
    $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE'];
    foreach ($blocked as $kw) {
        if (strpos($normalized, $kw) === 0) return [];
    }

    // Extract {param} placeholders and their order
    $paramNames = [];
    $paramValues = [];
    $paramTypes = '';

    $safeQuery = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function($m) use (&$paramNames) {
        $paramNames[] = $m[1];
        return '?';
    }, $query);

    // Map param names to values
    foreach ($paramNames as $name) {
        $val = '';
        if ($name === 'nopen') $val = $param_nopen;
        elseif ($name === 'norm') $val = $param_norm;
        else $val = $dbFields[$name] ?? '';
        $paramValues[] = $val;
        $paramTypes .= 's';
    }

    $stmt = @mysqli_prepare($conn, $safeQuery);
    if (!$stmt) return [];

    if (!empty($paramValues)) {
        mysqli_stmt_bind_param($stmt, $paramTypes, ...$paramValues);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Process `{{tabledb.<ns>.<selector>[|<format>[:<args>]]}}` references inside <tr> rows.
 *
 * Enhanced marker syntax (v0.9.14b) — user bebas design table di TinyMCE,
 * marker mendukung format/formula/aggregate directly di cells:
 *
 * ## Selector variants
 *
 * - `col_name`              — raw column value dari row
 * - `=<expression>`         — computed formula dgn bare column names, e.g. `=qty*harga`
 * - `sum:<col_or_formula>`  — SUM aggregate across all rows
 * - `avg:<col_or_formula>`  — AVG aggregate
 * - `count:<col_or_star>`   — COUNT of non-null (use `*` untuk count all rows)
 * - `min:<col_or_formula>`  — MIN
 * - `max:<col_or_formula>`  — MAX
 *
 * ## Format modifiers (|)
 *
 * - `|text`                 — HTML escape (default)
 * - `|number[:decimals]`    — 1500 → 1.500 (id) or 1,500 (en)
 * - `|currency[:code]`      — 1500000 → Rp 1.500.000 (default IDR)
 * - `|percent[:decimals]`   — 0.5 → 50,00% (as-ratio default)
 * - `|date[:format]`        — 2026-07-24 → 24/07/2026 (default d/m/Y)
 * - `|datetime[:format]`    — default d/m/Y H:i
 *
 * ## Row classification
 *
 * - Row dgn raw col OR formula → **cloned per data row** (existing behavior)
 * - Row dgn HANYA aggregate markers → **rendered once** (footer-style)
 * - Row mixed → treated as clone (aggregate evaluated with row context — rarely useful)
 *
 * ## Examples
 *
 * ```html
 * <table>
 *   <thead><tr><th>Item</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
 *   <tbody>
 *     <tr>
 *       <td>{{tabledb.stok.nama}}</td>
 *       <td>{{tabledb.stok.qty}}</td>
 *       <td>{{tabledb.stok.harga|currency}}</td>
 *       <td>{{tabledb.stok.=qty*harga|currency}}</td>
 *     </tr>
 *   </tbody>
 *   <tfoot>
 *     <tr>
 *       <td colspan="3"><strong>Total</strong></td>
 *       <td>{{tabledb.stok.sum:=qty*harga|currency}}</td>
 *     </tr>
 *   </tfoot>
 * </table>
 * ```
 *
 * ## Backward compatibility
 *
 * Old marker `{{tabledb.ns.col}}` (no format) tetap works — no format pipe =
 * text (HTML escape). Same behavior as pre-v0.9.14b.
 *
 * spec: docs/DYNAMIC-TABLES.md
 */
function processTabledbRows($html, $tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn) {
    if (!is_array($tableDbQueries) || empty($tableDbQueries)) return $html;

    // Cache: run each namespace's query at most once per template render
    $resultCache = [];

    // Regex captures: (1) namespace optional, (2) selector, (3) format pipe optional
    // Selector allows: word chars, `=`, `*`, `:` (for verb:field), operators inside formulas
    // Selector allows spaces (untuk formulas seperti `=qty * harga`); trim di parser.
    $markerRegex = '/\{\{tabledb(?:\.([a-zA-Z_]\w*))?\.([^|}]+?)(?:\|([^}]+))?\}\}/';

    return preg_replace_callback('/<tr\b[^>]*>[\s\S]*?<\/tr>/i', function ($m) use ($tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn, &$resultCache, $markerRegex) {
        $rowHtml = $m[0];

        if (!preg_match_all($markerRegex, $rowHtml, $refs, PREG_SET_ORDER)) {
            return $rowHtml; // no tabledb markers
        }

        // Namespace dari first marker (assume all refs di 1 row share ns)
        $ns = $refs[0][1] !== '' ? $refs[0][1] : 'default';
        $cfg = $tableDbQueries[$ns] ?? null;
        if (!$cfg || empty($cfg['sql'])) {
            return preg_replace($markerRegex, '—', $rowHtml);
        }

        // Execute query (cached per namespace)
        if (!array_key_exists($ns, $resultCache)) {
            $resultCache[$ns] = executeDynQuery($cfg['sql'], $param_norm, $param_nopen, $dbFields, $conn);
        }
        $rows = $resultCache[$ns];

        // Classify row: aggregate-only, clone (per-row), atau mixed
        $rowType = _tabledbClassifyRow($refs);

        // Empty result: kalau aggregate row → tetap render dgn aggregate=0 or —,
        // kalau clone row → keep 1 row w/ placeholders replaced by —
        if (empty($rows)) {
            if ($rowType === 'aggregate') {
                return _tabledbRenderRow($rowHtml, $markerRegex, [], null, $rows);
            }
            return preg_replace($markerRegex, '—', $rowHtml);
        }

        // Aggregate row → render ONCE (footer-style)
        if ($rowType === 'aggregate') {
            return _tabledbRenderRow($rowHtml, $markerRegex, [], null, $rows);
        }

        // Clone-per-row: render each data row with its context
        $output = '';
        foreach ($rows as $dataRow) {
            $output .= _tabledbRenderRow($rowHtml, $markerRegex, $dataRow, $dataRow, $rows);
        }
        return $output;
    }, $html);
}

/**
 * Classify a row's markers: 'aggregate' (only sum/avg/count/min/max markers),
 * 'clone' (has at least 1 raw col or formula), atau 'mixed' (treated as clone).
 *
 * @param array $refs preg_match_all matches (each: [full, ns, selector, format])
 * @return string 'aggregate'|'clone'
 */
function _tabledbClassifyRow(array $refs): string {
    $hasPerRow = false;
    $hasAggregate = false;
    foreach ($refs as $ref) {
        $parsed = _tabledbParseSelector($ref[2]);
        if ($parsed['type'] === 'aggregate') $hasAggregate = true;
        elseif ($parsed['type'] === 'raw' || $parsed['type'] === 'formula') $hasPerRow = true;
    }
    if ($hasAggregate && !$hasPerRow) return 'aggregate';
    return 'clone'; // includes mixed (aggregate + per-row — evaluated per-row)
}

/**
 * Parse selector string. Returns ['type' => 'raw'|'formula'|'aggregate', ...].
 *
 * @param string $sel
 * @return array
 */
function _tabledbParseSelector(string $sel): array {
    $sel = trim($sel);
    if ($sel === '') return ['type' => 'invalid'];
    // Formula: starts with `=`
    if ($sel[0] === '=') {
        return ['type' => 'formula', 'formula' => trim(substr($sel, 1))];
    }
    // Aggregate: sum:field, avg:field, count:*, count:field, min:formula, max:formula
    if (preg_match('/^(sum|avg|count|min|max):(.+)$/i', $sel, $m)) {
        return ['type' => 'aggregate', 'verb' => strtolower($m[1]), 'target' => $m[2]];
    }
    // Raw column: plain identifier
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $sel)) {
        return ['type' => 'raw', 'field' => $sel];
    }
    return ['type' => 'invalid'];
}

/**
 * Parse format pipe: 'currency' → ['type' => 'currency', 'args' => []],
 * 'currency:USD:2' → ['type' => 'currency', 'args' => ['USD', '2']].
 *
 * @param string|null $fmt
 * @return array
 */
function _tabledbParseFormat(?string $fmt): array {
    if ($fmt === null || $fmt === '') {
        return ['type' => 'text', 'args' => []];
    }
    $parts = explode(':', $fmt);
    return ['type' => strtolower(trim($parts[0])), 'args' => array_slice($parts, 1)];
}

/**
 * Render one row instance — substitute markers dgn computed/formatted values.
 *
 * @param string      $rowHtml     original <tr>...</tr> HTML w/ markers
 * @param string      $markerRegex regex to match tabledb markers
 * @param array       $rowContext  row data untuk per-row evaluation (empty untuk aggregate row)
 * @param array|null  $rowRaw      raw row data (for direct field access without formula eval)
 * @param array       $allRows     all rows (needed untuk aggregate computation)
 * @return string
 */
function _tabledbRenderRow(string $rowHtml, string $markerRegex, array $rowContext, ?array $rowRaw, array $allRows): string {
    return preg_replace_callback($markerRegex, function ($m) use ($rowContext, $rowRaw, $allRows) {
        $selector = _tabledbParseSelector($m[2]);
        $format = _tabledbParseFormat($m[3] ?? null);

        // Compute raw value based on selector type
        $rawValue = null;
        $nullReason = ''; // untuk hover title kalau result null
        if ($selector['type'] === 'raw') {
            $rawValue = $rowRaw[$selector['field']] ?? null;
            if ($rawValue === null && $rowRaw !== null) {
                $available = array_keys($rowRaw);
                $nullReason = 'Kolom "' . $selector['field'] . '" tidak ada di SQL rows. '
                    . 'Kolom tersedia: ' . implode(', ', $available);
            }
        } elseif ($selector['type'] === 'formula') {
            try {
                $rawValue = \Ezdoc\Table\FormulaEvaluator::evaluateBare($selector['formula'], $rowContext);
            } catch (\Ezdoc\Exceptions\ValidationException $e) {
                return '<span style="color:#c00;" title="Formula error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</span>';
            }
            if ($rawValue === null) {
                $available = is_array($rowContext) ? implode(', ', array_keys($rowContext)) : '(no row)';
                $nullReason = 'Formula "' . $selector['formula'] . '" return null (kolom missing/non-numeric/div-by-zero). '
                    . 'Kolom row: ' . $available;
            }
        } elseif ($selector['type'] === 'aggregate') {
            $rawValue = _tabledbComputeAggregate($selector['verb'], $selector['target'], $allRows);
            if ($rawValue === null) {
                $target = $selector['target'];
                // Match auto-detection logic di _tabledbComputeAggregate
                $isFormula = ($target !== '' && $target[0] === '=')
                    || preg_match('/[+\-*\/()]|\s/', $target);
                $available = !empty($allRows) && is_array($allRows[0]) ? implode(', ', array_keys($allRows[0])) : '(no rows)';
                if (empty($allRows)) {
                    $nullReason = 'Aggregate: 0 rows dari SQL (query returned empty result set).';
                } elseif ($isFormula) {
                    $exprShown = $target[0] === '=' ? substr($target, 1) : $target;
                    $nullReason = 'Aggregate: formula "' . $exprShown . '" tidak return angka valid di ANY row. '
                        . 'Kolom tersedia: ' . $available . '. Cek nama kolom di formula.';
                } else {
                    $nullReason = 'Aggregate: kolom "' . $target . '" tidak ada atau tidak numeric di SQL rows. '
                        . 'Kolom tersedia: ' . $available . '.';
                }
            }
        } else {
            return '<span style="color:#c00;">invalid marker</span>';
        }

        // Format
        if ($rawValue === null || $rawValue === '') {
            // Show dash BUT dgn title tooltip yg explain WHY — user bisa hover cek
            $titleAttr = $nullReason !== ''
                ? ' title="' . htmlspecialchars($nullReason, ENT_QUOTES) . '" style="text-decoration:underline dotted #c00;cursor:help;"'
                : '';
            return '<span' . $titleAttr . '>—</span>';
        }
        return _tabledbFormatValue($rawValue, $format);
    }, $rowHtml);
}

/**
 * Compute aggregate value across all rows.
 *
 * @param string $verb   'sum'|'avg'|'count'|'min'|'max'
 * @param string $target column name OR '=formula' OR '*' (for count)
 * @param array  $allRows
 * @return float|int|null
 */
function _tabledbComputeAggregate(string $verb, string $target, array $allRows) {
    // COUNT special case: '*' means count all rows
    if ($verb === 'count' && $target === '*') {
        return count($allRows);
    }

    // Detect formula:
    // - Explicit prefix `=` → definitely formula (strip =)
    // - Implicit: target contains operators (+ - * /) atau parens atau space
    //   → user probably meant formula tanpa prefix (kuantiti * harga_satuan)
    // - Otherwise: plain column name lookup
    $isFormula = false;
    $expr = null;
    if ($target !== '' && $target[0] === '=') {
        $isFormula = true;
        $expr = trim(substr($target, 1));
    } elseif (preg_match('/[+\-*\/()]|\s/', $target)) {
        $isFormula = true;
        $expr = trim($target);
    }

    $values = [];
    foreach ($allRows as $row) {
        if ($isFormula) {
            try {
                $val = \Ezdoc\Table\FormulaEvaluator::evaluateBare($expr, $row);
            } catch (\Ezdoc\Exceptions\ValidationException $e) {
                continue; // skip row on formula error
            }
        } else {
            $val = $row[$target] ?? null;
        }
        if ($val === null || $val === '') continue;
        if ($verb === 'count') {
            $values[] = 1.0; // count non-null
            continue;
        }
        if (!is_numeric($val)) continue;
        $values[] = (float) $val;
    }

    if (empty($values)) return null;

    switch ($verb) {
        case 'sum':   return array_sum($values);
        case 'count': return (float) count($values);
        case 'avg':   return array_sum($values) / count($values);
        case 'min':   return (float) min($values);
        case 'max':   return (float) max($values);
    }
    return null;
}

/**
 * Format value via Ezdoc\Table\Formatter based on parsed format spec.
 *
 * @param mixed $value
 * @param array $format ['type' => string, 'args' => string[]]
 * @return string
 */
function _tabledbFormatValue($value, array $format): string {
    $type = $format['type'];
    $args = $format['args'];
    // Bahasa isi dokumen: "1.500" atau "1,500" adalah isi dokumennya, bukan
    // bagian layar. Mata uang tetap mengikuti konvensi mata uangnya.
    $locale = function_exists('ezdoc_document_locale') ? ezdoc_document_locale() : 'en';
    switch ($type) {
        case 'currency':
            $currency = $args[0] ?? 'IDR';
            $decimals = isset($args[1]) && is_numeric($args[1]) ? (int) $args[1] : null;
            return \Ezdoc\Table\Formatter::formatCurrency($value, $currency, $decimals);
        case 'number':
            $decimals = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : 0;
            return \Ezdoc\Table\Formatter::formatNumber($value, $decimals, $locale);
        case 'percent':
            $decimals = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : 2;
            return \Ezdoc\Table\Formatter::formatPercent($value, $decimals, true, $locale);
        case 'date':
            $fmt = $args[0] ?? 'd/m/Y';
            return \Ezdoc\Table\Formatter::formatDate($value, $fmt, $locale);
        case 'datetime':
            $fmt = $args[0] ?? 'd/m/Y H:i';
            return \Ezdoc\Table\Formatter::formatDate($value, $fmt, $locale);
        case 'text':
        default:
            return \Ezdoc\Table\Formatter::escapeHtml((string) $value);
    }
}

// Function to render content for PDF (no form elements, just values)
/**
 * @param array{
 *     values?: array<array-key,mixed>,
 *     show_verify_qr?: bool,
 *     verify_doc_id?: int
 * } $permintaan Nilai yang SUDAH diputuskan pemanggil, bukan permintaan mentah.
 *     Kosong berarti tidak ada permintaan HTTP di belakangnya — jalur
 *     pembekuan memanggilnya begitu. Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md.
 */
function renderContentForPdf($html, $logos, $logoSizes, $configTtd, $ttdValues, $dbFields, $dynamicTables = [], $conn = null, $param_norm = '', $param_nopen = '', $tableDbQueries = [], $doc_id = 0, array $permintaan = []) {
    // Nilai dari permintaan, kalau memang ada permintaannya. Fungsi ini tidak
    // lagi menyentuh $_POST/$_GET: dipanggil dari jalur simpan, ia akan
    // mengambil dari permintaan SIMPAN alih-alih permintaan cetak, dan itu bisa
    // saja kebetulan benar — persis yang tidak boleh ada di pembuat bukti.
    $nilaiPermintaan = $permintaan['values'] ?? [];

    // Bawaannya dokumen yang sedang dirender. Pemanggil HTTP tetap mengirimkan
    // miliknya sendiri supaya perilakunya hari ini tidak bergeser sedikit pun.
    $verifyDocId = (int) ($permintaan['verify_doc_id'] ?? $doc_id);

    // Position offset corrections to match generator/logo (same as HTML rendering)
    // Set to 0 = no adjustment (logo is reference)
    $posOffsetQR = 0;
    $posOffsetTTD = 0;

    // Replace logo placeholders with actual images
    $html = preg_replace_callback('/<span[^>]*class="([^"]*logo-placeholder[^"]*)"[^>]*data-logo="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>.*?<\/span>/s', function($m) use ($logos, $logoSizes) {
        $classes = $m[1] ?? '';
        $logoName = $m[2];
        $width = $m[3] ?? $logoSizes[$logoName] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';
        $src = $logos[$logoName] ?? '';

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        if ($src) {
            $style = "width:" . h($width) . ";";
            $imgClass = "logo-img";

            if ($isFloating) {
                $imgClass .= " logo-floating";
                $imgClass .= $isBehind ? " logo-behind" : " logo-front";
                $style .= "position:absolute;left:{$posX}px;top:{$posY}px;";
            }

            return '<img src="' . h($src) . '" class="' . $imgClass . '" style="' . $style . '">';
        }
        return '';
    }, $html);

    // Replace TTD placeholders
    $html = preg_replace_callback('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<\/div>/s', function($m) use ($configTtd, $ttdValues, $dbFields, $posOffsetTTD, $conn, $doc_id, $nilaiPermintaan, $verifyDocId, $permintaan) {
        $tag = $m[1];
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-ttd="([^"]+)"/', $tag, $tm); $ttdId = $tm[1] ?? '';
        preg_match('/data-label="([^"]+)"/', $tag, $lm); $label = $lm[1] ?? t('fallback.signature', [], 'Signature');
        preg_match('/data-nama-field="([^"]+)"/', $tag, $nm); $namaField = $nm[1] ?? 'nama_' . $ttdId;
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '50';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '100';
        preg_match('/data-ttd-modes="([^"]+)"/', $tag, $mm); $ttdModes = $mm[1] ?? 'image';
        preg_match('/data-ttd-qr-data="([^"]+)"/', $tag, $qm); $qrDataTpl = html_entity_decode($qm[1] ?? '');
        preg_match('/data-default-nama="([^"]*)"/', $tag, $dnm); $defaultNama = html_entity_decode($dnm[1] ?? '');

        if (!$ttdId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        $ttdClass = 'ttd-item-inline';
        $style = '';
        if ($isFloating) {
            $ttdClass = 'ttd-item-floating';
            $ttdClass .= $isBehind ? ' ttd-behind' : ' ttd-front';
            $adjX = max(0, intval($posX) - $posOffsetTTD);
            $adjY = max(0, intval($posY) - $posOffsetTTD);
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        $ttdImg = $ttdValues[$ttdId] ?? '';
        // Resolve with fall-through on empty: dbFields -> POST -> GET
        $pickPdf = function($key) use ($dbFields, $nilaiPermintaan) {
            if (isset($dbFields[$key]) && $dbFields[$key] !== '') return $dbFields[$key];
            if (isset($nilaiPermintaan[$key]) && $nilaiPermintaan[$key] !== '') return $nilaiPermintaan[$key];
            return '';
        };
        $namaRawPdf = $pickPdf($namaField);
        if ($namaRawPdf === '' && $defaultNama !== '') $namaRawPdf = $defaultNama;
        $namaValue = h($namaRawPdf);

        // Determine active mode from saved document data
        $hasQr = strpos($ttdModes, 'qr') !== false;
        $hasImage = strpos($ttdModes, 'image') !== false;
        $savedMode = $pickPdf('_ttd_mode_' . $ttdId);
        $activeMode = $savedMode ?: ($hasImage ? 'image' : 'qr');

        // ─── Verify QR mode (dokumen-level) ───
        // Artinya sekarang: "pakai mode QR untuk semua slot sekaligus".
        //
        // Dulu ia merender jalur sendiri dengan URL verifikasi dokumen — nilai
        // yang sama untuk setiap slot, jadi empat penanda tangan menghasilkan
        // empat QR identik dan tidak ada cara tahu QR yang mana milik siapa.
        // Sekarang ia hanya memaksa modenya, dan isinya ikut jalur
        // per-tanda-tangan yang sama seperti toggle per slot.
        //
        // Yang dipertahankan dari mode ini: ia menyembunyikan SEMUA gambar tanda
        // tangan. Gambar bisa disalin ke dokumen lain; pointer ke record server
        // tidak bisa. Karena itu mode ini memaksa QR bahkan pada slot yang
        // template-nya cuma mengizinkan gambar — kalau tidak, satu gambar tetap
        // tercetak dan gunanya hilang.
        $showVerifyQrModePdf = ($dbFields['_show_verify_qr'] ?? '') === '1'
            || !empty($permintaan['show_verify_qr']);
        if ($showVerifyQrModePdf) {
            $activeMode = 'qr';
            $hasQr = true;
        }

        // Render based on active mode
        if ($activeMode === 'qr' && $hasQr) {
            // Pointer per tanda tangan — lihat penjelasan panjangnya di
            // renderContent(). Dihitung ulang tiap render, tidak pernah disimpan.
            $ttdVerifyUrlPdf = '';
            $__slugPdfTtd = '';
            if ($conn instanceof \mysqli && function_exists('doc_verify_ensure_slug') && (int) $doc_id > 0) {
                $__slugPdfTtd = (string) doc_verify_ensure_slug($conn, (int) $doc_id);
                if ($__slugPdfTtd !== '' && function_exists('doc_verify_build_url')) {
                    $ttdVerifyUrlPdf = doc_verify_build_url($__slugPdfTtd, $ttdId);
                }
            }

            // Urutannya harus sama dengan layar, kalau tidak QR yang dilihat
            // petugas berbeda dari yang tercetak:
            //   1. isi tersimpan  2. pattern template  3. pointer per tanda tangan
            $qrData = $pickPdf($namaField . '_qr');
            if ($qrData !== '' && $__slugPdfTtd !== '' && function_exists('doc_verify_resolve_qr_value')) {
                $qrData = doc_verify_resolve_qr_value($qrData, $__slugPdfTtd, $ttdId);
            }
            if ($qrData === '' && $qrDataTpl !== '') {
                $qrData = preg_replace_callback('/\{([^}]+)\}/', function($pm) use ($pickPdf, $conn, $verifyDocId) {
                    $key = $pm[1];
                    // Magic variable: {verify_url} → URL verifikasi dokumen
                    if ($key === 'verify_url' || $key === 'verify') {
                        $docId = $verifyDocId;
                        if ($docId > 0 && function_exists('doc_verify_ensure_slug')) {
                            $slug = doc_verify_ensure_slug($conn, $docId);
                            return $slug ? doc_verify_build_url($slug) : '';
                        }
                        return '';
                    }
                    return $pickPdf($key);
                }, $qrDataTpl);
            }
            // Pattern yang disetel template tapi field-nya masih kosong tidak
            // diganti diam-diam dengan pointer — itu akan mencetak sesuatu yang
            // artinya lain dari yang template minta. Pointer hanya mengisi
            // kekosongan saat memang tidak ada yang dikonfigurasi.
            //
            // Dan pointer hanya boleh dicetak kalau slot ini SUDAH ditandatangani.
            // Kertas beredar tanpa bisa ditarik: QR tercetak yang menunjuk record
            // yang belum ada akan selamanya menjawab "tanda tangan tidak dikenal".
            if (trim((string) $qrData) === '' && $qrDataTpl === ''
                && \Ezdoc\Signature\SignatureValue::isSigned($ttdImg)) {
                $qrData = $ttdVerifyUrlPdf;
            }
            if (trim($qrData) !== '') {
                try {
                    // Size 250px (up dari 150) + margin 6 supaya scannable saat di-print
                    $qrSrc = ezdoc_qr_data_uri($qrData, 250, 6);
                    // Display size 100x100px — cukup besar untuk scan HP dari jarak wajar
                    // max-width/max-height none supaya tidak kena override dari CSS parent
                    $contentHtml = '<img src="' . $qrSrc . '" style="width:100px;height:100px;max-width:none;max-height:none;" alt="QR">';
                    // Keterangan hanya saat isinya benar-benar pointer verifikasi.
                    // QR berisi pattern template bisa berisi apa saja — nomor
                    // barcode, kode antrian — dan "pindai untuk verifikasi" di
                    // bawahnya akan jadi janji yang tidak dipenuhi.
                    if ($qrData === $ttdVerifyUrlPdf && $ttdVerifyUrlPdf !== '') {
                        $contentHtml .= '<div style="font-size:9px;color:#666;margin-top:2px;">'
                            . h(t('ttd.scan_to_verify', [], 'Scan to verify')) . '</div>';
                    }
                } catch (Exception $e) {
                    $contentHtml = '<div style="height:100px;"></div>';
                }
            } else {
                $contentHtml = '<div style="height:100px;"></div>';
            }
        } else {
            // Penanda mode QR bukan gambar, dan referensi objek harus dibuka dulu.
            // Tanpa ini keduanya masuk ke atribut src dan yang tercetak adalah
            // gambar rusak di tempat tanda tangan.
            $ttdSrcPdf = ezdoc_resolve_signature_image($conn, $ttdImg);
            $contentHtml = $ttdSrcPdf !== ''
                ? '<img src="' . h($ttdSrcPdf) . '" class="ttd-signature" alt="TTD">'
                : '<div style="height:50px;"></div>';
        }

        return '<div class="' . $ttdClass . '" style="' . $style . '">
            <div class="ttd-label">' . h($label) . '</div>
            <div class="ttd-img">' . $contentHtml . '</div>
            <div class="ttd-name">(' . ($namaValue !== '' ? $namaValue : '..................') . ')</div>
        </div>';
    }, $html);

    // Replace MATERAI placeholders with image / empty area for PDF
    $html = preg_replace_callback('/<div([^>]*class="[^"]*materai-placeholder[^"]*"[^>]*)>\s*<div[^>]*>[\s\S]*?<\/div>\s*<\/div>/s', function($m) use ($dbFields) {
        $tag = $m[1];
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-materai="([^"]+)"/', $tag, $im); $materaiId = $im[1] ?? '';
        $label = '';
        if (preg_match('/data-label="([^"]*)"/', $tag, $lm)) $label = $lm[1];
        preg_match('/data-mode="([^"]+)"/', $tag, $mm); $matMode = $mm[1] ?? 'upload';
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '350';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '500';
        preg_match('/data-width="([^"]+)"/', $tag, $wm); $matW = max(20, intval($wm[1] ?? 100));
        preg_match('/data-height="([^"]+)"/', $tag, $hm); $matH = max(20, intval($hm[1] ?? 140));

        if (!$materaiId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind   = strpos($classes, 'behind') !== false;

        $cls = 'materai-pdf-inline';
        $style = '';
        if ($isFloating) {
            $cls = 'materai-pdf-floating';
            $cls .= $isBehind ? ' materai-behind' : ' materai-front';
            $adjX = max(0, intval($posX));
            $adjY = max(0, intval($posY));
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        $imgKey = '_materai_' . $materaiId . '_image';
        $matImg = '';
        if (isset($dbFields[$imgKey]) && $dbFields[$imgKey] !== '') {
            $rawImg = $dbFields[$imgKey];
            if (function_exists('safeDataImg')) {
                $matImg = safeDataImg($rawImg);
            } else {
                $matImg = preg_match('#^data:image/(png|jpe?g|gif);base64,#', $rawImg) ? $rawImg : '';
            }
        }

        $body = '';
        if ($matImg) {
            // Embedded e-materai image with configured size
            $body = '<img src="' . h($matImg) . '" style="width:' . $matW . 'px;height:' . $matH . 'px;object-fit:contain;" alt="Materai">';
        } else {
            // Empty area (manual stamping or upload mode without image yet)
            $body = '<div style="width:' . $matW . 'px;height:' . $matH . 'px;border:1px dashed #c2410c;"></div>';
        }

        $labelHtml = ($label !== '') ? '<div style="font-size:9pt;margin-bottom:3px;color:#000;text-align:center;">' . h($label) . '</div>' : '';

        return '<div class="materai-pdf ' . $cls . '" style="' . $style . 'text-align:center;">' . $labelHtml . $body . '</div>';
    }, $html);

    // Replace QR placeholders with actual QR code images
    $html = preg_replace_callback('/<span[^>]*class="([^"]*qr-placeholder[^"]*)"[^>]*data-qr="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>.*?<\/span>/s', function($m) use ($dbFields, $posOffsetQR, $conn, $doc_id) {
        $classes = $m[1] ?? '';
        $fieldName = $m[2];
        $width = $m[3] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';

        // Get field value for QR data
        $qrData = $dbFields[$fieldName] ?? '';
        // Auto-heal stale verify URL supaya PDF juga pakai base URL current
        if ($qrData !== '' && function_exists('doc_verify_resolve_qr_value') && function_exists('doc_verify_ensure_slug')) {
            $__slugPdfQR = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slugPdfQR) $qrData = doc_verify_resolve_qr_value($qrData, $__slugPdfQR);
        }

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        if ($qrData) {
            // Generate QR code
            $qrSrc = ezdoc_qr_data_uri($qrData, 200, 5);
            $style = "width:" . h($width) . ";height:auto;";
            $imgClass = "qr-img";

            if ($isFloating) {
                $imgClass .= " qr-floating";
                $imgClass .= $isBehind ? " qr-behind" : " qr-front";
                // Apply offset correction (QR has 8px padding in generator)
                $adjX = max(0, intval($posX) - $posOffsetQR);
                $adjY = max(0, intval($posY) - $posOffsetQR);
                $style .= "position:absolute;left:{$adjX}px;top:{$adjY}px;";
            }

            return '<img src="' . $qrSrc . '" class="' . $imgClass . '" style="' . $style . '" alt="QR">';
        }
        return ''; // No QR if no data
    }, $html);

    // ===== TABLEDB (enhanced markers v0.9.14b, PDF) =====
    // Same processor as HTML render path.
    if (!empty($tableDbQueries) && is_array($tableDbQueries)) {
        $html = processTabledbRows($html, $tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn);
    }

    // ===== CONDITIONAL SECTIONS (#7) =====
    // Strip blocks where condition fails; keep inner content of blocks where it passes.
    $html = processConditionalSections($html, $dbFields);

    // Replace field placeholders with value text for PDF
    $html = preg_replace_callback('/<span[^>]*class=["\']field-placeholder[^"\']*["\']([^>]*)>\{\{([^}:]+)(?::text)?\}\}<\/span>/', function($m) use ($dbFields) {
        $attrs = $m[1];
        $name = $m[2];
        $val = isset($dbFields[$name]) && $dbFields[$name] !== '' ? $dbFields[$name] : '';

        // Parse type and default
        $type = 'text';
        $label = '';
        $default = '';
        if (preg_match('/data-type=["\']([^"\']+)["\']/', $attrs, $tm)) $type = $tm[1];
        if (preg_match('/data-label=["\']([^"\']+)["\']/', $attrs, $lm)) $label = html_entity_decode($lm[1]);
        if (preg_match('/data-default=["\']([^"\']+)["\']/', $attrs, $dm)) $default = html_entity_decode($dm[1]);

        // If no saved value, use resolved default
        if ($val === '' && $default !== '') {
            $val = resolveDefault($default);
        }

        return renderFieldForPdf($name, $type, $val, $label);
    }, $html);

    // Handle bare {{field}}
    $html = preg_replace_callback('/\{\{([^}:]+)(?::text)?\}\}/', function($m) use ($dbFields) {
        $name = $m[1];
        $val = isset($dbFields[$name]) ? $dbFields[$name] : '';
        return renderFieldForPdf($name, 'text', $val, '');
    }, $html);

    // Process <p> tags - mark floating-only for paragraphs with only floating elements
    $html = preg_replace_callback('/<p([^>]*)>([\s\S]*?)<\/p>/i', function($match) {
        $attrs = $match[1];
        $content = $match[2];

        // Check if contains floating elements
        $floatingClasses = ['ttd-item-floating', 'qr-item-floating', 'logo-floating', 'qr-floating'];
        $hasFloating = false;
        foreach ($floatingClasses as $fc) {
            if (strpos($content, $fc) !== false) {
                $hasFloating = true;
                break;
            }
        }

        // If no floating elements, keep unchanged (including empty <p> for spacing)
        if (!$hasFloating) {
            return $match[0];
        }

        // Get text content only (strip all tags)
        $textOnly = strip_tags($content);
        $textOnly = preg_replace('/&nbsp;/i', ' ', $textOnly);
        $textOnly = trim($textOnly);

        // If has floating element but no text content, mark as floating-only
        if (empty($textOnly)) {
            if (strpos($attrs, 'class=') !== false) {
                $attrs = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 floating-only"', $attrs);
            } else {
                $attrs .= ' class="floating-only"';
            }
            return '<p' . $attrs . '>' . $content . '</p>';
        }

        return $match[0]; // Has floating + text, keep unchanged
    }, $html);

    return $html;
}

// Render field value for PDF (no inputs, just text)
function renderFieldForPdf($name, $type, $val, $label) {
    $hVal = h($val);

    switch ($type) {
        case 'checkbox':
            $checked = ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'Ya');
            // DejaVu Sans supports the box glyphs; default DOMPDF fonts (Helvetica/Times) don't
            $icon = '<span style="font-family: DejaVu Sans, sans-serif;">' . ($checked ? '☑' : '☐') . '</span>';
            $labelSuffix = $label !== '' ? ' ' . h($label) : '';
            return '<span class="f-pdf">' . $icon . $labelSuffix . '</span>';

        case 'date':
            if ($val) {
                // Format date nicely
                $timestamp = strtotime($val);
                if ($timestamp) {
                    $hVal = date('d/m/Y', $timestamp);
                }
            }
            return '<span class="f-pdf">' . ($hVal ?: '........') . '</span>';

        case 'time':
            // `strtotime('14:30')` mengembalikan jam itu HARI INI, jadi hasilnya
            // benar untuk format 'H:i' dan tidak bergantung pada tanggal apa pun.
            // Nilai yang tersimpan sudah 24 jam, tapi tetap dilewatkan formatter
            // supaya 'HH:MM:SS' dari sumber lain ikut tercetak rapi.
            if ($val) {
                $timestamp = strtotime($val);
                if ($timestamp) {
                    $hVal = date('H:i', $timestamp);
                }
            }
            return '<span class="f-pdf">' . ($hVal ?: '.....') . '</span>';

        case 'number':
        case 'radio':
        case 'select':
        default:
            return '<span class="f-pdf">' . ($hVal ?: '........') . '</span>';
    }
}


/**
 * Turunkan seluruh keputusan tata letak dari satu baris template.
 *
 * HTML beserta elemen mengambangnya, konfigurasi tanda tangan (dari placeholder
 * kalau ada, dari format lama kalau tidak), logo, ukuran kertas, orientasi,
 * padding, dan mode tata letak.
 *
 * ## Kenapa di sini
 *
 * Semua ini murni fungsi dari baris templatenya: tidak menyentuh permintaan yang
 * sedang berjalan, tidak menyentuh RBAC, tidak menyentuh dokumen. Yang membuatnya
 * dulu terkunci di dalam view cuma LETAKNYA — tersebar di antara logika halaman.
 *
 * Jalur pembekuan dokumen harus merakit PDF yang sama persis dengan yang
 * dicetak, dari `save_document.php`. Selama derivasi ini tinggal di view,
 * memanggilnya berarti menjalankan seluruh halaman: query-nya, gerbang aksesnya,
 * keluarannya. Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md §4.
 *
 * Dipindah apa adanya, tanpa satu huruf pun berubah. Kesetaraannya dibuktikan
 * dengan membandingkan seluruh keluaran untuk setiap template sungguhan yang
 * ada, sebelum dan sesudah pemindahan.
 *
 * @param array<string,mixed> $template Baris template dengan alias kolom seperti
 *     yang dipakai view: `template_html`, `config_ttd`, `config_header`,
 *     `floating_elements`, `doc_scope`.
 * @return array<string,mixed> 19 keputusan tata letak, siap dipakai perakit.
 */
function ezdoc_template_layout(array $template): array
{
    // v0.9.12 sidecar rehydration — inject floating markers dari JSON column
    // balik ke templateHtml supaya renderContent pipeline unchanged. Backward-
    // compat: legacy rows dgn floating markers still in HTML tetap works.
    $templateHtml = $template['template_html'] ?: '';
    if (!empty($template['floating_elements'])) {
        $floating = \Ezdoc\Template\FloatingExtractor::fromJson($template['floating_elements']);
        if (!empty($floating)) {
            $templateHtml = \Ezdoc\Template\FloatingInjector::inject($templateHtml, $floating);
        }
    }
    $configTtdRaw = json_decode($template['config_ttd'] ?: '[]', true) ?: [];
    $configHeader = json_decode($template['config_header'] ?: '{}', true) ?: [];
    // Scope template: patient (butuh NORM+NOPEN) atau general (tanpa)
    $templateDocScope = $template['doc_scope'] ?? 'patient';
    $isGeneralDoc = $templateDocScope === 'general';

    // Check if template uses new TTD placeholder format
    $hasTtdPlaceholders = strpos($templateHtml, 'ttd-placeholder') !== false;

    // If using placeholders, extract TTD config from placeholders (not from old configTtd)
    $configTtd = [];
    if ($hasTtdPlaceholders) {
        // Extract TTD info from placeholders in content — robust to attribute ordering
        preg_match_all('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>/s', $templateHtml, $ttdMatches, PREG_SET_ORDER);
        foreach ($ttdMatches as $m) {
            $attrs = $m[1];
            preg_match('/data-ttd="([^"]+)"/', $attrs, $tm);
            $ttdId = $tm[1] ?? '';
            if (!$ttdId) continue;

            preg_match('/data-label="([^"]+)"/', $attrs, $lm);
            preg_match('/data-nama-field="([^"]+)"/', $attrs, $nm);
            preg_match('/data-ttd-modes="([^"]+)"/', $attrs, $mm);
            preg_match('/data-ttd-qr-data="([^"]+)"/', $attrs, $qm);
            preg_match('/data-require-otp="([^"]+)"/', $attrs, $rom);
            preg_match('/data-otp-phone-source="([^"]+)"/', $attrs, $opsm);
            preg_match('/data-otp-ttl="([^"]+)"/', $attrs, $otm);

            $requireOtpVal = $rom[1] ?? '0';
            $requireOtp = ($requireOtpVal === '1' || $requireOtpVal === 'true');

            $otpPhoneSource = $opsm[1] ?? 'nomor_hp_pasien';

            $configTtd[] = [
                'id' => $ttdId,
                'label' => $lm[1] ?? t('fallback.signature', [], 'Signature'),
                'nama_field' => $nm[1] ?? 'nama_' . $ttdId,
                'ttd_modes' => $mm[1] ?? 'image',
                'qr_data' => html_entity_decode($qm[1] ?? ''),
                'require_otp' => $requireOtp,
                'otp_phone_source' => $otpPhoneSource,
                'otp_ttl' => (int)($otm[1] ?? 300)
            ];
        }
    } else {
        // Use old format configTtd
        $configTtd = array_map(function($item) {
            if (isset($item['require_otp'])) {
                $item['require_otp'] = !empty($item['require_otp']);
            }
            return $item;
        }, $configTtdRaw);
    }

    // Get logos (support old and new format)
    $logos = $configHeader['logos'] ?? [];
    $logoSizes = $configHeader['logoSizes'] ?? [];
    if (isset($configHeader['logoKiri'])) $logos['logo_kiri'] = $configHeader['logoKiri'];
    if (isset($configHeader['logoKanan'])) $logos['logo_kanan'] = $configHeader['logoKanan'];

    // Paper size and padding settings
    $paperSizes = [
        'A4' => ['width' => 210, 'height' => 297],
        'A5' => ['width' => 148, 'height' => 210],
        'Letter' => ['width' => 216, 'height' => 279],
        'Legal' => ['width' => 216, 'height' => 356],
        'F4' => ['width' => 215, 'height' => 330]
    ];

    $paperSize = $configHeader['paperSize'] ?? 'A4';
    $orientation = $configHeader['orientation'] ?? 'portrait';

    // Handle custom size or predefined
    if ($paperSize === 'Custom') {
        $paperDim = [
            'width' => (int)($configHeader['customWidth'] ?? 210),
            'height' => (int)($configHeader['customHeight'] ?? 297)
        ];
    } else {
        if (!isset($paperSizes[$paperSize])) $paperSize = 'A4';
        $paperDim = $paperSizes[$paperSize];
    }

    // Swap dimensions for landscape
    if ($orientation === 'landscape') {
        $paperDim = ['width' => $paperDim['height'], 'height' => $paperDim['width']];
    }

    $padding = $configHeader['padding'] ?? ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20];
    $padTop = (int)($padding['top'] ?? 20);
    $padRight = (int)($padding['right'] ?? 20);
    $padBottom = (int)($padding['bottom'] ?? 20);

    // Layout mode: 'continuous' (default — satu kontainer scroll, tanpa page
    // break) atau 'paged' (kartu multi-halaman dengan mask/spacer). Aturan
    // padding berlaku di kedua mode.
    //
    // Default harus sama dengan designer (lihat configHeader.layoutMode di
    // views/_partials/designer_scripts.php). Kalau berbeda, template yang belum
    // pernah menyimpan layoutMode akan tampil satu macam di designer dan macam
    // lain saat digenerate.
    $layoutMode = $configHeader['layoutMode'] ?? 'continuous';
    if (!in_array($layoutMode, ['paged', 'continuous'], true)) $layoutMode = 'continuous';
    $padLeft = (int)($padding['left'] ?? 20);

    return [
        'templateHtml'         => $templateHtml,
        'configTtdRaw'         => $configTtdRaw,
        'configHeader'         => $configHeader,
        'templateDocScope'     => $templateDocScope,
        'isGeneralDoc'         => $isGeneralDoc,
        'hasTtdPlaceholders'   => $hasTtdPlaceholders,
        'configTtd'            => $configTtd,
        'logos'                => $logos,
        'logoSizes'            => $logoSizes,
        'paperSizes'           => $paperSizes,
        'paperSize'            => $paperSize,
        'orientation'          => $orientation,
        'paperDim'             => $paperDim,
        'padding'              => $padding,
        'padTop'               => $padTop,
        'padRight'             => $padRight,
        'padBottom'            => $padBottom,
        'padLeft'              => $padLeft,
        'layoutMode'           => $layoutMode,
    ];
}


/**
 * Rakit HTML PDF lengkap untuk satu dokumen.
 *
 * Satu-satunya jalan menuju HTML yang dicetak — dan, sejak pembekuan dokumen
 * ada, satu-satunya jalan menuju HTML yang disimpan sebagai bukti.
 *
 * ## Kenapa satu, bukan dua
 *
 * Rencananya (docs/260811-beku-pdf-tiap-tanda-tangan.md §3) memperingatkan
 * tepat satu hal: dua perender yang mirip lalu menyimpang. Perakit kedua yang
 * dibuat khusus untuk pembekuan akan tampak benar berbulan-bulan, sampai satu
 * template memakai sesuatu yang cuma diurus salah satunya — dan yang tersimpan
 * sebagai bukti ternyata tidak sama dengan yang dicetak dan ditandatangani
 * orang. Itu kegagalan yang paling mahal yang bisa dihasilkan berkas ini.
 *
 * Jadi jalur cetak memanggil fungsi ini, dan jalur pembekuan memanggil fungsi
 * yang sama.
 *
 * ## Yang HARUS diserahkan pemanggil
 *
 * Tiga hal berasal dari permintaan HTTP dan tidak boleh diterka dari sini:
 * `fields` (nilai field, di jalur cetak termasuk isian dari query string),
 * `ttd_values`, dan `permintaan`. Jalur pembekuan menyerahkan nilai yang BARU
 * SAJA TERSIMPAN, bukan isi permintaan simpannya.
 *
 * @param array{
 *     template: array<string,mixed>,
 *     dokumen?: array<string,mixed>|null,
 *     doc_id?: int,
 *     norm?: string,
 *     nopen?: string,
 *     fields?: array<string,mixed>,
 *     ttd_values?: array<string,mixed>,
 *     conn?: mixed,
 *     permintaan?: array<string,mixed>
 * } $in
 */
function ezdoc_document_pdf_html(array $in): string
{
    $template = $in['template'];
    $dokumen  = $in['dokumen'] ?? null;
    $docId    = (int) ($in['doc_id'] ?? 0);

    $tataLetak   = ezdoc_template_layout($template);
    $templateHtml = $tataLetak['templateHtml'];

    // Elemen mengambang milik DOKUMEN menimpa milik template.
    //
    // Penanda tingkat-template sudah disisipkan ezdoc_template_layout(); kalau
    // dokumennya punya sendiri, yang lama dicabut dulu lalu yang baru dipasang.
    // Tanpa pencabutan itu keduanya menumpuk, dan logonya tercetak dua kali.
    if (!empty($dokumen['floating_elements'])) {
        $docFloating = \Ezdoc\Template\FloatingExtractor::fromJson($dokumen['floating_elements']);
        if (!empty($docFloating)) {
            $dicabut = \Ezdoc\Template\FloatingExtractor::extract($templateHtml);
            $templateHtml = \Ezdoc\Template\FloatingInjector::inject(
                $dicabut['html'],
                $docFloating
            );
        }
    }

    $fields    = $in['fields'] ?? [];
    $ttdValues = $in['ttd_values'] ?? [];
    $conn      = $in['conn'] ?? null;

    $isi = renderContentForPdf(
        $templateHtml,
        $tataLetak['logos'],
        $tataLetak['logoSizes'],
        $tataLetak['configTtd'],
        $ttdValues,
        $fields,
        [],
        $conn,
        (string) ($in['norm'] ?? ''),
        (string) ($in['nopen'] ?? ''),
        $tataLetak['configHeader']['tableDbQueries'] ?? [],
        $docId,
        $in['permintaan'] ?? []
    );

    return ezdoc_compose_pdf_html([
        'template'             => $template,
        'doc_id'               => $docId,
        'paper'                => $tataLetak['paperDim'],
        'pad_top'              => $tataLetak['padTop'],
        'pad_right'            => $tataLetak['padRight'],
        'pad_bottom'           => $tataLetak['padBottom'],
        'pad_left'             => $tataLetak['padLeft'],
        'content'              => $isi,
        'config_ttd'           => $tataLetak['configTtd'],
        'ttd_values'           => $ttdValues,
        'pdf_fields'           => $fields,
        'has_ttd_placeholders' => $tataLetak['hasTtdPlaceholders'],
        'conn'                 => $conn,
        // Satu halaman setinggi isinya. Diputuskan dari template, bukan dari
        // pemanggil, supaya cetak dan beku tidak pernah berbeda pendapat.
        'continuous'           => ($tataLetak['layoutMode'] ?? '') === 'continuous',
    ]);
}

/**
 * Byte PDF dokumen, sesuai tata letak templatenya.
 *
 * Satu jawaban untuk cetak dan untuk pembekuan. Template `continuous` jadi satu
 * halaman setinggi isinya kalau perendernya mampu; kalau tidak — perender host
 * tanpa kemampuan itu, atau isi yang lebih panjang dari batas satu halaman —
 * dirender per halaman seperti biasa, dan kejadiannya dicatat.
 *
 * @param array<string,mixed> $tataLetak hasil ezdoc_template_layout()
 */
function ezdoc_render_pdf_bytes(\Ezdoc\Rendering\PdfRenderer $perender, string $html, array $tataLetak): string
{
    $kertas = $tataLetak['paperDim'];

    if (($tataLetak['layoutMode'] ?? '') === 'continuous'
        && $perender instanceof \Ezdoc\Rendering\ContinuousPdfRenderer) {
        $bytes = $perender->renderContinuous(
            $html,
            (float) $kertas['width'],
            (float) ($tataLetak['padBottom'] ?? 20),
            (float) $kertas['height']
        );
        if ($bytes !== null) {
            return $bytes;
        }
        @error_log('[ezdoc:pdf] isi terlalu panjang untuk satu halaman; dirender per halaman');
    }

    return $perender->render(
        $html,
        [(float) $kertas['width'], (float) $kertas['height']],
        (string) ($tataLetak['orientation'] ?? 'portrait')
    );
}


/**
 * Perender PDF yang dipakai pemasangan ini — satu jawaban untuk semua jalur.
 *
 * Dulu jalur cetak dan jalur pembekuan masing-masing memutuskan sendiri, dan
 * keduanya sudah berbeda: yang cetak memberi DompdfRenderer basePath akar
 * library untuk menyelesaikan aset relatif, yang beku tidak. Dua perender untuk
 * dokumen yang sama bisa menghasilkan PDF yang berbeda — dan yang tersimpan
 * sebagai bukti berhenti sama dengan yang dicetak orang.
 *
 * Urutannya: perender yang disuntik host lewat Context, lalu DompdfRenderer
 * bawaan kalau paketnya terpasang, lalu null.
 */
function ezdoc_pdf_renderer(): ?\Ezdoc\Rendering\PdfRenderer
{
    try {
        $ctx = \Ezdoc\Context::default();
        if ($ctx->pdf instanceof \Ezdoc\Rendering\PdfRenderer) {
            return $ctx->pdf;
        }
    } catch (\Throwable $e) {
        // Context belum disetel. Jatuh ke bawaan.
    }

    if (class_exists('\\Dompdf\\Dompdf')) {
        // Akar library, supaya aset relatif di HTML terselesaikan sama persis
        // di jalur mana pun perender ini dipanggil.
        return new \Ezdoc\Rendering\DompdfRenderer([], dirname(__DIR__) . '/');
    }

    return null;
}

/**
 * Byte PDF beku yang berlaku untuk dokumen ini, atau null.
 *
 * Null berarti "render seperti biasa": tidak ada artefak aktif, atau artefaknya
 * tidak terbaca. Yang kedua bukan keadaan normal — berkas bukti hilang dari
 * penyimpanan — jadi ia dicatat keras. Tapi halamannya tidak dijatuhkan: orang
 * yang sedang mencetak tidak bisa berbuat apa-apa atas penyimpanan yang rusak,
 * dan cetakan langsung masih lebih berguna daripada layar kosong.
 *
 * @param mixed $db Connection
 * @return array{bytes: string, cycle: int}|null
 */
function ezdoc_frozen_pdf($db, int $documentId): ?array
{
    if ($documentId <= 0) {
        return null;
    }

    try {
        $beku = new \Ezdoc\Document\FreezeService($db);
        $artefak = $beku->active($documentId);
        if ($artefak === null) {
            return null;
        }

        $bytes = $beku->bytes($artefak);
        if ($bytes === null || $bytes === '') {
            @error_log(sprintf(
                '[ezdoc:freeze] artefak aktif doc_id=%d siklus %d ada di basis data tapi berkasnya tidak terbaca (key %s)',
                $documentId,
                $artefak->getCycle(),
                $artefak->getBlobKey()
            ));

            return null;
        }

        return ['bytes' => $bytes, 'cycle' => $artefak->getCycle()];
    } catch (\Throwable $e) {
        @error_log(sprintf('[ezdoc:freeze] gagal membaca artefak doc_id=%d: %s', $documentId, $e->getMessage()));

        return null;
    }
}
