<?php

declare(strict_types=1);

namespace Ezdoc\Rendering;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Default PDF renderer using dompdf/dompdf.
 *
 * Zero external dependency beyond `dompdf/dompdf` composer package. Ships
 * dgn library sebagai auto-instantiated fallback kalau consumer tidak wire
 * custom {@see PdfRenderer} via Context.
 *
 * ## Design decisions (industry-standard)
 * - **Millimeter input**: paperMm array `[width, height]` in mm — align dgn
 *   CSS `@page { size }` semantic. Internal conversion ke dompdf's pt via
 *   1mm ≈ 2.83465 pt (72/25.4).
 * - **Orientation-aware**: `setPaper($rect, $orientation)` — dompdf handles
 *   width/height swap kalau orientation mismatch dgn rectangle dimensions.
 * - **Inline stream**: `Attachment: false` — browser render PDF inline
 *   (matches consumer's typical UX untuk preview mode).
 * - **Configurable options**: constructor accepts custom dompdf options
 *   array (mis. `tempDir`, `chroot`, `defaultFont`) untuk consumer control.
 *
 * ## Usage
 *
 * Auto-wired (default kalau consumer skip injection):
 * ```php
 * // ezdoc/views/document/generate.php internally:
 * $renderer = new DompdfRenderer([], __DIR__ . '/../');
 * $renderer->stream($html, $filename, [210, 297], 'portrait');
 * ```
 *
 * Custom-wired oleh consumer app:
 * ```php
 * $renderer = new DompdfRenderer([
 *     'tempDir' => '/var/tmp/dompdf',
 *     'chroot'  => '/var/www',
 *     'defaultFont' => 'Times',
 * ], '/var/www/app');
 * Context::default()->withPdf($renderer);
 * ```
 *
 * ## Substitution
 * Consumer prefers different backend (mPDF, wkhtmltopdf)? Implement
 * {@see PdfRenderer} + inject:
 * ```php
 * class MpdfRenderer implements PdfRenderer { ... }
 * $ctx = Context::default()->withPdf(new MpdfRenderer());
 * ```
 *
 * spec: docs/PDF-RENDERING.md
 */
final class DompdfRenderer implements PdfRenderer
{
    /** @var array<string,mixed> */
    private $options;

    /** @var string|null */
    private $basePath;

    /**
     * @param array<string,mixed> $options   dompdf Options overrides (isRemoteEnabled, tempDir, dst.)
     * @param string|null         $basePath  Base path untuk relative asset resolution (default: null → dompdf's default)
     */
    public function __construct(array $options = [], ?string $basePath = null)
    {
        // Sensible defaults — override via constructor args
        $this->options = $options + [
            'isRemoteEnabled'        => true,
            'isHtml5ParserEnabled'   => true,
            'isFontSubsettingEnabled' => true,
        ];
        $this->basePath = $basePath;
    }

    public function render(string $html, array $paperMm, string $orientation): string
    {
        return (string) $this->build($html, $paperMm, $orientation)->output();
    }

    public function stream(string $html, string $filename, array $paperMm, string $orientation): void
    {
        // Sengaja memakai stream() milik dompdf, bukan render() di atas lalu
        // mencetak sendiri. Header yang benar untuk PDF inline lebih banyak
        // seluk-beluknya daripada kelihatannya, dan menuliskannya ulang di sini
        // berarti dua tempat yang bisa berselisih.
        //
        // Caller bertanggung jawab exit() setelah return.
        $this->build($html, $paperMm, $orientation)->stream($filename, ['Attachment' => false]);
    }

    /**
     * Satu tempat penyiapan dompdf untuk kedua jalur.
     *
     * Sebelumnya seluruh penyiapan ini hidup di dalam `stream()`. Menyalinnya
     * untuk `render()` berarti dua salinan pengaturan kertas dan basePath yang
     * bisa berbeda diam-diam — dan bedanya baru terlihat sebagai dokumen beku
     * yang tidak sama dengan yang dicetak.
     *
     * @param array<int,float|int> $paperMm
     */
    private function build(string $html, array $paperMm, string $orientation): Dompdf
    {
        $options = new Options();
        foreach ($this->options as $key => $value) {
            $options->set($key, $value);
        }

        $dompdf = new Dompdf($options);
        if ($this->basePath !== null) {
            $dompdf->setBasePath($this->basePath);
        }

        $dompdf->loadHtml($html);

        // dompdf setPaper accepts either named size ('a4') or rectangle in points.
        // Convert paperMm [w, h] to [0, 0, w_pt, h_pt] rectangle.
        // 1mm ≈ 2.83465 pt (72 pt/inch ÷ 25.4 mm/inch).
        $widthPt  = (float) ($paperMm[0] ?? 210) * 2.83465;
        $heightPt = (float) ($paperMm[1] ?? 297) * 2.83465;
        $dompdf->setPaper([0.0, 0.0, $widthPt, $heightPt], $orientation);

        $dompdf->render();

        return $dompdf;
    }
}
