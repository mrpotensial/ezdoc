# PDF Rendering

Contract-based PDF rendering backend. Consumer app can inject any PDF engine (dompdf, mPDF, wkhtmltopdf, Weasyprint) via a single interface.

## Overview

Ezdoc uses `Ezdoc\Rendering\PdfRenderer` interface as the PDF output contract. This decouples the library from any specific PDF engine, following industry-standard driver/transport patterns.

| Component | Path | Responsibility |
|---|---|---|
| `PdfRenderer` (interface) | `src/Rendering/PdfRenderer.php` | Contract — accepts HTML, streams PDF response |
| `DompdfRenderer` (default) | `src/Rendering/DompdfRenderer.php` | Reference impl using `dompdf/dompdf` composer package |
| `Context::$pdf` | `src/Context.php` | Injection point (immutable via `withPdf()`) |

## Precedent

Design modeled after industry-standard backend abstraction patterns:

- **Symfony Mailer** — `TransportInterface` + concrete `Transport` classes + factory
- **Laravel Mail** — `MailManager::extend()` custom driver pattern
- **Filament Notifications** — contract-based renderers
- **Barryvdh/laravel-dompdf** — `stream($filename)` method name (adopted for parity)

## Interface

```php
namespace Ezdoc\Rendering;

interface PdfRenderer
{
    public function stream(
        string $html,        // Full HTML document
        string $filename,    // Suggested filename ("document_123.pdf")
        array $paperMm,      // [width_mm, height_mm]
        string $orientation  // 'portrait' | 'landscape'
    ): void;
}
```

**Contract semantics**:
- `stream()` sends `Content-Type: application/pdf` header + PDF binary body
- Does NOT call `exit` — caller responsible for terminating request
- `paperMm` matches CSS `@page { size }` semantic (millimeters)

## Default: DompdfRenderer

Zero-dependency default (beyond `dompdf/dompdf` composer package). Auto-instantiated by `generate.php` when consumer doesn't inject custom renderer.

```php
use Ezdoc\Rendering\DompdfRenderer;

// Default (no options)
$renderer = new DompdfRenderer();

// With custom options
$renderer = new DompdfRenderer([
    'tempDir'                => '/var/tmp/dompdf',
    'chroot'                 => '/var/www',
    'defaultFont'            => 'Times',
    'isRemoteEnabled'        => true,
    'isHtml5ParserEnabled'   => true,
    'isFontSubsettingEnabled' => true,
], basePath: '/var/www/app');
```

**Constructor**:
```php
public function __construct(array $options = [], ?string $basePath = null)
```

- `$options` — passed to `Dompdf\Options` (see [dompdf docs](https://github.com/dompdf/dompdf/wiki))
- `$basePath` — for relative asset resolution (`<img src="assets/logo.png">`)

## Consumer Integration

### Auto-wired (default — no code needed)

If consumer installs `dompdf/dompdf` via Composer, ezdoc auto-instantiates `DompdfRenderer` when rendering PDF. Zero configuration required.

### Custom-wired (opt-in)

Inject via `Context::withPdf()`:

```php
use Ezdoc\Context;
use Ezdoc\Rendering\DompdfRenderer;

$renderer = new DompdfRenderer([
    'defaultFont' => 'Times',
], __DIR__);

$ctx = Context::default()->withPdf($renderer);
Context::setDefault($ctx);
```

### Custom Backend (mPDF example)

Implement the `PdfRenderer` interface:

```php
use Ezdoc\Rendering\PdfRenderer;
use Mpdf\Mpdf;

final class MpdfRenderer implements PdfRenderer
{
    public function stream(string $html, string $filename, array $paperMm, string $orientation): void
    {
        $mpdf = new Mpdf([
            'format' => [$paperMm[0], $paperMm[1]],
            'orientation' => $orientation === 'landscape' ? 'L' : 'P',
        ]);
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, 'I'); // Inline
    }
}

Context::setDefault(Context::default()->withPdf(new MpdfRenderer()));
```

### wkhtmltopdf Example

```php
use Ezdoc\Rendering\PdfRenderer;
use Knp\Snappy\Pdf;

final class WkhtmlToPdfRenderer implements PdfRenderer
{
    private Pdf $snappy;

    public function __construct(string $binary)
    {
        $this->snappy = new Pdf($binary);
    }

    public function stream(string $html, string $filename, array $paperMm, string $orientation): void
    {
        $this->snappy->setOption('page-width', $paperMm[0] . 'mm');
        $this->snappy->setOption('page-height', $paperMm[1] . 'mm');
        $this->snappy->setOption('orientation', $orientation);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $this->snappy->getOutputFromHtml($html);
    }
}
```

## Resolution Priority

`generate.php` resolves PDF renderer in this order:

1. **Consumer-injected `PdfRenderer` instance** via `$ctx->pdf`
2. **Duck-typed object** with `stream()` method (backward-compat for pre-contract implementations)
3. **Auto-instantiate `DompdfRenderer`** if `Dompdf\Dompdf` class exists
4. **Error page** if none of the above (guide user to install dompdf or wire custom)

## CSS Considerations for dompdf

`DompdfRenderer` relies on `dompdf/dompdf` engine which has some CSS quirks vs browsers:

- **`box-sizing: border-box` is partial** — `padding` inside `width` calc unreliable. Use explicit `.content { width: (paperW - padL - padR) mm }` in stylesheet
- **Fonts** — dompdf ships `Times`, `Helvetica`, `Courier`, `DejaVu` families. Use `"Times", "Times New Roman", serif` for portable serif (dompdf → native Times, browser → Times New Roman)
- **Line-height** — set `line-height: 1.6` explicitly on `body` (dompdf default varies)
- **Heading defaults** — dompdf doesn't apply browser h1-h6 defaults. Set explicit `h1 { font-size: 2em; margin: 0.67em 0 }`, etc.
- **Word wrapping** — set `word-wrap`, `overflow-wrap`, `word-break` on `.content` for long unbreakable text

See `views/document/generate.php` PDF `<style>` block for reference stylesheet applying all these fixes.

## Testing

Verify renderer contract compliance:

```php
public function testRendererStreamsPdf(): void
{
    $renderer = new DompdfRenderer();

    ob_start();
    $renderer->stream(
        html: '<h1>Test</h1>',
        filename: 'test.pdf',
        paperMm: [210, 297],
        orientation: 'portrait'
    );
    $output = ob_get_clean();

    $this->assertStringStartsWith('%PDF-', $output);
    $this->assertGreaterThan(1000, strlen($output));
}
```

## Migration from `generatePDF()` (legacy)

**Before** (consumer function dependency):
```php
// In consumer's koneksi.php:
function generatePDF($html, $filename, $stream, $paper, $orientation) {
    $dompdf = new Dompdf();
    // ...
}

// Ezdoc calls global function:
generatePDF($pdfHtml, $filename, true, $paperMm, $orientation);
```

**After** (library-native):
```php
// No consumer code needed — ezdoc auto-wires DompdfRenderer.
// Or opt-in:
Context::default()->withPdf(new DompdfRenderer());
```

Legacy `generatePDF()` fallback removed from `generate.php` in v0.9.10. Consumer apps still defining it can migrate at their own pace by wrapping in a `PdfRenderer` implementation.

## See Also

- `docs/LOCALIZATION.md` — DateFormatter (companion contract for date translation)
- `src/Context.php` — DI container documentation
- [dompdf docs](https://github.com/dompdf/dompdf/wiki) — CSS support matrix, font registration
- Symfony Mailer transports — architectural inspiration
