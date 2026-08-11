<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use Ezdoc\Rendering\DompdfRenderer;
use Ezdoc\Rendering\PdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Kontrak `PdfRenderer` harus bisa MENGEMBALIKAN byte, bukan cuma mengirimnya.
 *
 * Antarmuka ini semula hanya punya `stream()`, dan itu membuat pembekuan
 * dokumen mustahil: byte-nya sudah terlanjur pergi ke browser sebelum ada yang
 * bisa memegangnya. Setiap kebutuhan yang menyimpan alih-alih menampilkan —
 * membekukan, menyegel dengan sertifikat, melampirkan ke surel, mengirim ke
 * penyedia tanda tangan — semuanya buntu di situ.
 *
 * `DompdfRenderer` tidak bisa dijalankan di sini kalau paket dompdf tidak
 * terpasang, jadi yang diperiksa bentuknya lewat refleksi. Yang dijalankan
 * sungguhan adalah implementasi pengganti — dan itu memang inti dari kontrak
 * ini: aplikasi pemakai boleh memasang mesin PDF apa pun.
 */
final class PdfRendererContractTest extends TestCase
{
    public function testTheContractRequiresBothReturningAndStreaming(): void
    {
        $r = new \ReflectionClass(PdfRenderer::class);

        self::assertTrue($r->hasMethod('render'), 'render() hilang — pembekuan butuh byte-nya');
        self::assertTrue($r->hasMethod('stream'));
    }

    /** Yang mengembalikan byte harus benar-benar mengembalikan string. */
    public function testRenderIsDeclaredToReturnBytes(): void
    {
        $m = new \ReflectionMethod(PdfRenderer::class, 'render');

        self::assertSame('string', (string) $m->getReturnType());
        self::assertSame(3, $m->getNumberOfParameters(), 'render() tidak perlu nama berkas');
    }

    /**
     * Implementasi bawaan harus ikut kontraknya, walau paketnya belum terpasang
     * di mesin ini. Diperiksa lewat refleksi supaya tesnya tidak diam-diam
     * dilewati justru di tempat yang paling perlu diperiksa.
     */
    public function testTheDefaultImplementationSatisfiesTheContract(): void
    {
        $r = new \ReflectionClass(DompdfRenderer::class);

        self::assertTrue($r->implementsInterface(PdfRenderer::class));
        self::assertTrue($r->hasMethod('render'));
        self::assertSame('string', (string) $r->getMethod('render')->getReturnType());
    }

    /**
     * Kedua jalur harus berbagi satu penyiapan.
     *
     * Kalau `render()` dan `stream()` menyiapkan mesinnya sendiri-sendiri,
     * pengaturan kertas dan basePath bisa berbeda diam-diam — dan bedanya baru
     * terlihat sebagai dokumen beku yang tidak sama dengan yang dicetak.
     */
    public function testBothPathsShareOneSetup(): void
    {
        $r = new \ReflectionClass(DompdfRenderer::class);

        self::assertTrue(
            $r->hasMethod('build'),
            'render() dan stream() harus berbagi satu penyiapan, bukan menyalinnya'
        );

        // Dihitung di KODE saja. Docblock kelas ini memang menjelaskan
        // setPaper(), dan pemeriksa yang ikut menghitung komentar akan menuduh
        // dokumentasi yang baik sebagai duplikasi.
        $kode = '';
        foreach (token_get_all((string) file_get_contents((string) $r->getFileName())) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        self::assertSame(
            1,
            preg_match_all('/\bsetPaper\s*\(/', $kode),
            'pengaturan kertas ditulis lebih dari sekali — dua salinan bisa berselisih'
        );
    }

    /** Kontraknya bisa dipenuhi implementasi lain — itu gunanya ia antarmuka. */
    public function testASubstituteEngineCanFulfilTheContract(): void
    {
        $fake = new class implements PdfRenderer {
            public function render(string $html, array $paperMm, string $orientation): string
            {
                return "%PDF-1.4 " . $orientation . ' ' . implode('x', $paperMm) . ' ' . strlen($html);
            }

            public function stream(string $html, string $filename, array $paperMm, string $orientation): void
            {
                echo $this->render($html, $paperMm, $orientation);
            }
        };

        $bytes = $fake->render('<html><body>halo</body></html>', [210, 297], 'portrait');

        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertStringContainsString('210x297', $bytes);
    }
}
