<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\Geometry;
use Ezdoc\Chart\SvgChartRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Gambarnya sendiri: letaknya benar, bentuknya bisa dicetak, dan hasilnya
 * selalu sama.
 *
 * Perender ini dipakai tiga pihak — halaman dokumen, PDF cetak, dan PDF beku
 * yang dibuat server saat tanda tangan masuk. Karena itu dua sifat di sini
 * bukan kerapian, melainkan syarat:
 *
 * - **deterministik**: angka yang sama menghasilkan byte yang sama, kapan pun
 *   dipanggil. Tanpa itu, dokumen tidak bisa dibangun ulang dari nilainya.
 * - **bisa dicetak Dompdf**: dua batasan yang sudah diuji langsung pada Dompdf
 *   3.1.5 + php-svg-lib, lihat `docs/260929-grafik-terisi.md` §2.
 */
final class SvgChartRendererTest extends TestCase
{
    /** Papan sederhana dengan angka yang mudah dihitung di kepala. */
    private static function spec(): ChartSpec
    {
        return ChartSpec::fromArray([
            'hours' => 16,
            'column_width' => 40,
            'start_field' => 'start_time',
            'panels' => [
                [
                    'title' => 'Pembukaan',
                    'height' => 200,
                    'y' => ['min' => 0, 'max' => 10, 'step' => 1],
                    'series' => [['field' => 'dilatation', 'marker' => 'circle']],
                ],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private static function values(): array
    {
        return [
            'start_time' => '2026-09-29 08:00',
            'dilatation' => [
                ['x' => '2026-09-29 10:00', 'y' => 5],
                ['x' => '2026-09-29 12:00', 'y' => 7],
            ],
        ];
    }

    /**
     * Titik mendarat tepat di tempat yang dihitung.
     *
     * kiri 64 + (2 jam × 40) = 144. Atas 16 + ((10−5)/10 × 200) = 116.
     */
    public function testPointLandsWhereTheNumbersSay(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), self::values());

        self::assertStringContainsString('cx="144" cy="116"', $svg);
        self::assertStringContainsString('cx="224" cy="76"', $svg);
    }

    /** Dan skalanya menyebut angka yang sama dengan yang dipakai menggambar. */
    public function testExportedScaleMatchesTheDrawing(): void
    {
        $renderer = new SvgChartRenderer();
        $scale = $renderer->scale(self::spec(), self::values());

        self::assertSame(144.0, $scale->x('2026-09-29 10:00'));
        self::assertSame(116.0, $scale->y(0, 5));

        $export = $scale->export();
        self::assertSame(Geometry::LEFT, $export['left']);
        self::assertSame(40.0, $export['column_width']);
        self::assertSame('2026-09-29 08:00', $export['start']);
        self::assertSame(16.0, $export['panels'][0]['top']);
    }

    /** Dipanggil dua kali, hasilnya sama persis. */
    public function testRenderingIsDeterministic(): void
    {
        $renderer = new SvgChartRenderer();

        self::assertSame(
            $renderer->render(self::spec(), self::values()),
            $renderer->render(self::spec(), self::values())
        );
    }

    /**
     * Urutan pengetikan tidak mengubah gambarnya.
     *
     * Dua dokumen beridentik isi harus menghasilkan PDF yang sama.
     */
    public function testTypingOrderDoesNotChangeTheDrawing(): void
    {
        $terbalik = self::values();
        $terbalik['dilatation'] = array_reverse($terbalik['dilatation']);

        $renderer = new SvgChartRenderer();

        self::assertSame(
            $renderer->render(self::spec(), self::values()),
            $renderer->render(self::spec(), $terbalik)
        );
    }

    /**
     * Tanpa jam mulai dan tanpa satu titik pun, tidak ada label jam.
     *
     * Bukan jam hari ini. Membaca jam dinding membuat dokumen yang sama
     * menghasilkan dua gambar berbeda — dan itu menghapus satu-satunya alasan
     * tanda tangannya berarti.
     */
    public function testEmptyBoardNeverReadsTheClock(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), []);

        self::assertStringNotContainsString(date('Y'), $svg, 'tahun ini bocor ke gambarnya');
        self::assertSame(
            0,
            preg_match('/\d{2}:\d{2}/', $svg),
            'label jam muncul padahal awal sumbunya belum diketahui'
        );
        self::assertStringContainsString('<svg', $svg, 'papan kosong seharusnya tetap menggambar kisinya');
    }

    /**
     * Jam mulai boleh datang dari titik paling awal.
     *
     * Lembar yang sudah terisi tetap tergambar walau isian jam mulainya belum
     * disentuh.
     */
    public function testStartCanComeFromTheEarliestPoint(): void
    {
        $nilai = self::values();
        unset($nilai['start_time']);

        $scale = (new SvgChartRenderer())->scale(self::spec(), $nilai);

        self::assertSame('2026-09-29 10:00', $scale->export()['start']);
    }

    /**
     * Titik di luar sumbu tidak digambar, dan garisnya putus di situ.
     *
     * Bukan digeser ke tepi. Nilai di luar jangkauan biasanya salah ketik —
     * DJJ 1440, bukan 144. Digeser ke tepi, ia mendarat di garis teratas dan
     * terbaca sebagai pengukuran yang sah; dokumen yang ditandatangani lalu
     * memuat angka yang tidak pernah diukur siapa pun.
     */
    public function testOutOfRangePointIsNotDrawnAndBreaksTheLine(): void
    {
        $nilai = self::values();
        $nilai['dilatation'] = [
            ['x' => '2026-09-29 09:00', 'y' => 3],
            ['x' => '2026-09-29 10:00', 'y' => 99],
            ['x' => '2026-09-29 11:00', 'y' => 6],
        ];

        $svg = (new SvgChartRenderer())->render(self::spec(), $nilai);

        self::assertSame(2, substr_count($svg, '<circle'), 'titik di luar sumbu ikut tergambar');
        self::assertSame(0, substr_count($svg, '<path'), 'garisnya tidak putus di titik yang hilang');
    }

    /** Titik di luar rentang waktu sumbu juga tidak digambar. */
    public function testPointBeyondTheTimeWindowIsNotDrawn(): void
    {
        $nilai = self::values();
        $nilai['dilatation'] = [['x' => '2026-09-30 08:00', 'y' => 5]];

        self::assertSame(0, substr_count((new SvgChartRenderer())->render(self::spec(), $nilai), '<circle'));
    }

    /**
     * Titik yang berurutan benar-benar dihubungkan garis.
     *
     * Ditemukan uji mutasi: melumpuhkan penyusun garisnya tidak membuat satu
     * pun uji gagal. Yang diperiksa selama ini cuma garis yang PUTUS — dan
     * "tidak ada garis sama sekali" juga memenuhi syarat itu.
     */
    public function testConsecutivePointsAreJoinedByALine(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), self::values());

        self::assertSame(1, substr_count($svg, '<path'), 'dua titik berurutan tidak dihubungkan');
        self::assertStringContainsString('d="M144 116 L224 76"', $svg, 'garisnya tidak melewati kedua titik');
    }

    /**
     * Label jam dihitung dari awal sumbu, bukan dari jam dinding.
     *
     * Ditemukan uji mutasi: mengganti sumber waktunya dengan `new
     * DateTimeImmutable()` tidak membuat satu pun uji gagal, karena satu-satunya
     * uji yang menyentuh label memakai papan kosong — yang label jamnya memang
     * tidak digambar.
     */
    public function testHourLabelsCountFromTheStartOfTheAxis(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), self::values());

        self::assertStringContainsString('>08:00<', $svg, 'kolom pertama bukan jam mulai');
        self::assertStringContainsString('>12:00<', $svg, 'kolom ke-4 bukan empat jam sesudahnya');

        // Kolom ke-16 jatuh di tengah malam. Sekaligus membuktikan pergantian
        // hari tidak perlu ditambal: awal sumbunya membawa tanggal.
        self::assertStringContainsString('>00:00<', $svg, 'kolom terakhir tidak melewati tengah malam');

        self::assertSame(
            17,
            preg_match_all('/>\d{2}:\d{2}</', $svg),
            'jumlah label jam tidak sebanyak kolomnya'
        );
    }

    /**
     * Keluarannya XML yang sah.
     *
     * php-svg-lib mem-parse-nya sebagai XML; satu tag yang tidak tertutup
     * membuat seluruh gambar hilang dari PDF, tanpa pesan apa pun.
     */
    public function testOutputIsWellFormedXml(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), self::values());

        $sebelumnya = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $sah = $doc->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        self::assertTrue($sah, 'SVG-nya bukan XML yang sah');
    }

    /**
     * Dua batasan Dompdf dijaga di sini, bukan cuma ditulis di dokumen.
     *
     * `<pattern>` gagal DIAM-DIAM di php-svg-lib: kotaknya tetap muncul, cuma
     * terisi hitam pekat, jadi tiga tingkat arsiran tercetak sama dan tidak ada
     * yang menyadarinya. Dan `class`/`<style>` tidak diperlakukan seperti di
     * peramban.
     */
    public function testStaysWithinWhatDompdfCanDraw(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), self::values());

        self::assertStringNotContainsString('<pattern', $svg, 'arsiran pattern tidak tergambar Dompdf');
        self::assertStringNotContainsString('<style', $svg, 'CSS tidak diperlakukan seperti di peramban');
        self::assertSame(0, preg_match('/\sclass="/', $svg), 'atribut class tidak berlaku di Dompdf');
    }

    /** Judul yang mengandung tanda petik tidak merusak XML-nya. */
    public function testTitleWithQuotesIsEscaped(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [[
                'title' => 'Nadi <ibu> & "bayi"',
                'series' => [['field' => 'pulse']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($spec, []);

        self::assertStringNotContainsString('<ibu>', $svg);
        self::assertStringContainsString('&lt;ibu&gt;', $svg);
    }

    /**
     * Panel bertumpuk berbagi sumbu X yang sama.
     *
     * Inilah gunanya papan: jam yang sama di panel atas harus tepat di atas jam
     * yang sama di panel bawah.
     */
    public function testStackedPanelsShareTheSameXAxis(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 8,
            'column_width' => 30,
            'start_field' => 'start_time',
            'panels' => [
                ['height' => 90, 'y' => ['min' => 0, 'max' => 10], 'series' => [['field' => 'a']]],
                ['height' => 90, 'y' => ['min' => 0, 'max' => 10], 'series' => [['field' => 'b']]],
            ],
        ]);

        $scale = (new SvgChartRenderer())->scale($spec, ['start_time' => '2026-09-29 08:00']);

        self::assertSame($scale->x('2026-09-29 10:00'), $scale->x('2026-09-29 10:00'));
        self::assertSame(16.0, $scale->top(0));
        self::assertSame(16.0 + 90 + Geometry::PANEL_GAP, $scale->top(1));
    }

    /** Garis acuan tergambar, dan yang putus-putus memang putus-putus. */
    public function testReferenceLinesAreDrawn(): void
    {
        $spec = ChartSpec::fromArray([
            'start_field' => 'start_time',
            'panels' => [[
                'y' => ['min' => 0, 'max' => 10],
                'references' => [
                    ['from' => [0, 4], 'to' => [6, 10], 'style' => 'solid'],
                    ['from' => [4, 4], 'to' => [10, 10], 'style' => 'dashed'],
                ],
                'series' => [['field' => 'a']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($spec, ['start_time' => '2026-09-29 08:00']);

        self::assertSame(1, substr_count($svg, 'stroke-dasharray'), 'garis putus-putusnya hilang');
    }

    /**
     * Angka ditulis satu cara.
     *
     * Mengubah float jadi teks di PHP mengikuti setelan `precision` di php.ini,
     * jadi server berbeda bisa menulis "12.1" dan "12.099999999999999" untuk
     * nilai yang sama — dan keluaran ini masuk ke PDF yang ditandatangani.
     */
    public function testNumbersAreWrittenOneWay(): void
    {
        $spec = ChartSpec::fromArray([
            'column_width' => 33.333,
            'start_field' => 'start_time',
            'panels' => [['y' => ['min' => 0, 'max' => 3, 'step' => 0.1], 'series' => [['field' => 'a']]]],
        ]);

        $svg = (new SvgChartRenderer())->render($spec, [
            'start_time' => '2026-09-29 08:00',
            'a' => [['x' => '2026-09-29 09:00', 'y' => 1.5]],
        ]);

        self::assertSame(0, preg_match('/\d\.\d{3,}/', $svg), 'ada angka dengan lebih dari dua desimal');
        self::assertSame(0, preg_match('/="-0"/', $svg), 'ada nol bertanda minus');
    }

    /**
     * Tidak ada spasi sesudah koma di nama font.
     *
     * php-svg-lib tidak membaca daftar berkoma yang berspasi dan jatuh ke
     * huruf berkait. Selama berbulan-bulan label grafik tercetak berkait
     * sementara di layar tak berkait, dan tidak ada yang curiga: gambarnya
     * muncul, cuma rupanya berbeda di tempat yang jarang dibandingkan
     * berdampingan.
     *
     * Diuji enam ejaan pada Dompdf 3.1.5 + php-svg-lib, 30 Sep 2026 — lihat
     * `docs/260930-grafik-jenis-baru.md` §3. Yang dijaga di sini keluarannya,
     * bukan tetapannya: nama tetapan boleh berubah, syaratnya tidak.
     */
    public function testFontNamesCarryNoSpaceAfterTheComma(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), self::values());

        self::assertGreaterThan(0, substr_count($svg, 'font-family='), 'tidak ada teks sama sekali');
        self::assertSame(
            0,
            preg_match_all('/font-family="[^"]*,\s/', $svg),
            'ada spasi sesudah koma — label grafiknya akan tercetak berkait'
        );
    }
}
