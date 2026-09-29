<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use PHPUnit\Framework\TestCase;

/**
 * Penanda papan di HTML template berubah jadi gambar — dua bungkus, satu isi.
 *
 * Layar menyisipkan `<svg>` langsung; cetakan membungkusnya dalam `<img>`
 * dengan data URI, karena Dompdf **mengabaikan `<svg>` yang ditulis langsung
 * di HTML**. Diuji pada Dompdf 3.1.5, lihat `docs/260929-grafik-terisi.md` §2.
 *
 * Yang digambar keduanya harus sama. Kalau tidak, orang menandatangani sesuatu
 * yang berbeda dari yang tercetak.
 */
final class ChartHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/chart_helpers.php';
    }

    /** @param array<string,mixed> $spec */
    private static function penanda(array $spec, string $id = 'chart_1'): string
    {
        return '<div class="chart-placeholder" data-chart="' . $id . '" data-chart-spec="'
            . htmlspecialchars((string) json_encode($spec), ENT_QUOTES, 'UTF-8')
            . '" contenteditable="false"><span>Papan grafik</span></div>';
    }

    /** @return array<string,mixed> */
    private static function spec(): array
    {
        return [
            'hours' => 16,
            'column_width' => 40,
            'start_field' => 'start_time',
            'panels' => [[
                'height' => 200,
                'y' => ['min' => 0, 'max' => 10, 'step' => 1],
                'series' => [['field' => 'dilatation']],
            ]],
        ];
    }

    /** @return callable(string):string */
    private static function pembaca(): callable
    {
        $nilai = [
            'start_time' => '2026-09-29 08:00',
            'dilatation' => '[{"x":"2026-09-29 10:00","y":5}]',
        ];

        return static function (string $name) use ($nilai): string {
            return $nilai[$name] ?? '';
        };
    }

    /** Di layar: gambarnya langsung, plus satu isian tersembunyi per deret. */
    public function testScreenDrawsSvgAndWritesHiddenInputs(): void
    {
        $html = ezdoc_chart_render_screen(self::penanda(self::spec()), self::pembaca());

        self::assertStringNotContainsString('chart-placeholder', $html, 'penandanya masih tertinggal');
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('cx="144" cy="116"', $html, 'titiknya tidak mendarat di tempat yang dihitung');
        self::assertSame(
            1,
            preg_match('/<input type="hidden" name="dilatation" data-chart-series="dilatation"/', $html),
            'deretnya tidak punya isian tersembunyi'
        );
    }

    /**
     * Isian tersembunyi itu yang membuat grafik tidak butuh mekanisme sendiri.
     *
     * Namanya nama field, jadi penyimpanan dokumen memperlakukannya persis
     * seperti isian lain — termasuk pembekuan sesudah tanda tangan dan
     * penyalinan ke versi baru.
     */
    public function testHiddenInputCarriesTheCanonicalForm(): void
    {
        $penanda = self::penanda(self::spec());
        $html = ezdoc_chart_render_screen($penanda, static function (string $name): string {
            if ($name === 'start_time') {
                return '2026-09-29 08:00';
            }

            // Sengaja berantakan: terbalik urutannya, ada waktu kembar.
            return '[{"x":"2026-09-29 12:00","y":6},{"x":"2026-09-29 10:00","y":5},{"x":"2026-09-29 12:00","y":7}]';
        });

        self::assertStringContainsString(
            'value="[{&quot;x&quot;:&quot;2026-09-29 10:00&quot;,&quot;y&quot;:5},'
            . '{&quot;x&quot;:&quot;2026-09-29 12:00&quot;,&quot;y&quot;:7}]"',
            $html,
            'yang tersimpan bukan bentuk bakunya'
        );
    }

    /** Di cetakan: gambar yang sama, dibungkus <img> karena Dompdf butuh itu. */
    public function testPdfWrapsTheSameDrawingInAnImage(): void
    {
        $html = ezdoc_chart_render_pdf(self::penanda(self::spec()), self::pembaca());

        self::assertStringNotContainsString('chart-placeholder', $html);
        self::assertStringNotContainsString('<svg', $html, 'Dompdf mengabaikan <svg> yang ditulis langsung');
        self::assertSame(1, preg_match('/<img src="data:image\/svg\+xml;base64,[A-Za-z0-9+\/=]+"/', $html));
    }

    /** Dan gambar di dalam bungkus itu sama persis dengan yang di layar. */
    public function testPdfDrawingMatchesTheScreen(): void
    {
        $layar = ezdoc_chart_render_screen(self::penanda(self::spec()), self::pembaca());
        $cetak = ezdoc_chart_render_pdf(self::penanda(self::spec()), self::pembaca());

        self::assertSame(1, preg_match('/(<svg.*<\/svg>)/s', $layar, $m), 'svg layar tidak ketemu');
        self::assertSame(1, preg_match('/base64,([A-Za-z0-9+\/=]+)"/', $cetak, $n), 'gambar cetak tidak ketemu');

        self::assertSame($m[1], base64_decode($n[1]), 'yang tercetak berbeda dari yang dilihat orang');
    }

    /**
     * Spesifikasi yang tidak terbaca menyebut dirinya rusak, bukan menghilang.
     *
     * Grafik yang lenyap tanpa jejak terbaca sebagai template yang memang tidak
     * punya grafik, dan tidak ada yang mencarinya.
     */
    public function testBrokenSpecSaysSoOnScreen(): void
    {
        $rusak = '<div class="chart-placeholder" data-chart="x" data-chart-spec="bukan json"><span>x</span></div>';

        self::assertStringContainsString('ez-chart-broken', ezdoc_chart_render_screen($rusak, self::pembaca()));
    }

    /** HTML tanpa satu papan pun dikembalikan apa adanya. */
    public function testHtmlWithoutChartsIsUntouched(): void
    {
        $html = '<p>Surat biasa</p>';

        self::assertSame($html, ezdoc_chart_render_screen($html, self::pembaca()));
        self::assertSame($html, ezdoc_chart_render_pdf($html, self::pembaca()));
    }

    /** Dua papan di satu halaman masing-masing digambar sendiri. */
    public function testTwoBoardsOnOnePage(): void
    {
        $spec2 = self::spec();
        $spec2['panels'][0]['series'] = [['field' => 'pulse']];

        $html = ezdoc_chart_render_screen(
            self::penanda(self::spec(), 'a') . self::penanda($spec2, 'b'),
            self::pembaca()
        );

        self::assertSame(2, substr_count($html, '<svg'));
        self::assertStringContainsString('data-chart-series="dilatation"', $html);
        self::assertStringContainsString('data-chart-series="pulse"', $html);
    }
}
