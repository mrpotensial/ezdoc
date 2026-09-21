<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use Ezdoc\Rendering\ContinuousPdfRenderer;
use Ezdoc\Rendering\DompdfRenderer;
use Ezdoc\Rendering\PdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Template `continuous` jadi PDF satu halaman setinggi isinya.
 *
 * Dilaporkan dari pemakaian: template diset continuous, tapi PDF Raw tetap
 * terpotong per lembar A4. `layoutMode` dulu cuma mengatur tampilan layar —
 * jalur PDF tidak pernah membacanya, padahal label di designer berbunyi
 * "Continuous (no page breaks)". Dokumen seperti ini disimpan dan dibaca secara
 * digital; batas lembar cuma menambahkan potongan yang tidak ada di layar.
 *
 * Yang dijaga: cetak DAN arsip beku sama-sama satu halaman, lewat pembantu yang
 * sama. Arsip yang berbeda bentuk dari cetakannya bukan arsip.
 *
 * Tes yang merender sungguhan butuh dompdf. Ia bukan dependensi library ini
 * (`suggest`), jadi tes itu dilewati kalau dompdf tidak bisa dimuat.
 */
final class ContinuousPdfTest extends TestCase
{
    private static function siapkanDompdf(): bool
    {
        if (class_exists('\\Dompdf\\Dompdf')) {
            return true;
        }

        // Pemasangan pengembangan: dompdf milik aplikasi pemakai, satu tingkat di
        // atas library, tanpa peta autoload composer. Dimuat manual kalau ada.
        $v = dirname(__DIR__, 3) . '/vendor/';
        if (!is_dir($v . 'dompdf/dompdf/src')) {
            return false;
        }

        spl_autoload_register(static function (string $k) use ($v): void {
            if ($k === 'Dompdf\\Cpdf') {
                require $v . 'dompdf/dompdf/lib/Cpdf.php';

                return;
            }
            foreach ([
                'Dompdf\\'          => 'dompdf/dompdf/src/',
                'FontLib\\'         => 'dompdf/php-font-lib/src/FontLib/',
                'Svg\\'             => 'dompdf/php-svg-lib/src/Svg/',
                'Masterminds\\'     => 'masterminds/html5/src/',
                'Sabberworm\\CSS\\' => 'sabberworm/php-css-parser/src/',
            ] as $ns => $dir) {
                if (strpos($k, $ns) === 0) {
                    $f = $v . $dir . str_replace('\\', '/', substr($k, strlen($ns))) . '.php';
                    if (is_file($f)) {
                        require $f;
                    }

                    return;
                }
            }
        });

        return class_exists('\\Dompdf\\Dompdf');
    }

    private static function jumlahHalaman(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page(?!s)/', $pdf);
    }

    /** @return array{float,float} lebar, tinggi dalam mm */
    private static function ukuranHalaman(string $pdf): array
    {
        preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m);

        return [(float) ($m[1] ?? 0) / 2.83465, (float) ($m[2] ?? 0) / 2.83465];
    }

    private static function htmlPanjang(bool $continuous, int $baris = 90): string
    {
        require_once dirname(__DIR__, 2) . '/lib/view_helpers.php';
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';

        $isi = '';
        for ($i = 1; $i <= $baris; $i++) {
            $isi .= "<p>Baris ke-{$i}. Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>";
        }
        $isi .= '<p style="page-break-before: always">Sesudah pemisah paksa.</p>';

        return ezdoc_compose_pdf_html([
            'template'             => ['nama_template' => 'Uji'],
            'doc_id'               => 0,
            'paper'                => ['width' => 210, 'height' => 297],
            'pad_top'              => 20,
            'pad_right'            => 20,
            'pad_bottom'           => 20,
            'pad_left'             => 20,
            'content'              => $isi,
            'config_ttd'           => [],
            'ttd_values'           => [],
            'pdf_fields'           => [],
            'has_ttd_placeholders' => true,
            'conn'                 => null,
            'continuous'           => $continuous,
        ]);
    }

    // ─── Perakit ───

    /** Di dompdf `@page size` menang atas setPaper — jadi ia harus absen. */
    public function testPerakitContinuousTanpaUkuranHalaman(): void
    {
        $html = self::htmlPanjang(true);

        self::assertDoesNotMatchRegularExpression('/@page\s*\{[^}]*size:/', $html);
        self::assertStringContainsString('page-break-before: auto !important', $html);
    }

    /** Mode per halaman tidak berubah sedikit pun. */
    public function testPerakitPerHalamanTetapBerukuran(): void
    {
        $html = self::htmlPanjang(false);

        self::assertMatchesRegularExpression('/@page\s*\{[^}]*size:\s*210mm 297mm;/', $html);
        self::assertStringNotContainsString('page-break-before: auto !important', $html);
    }

    // ─── Perender sungguhan ───

    public function testDompdfContinuousJadiSatuHalaman(): void
    {
        if (!self::siapkanDompdf()) {
            self::markTestSkipped('dompdf tidak tersedia.');
        }

        $pdf = (new DompdfRenderer())->renderContinuous(self::htmlPanjang(true), 210.0, 20.0);

        self::assertNotNull($pdf);
        self::assertSame(1, self::jumlahHalaman($pdf), 'isi terpotong ke halaman lain');

        [$lebar, $tinggi] = self::ukuranHalaman($pdf);
        self::assertEqualsWithDelta(210.0, $lebar, 1.0);
        self::assertGreaterThan(297.0, $tinggi, 'halamannya tidak lebih tinggi dari A4 — bukan continuous');
    }

    /** Pembanding: isi yang sama di mode per halaman memang lebih dari satu lembar. */
    public function testIsiYangSamaPerHalamanLebihDariSatu(): void
    {
        if (!self::siapkanDompdf()) {
            self::markTestSkipped('dompdf tidak tersedia.');
        }

        $pdf = (new DompdfRenderer())->render(self::htmlPanjang(false), [210, 297], 'portrait');

        self::assertGreaterThan(1, self::jumlahHalaman($pdf), 'premis tes: isinya harus lebih panjang dari satu A4');
    }

    /**
     * Lanskap tetap memanjang ke bawah, tidak diputar.
     *
     * setPaper() dengan 'landscape' menukar sisi supaya lebar lebih besar dari
     * tinggi. Halaman continuous yang tinggi akan diputar jadi lebar kalau
     * orientasinya ikut diteruskan.
     */
    public function testLanskapTidakDiputar(): void
    {
        if (!self::siapkanDompdf()) {
            self::markTestSkipped('dompdf tidak tersedia.');
        }

        $pdf = (new DompdfRenderer())->renderContinuous(self::htmlPanjang(true), 297.0, 20.0);
        [$lebar, $tinggi] = self::ukuranHalaman((string) $pdf);

        self::assertEqualsWithDelta(297.0, $lebar, 1.0, 'lebar lanskap tidak dipertahankan');
        self::assertGreaterThan($lebar, $tinggi, 'halaman lanskap continuous diputar jadi lebar');
    }

    /**
     * Isi yang muat selembar tetap berukuran PERSIS selembar.
     *
     * Dengan cadangan pembulatan 1 mm, dokumen A4 satu lembar jadi 298 mm — dan
     * dicetak di kertas A4 ia diperkecil 99,7%.
     */
    public function testIsiPendekPersisSelembar(): void
    {
        if (!self::siapkanDompdf()) {
            self::markTestSkipped('dompdf tidak tersedia.');
        }

        $pdf = (new DompdfRenderer())->renderContinuous(self::htmlPanjang(true, 5), 210.0, 20.0, 297.0);
        [, $tinggi] = self::ukuranHalaman((string) $pdf);

        self::assertSame(1, self::jumlahHalaman((string) $pdf));
        self::assertEqualsWithDelta(297.0, $tinggi, 0.5, 'dokumen selembar tidak lagi berukuran A4');
    }

    /** Pemisah paksa dari template tidak membuat halaman kedua. */
    public function testPemisahPaksaDiabaikan(): void
    {
        if (!self::siapkanDompdf()) {
            self::markTestSkipped('dompdf tidak tersedia.');
        }

        $pdf = (new DompdfRenderer())->renderContinuous(self::htmlPanjang(true, 10), 210.0, 20.0);

        self::assertSame(1, self::jumlahHalaman((string) $pdf));
    }

    // ─── Satu jawaban untuk cetak dan beku ───

    public function testPembantuMemakaiContinuousUntukTemplateContinuous(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';
        $r = new PerenderPencatat();

        ezdoc_render_pdf_bytes($r, '<p>x</p>', $this->tataLetak('continuous'));

        self::assertSame(['continuous', 210.0, 15.0, 297.0], $r->dipanggil);
    }

    public function testPembantuPerHalamanUntukTemplatePaged(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';
        $r = new PerenderPencatat();

        ezdoc_render_pdf_bytes($r, '<p>x</p>', $this->tataLetak('paged'));

        self::assertSame('render', $r->dipanggil[0]);
    }

    /** Terlalu panjang untuk satu halaman: jatuh ke per halaman, bukan gagal. */
    public function testTerlaluPanjangJatuhKePerHalaman(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';
        $r = new PerenderPencatat(true);

        $hasil = ezdoc_render_pdf_bytes($r, '<p>x</p>', $this->tataLetak('continuous'));

        self::assertSame('render', $r->dipanggil[0]);
        self::assertSame('%PDF paged', $hasil);
    }

    /** Perender host tanpa kemampuan continuous tetap dipakai, per halaman. */
    public function testPerenderTanpaKemampuanTetapDipakai(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';
        $r = new class implements PdfRenderer {
            public function render(string $html, array $paperMm, string $orientation): string { return '%PDF host'; }
            public function stream(string $html, string $filename, array $paperMm, string $orientation): void {}
        };

        self::assertSame('%PDF host', ezdoc_render_pdf_bytes($r, '<p>x</p>', $this->tataLetak('continuous')));
    }

    /** Cetak dan beku sama-sama lewat pembantu yang sama. */
    public function testCetakDanBekuMemakaiPembantuYangSama(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertStringContainsString(
            'ezdoc_render_pdf_bytes($perender, $html, $tataLetak)',
            (string) file_get_contents($root . '/actions/document/save_document.php')
        );
        self::assertStringContainsString(
            'ezdoc_render_pdf_bytes($pdfRenderer, $pdfHtml, $__tataLetak)',
            (string) file_get_contents($root . '/views/document/generate.php')
        );
    }

    /** @return array<string,mixed> */
    private function tataLetak(string $mode): array
    {
        return [
            'layoutMode'  => $mode,
            'paperDim'    => ['width' => 210, 'height' => 297],
            'padBottom'   => 15,
            'orientation' => 'portrait',
        ];
    }
}

/** Perender uji yang mencatat jalan mana yang dipilih. */
final class PerenderPencatat implements PdfRenderer, ContinuousPdfRenderer
{
    /** @var array<int,mixed> */
    public $dipanggil = [];

    /** @var bool */
    private $terlaluPanjang;

    public function __construct(bool $terlaluPanjang = false)
    {
        $this->terlaluPanjang = $terlaluPanjang;
    }

    public function renderContinuous(string $html, float $widthMm, float $marginBottomMm, float $minHeightMm = 0.0): ?string
    {
        if ($this->terlaluPanjang) {
            return null;
        }
        $this->dipanggil = ['continuous', $widthMm, $marginBottomMm, $minHeightMm];

        return '%PDF continuous';
    }

    public function render(string $html, array $paperMm, string $orientation): string
    {
        $this->dipanggil = ['render', $paperMm, $orientation];

        return '%PDF paged';
    }

    public function stream(string $html, string $filename, array $paperMm, string $orientation): void
    {
    }
}
