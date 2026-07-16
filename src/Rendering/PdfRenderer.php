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
