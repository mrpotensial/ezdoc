<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Jenis panel selain garis: batang, simbol, teks — dan hiasan yang dituntut
 * lembar pemantauan sungguhan.
 *
 * Semuanya berasal dari satu lembar yang dikirim pemakai (partograf RM 78).
 * Yang diuji di sini bukan "bisa menggambar", melainkan hal-hal yang kalau
 * salah **tidak terlihat salah**:
 *
 * - arsiran yang menyebar seperti kipas, bukan garis sejajar
 * - tiga tingkat isian batang yang tercetak sama pekat
 * - nama garis acuan yang miring ke arah yang keliru
 */
final class PanelLainTest extends TestCase
{
    /**
     * @param array<string,mixed> $panel
     * @param array<string,mixed> $values
     */
    private static function gambar(array $panel, array $values, int $hours = 8): string
    {
        $spec = ChartSpec::fromArray([
            'hours' => $hours,
            'column_width' => 40,
            'start_field' => 'mulai',
            'panels' => [$panel],
        ]);

        return (new SvgChartRenderer())->render($spec, ['mulai' => '2026-09-29 08:00'] + $values);
    }

    // ── Panel simbol dan teks ────────────────────────────────────────────

    /**
     * Isi sel diletakkan di TENGAH kolomnya, bukan di garis jamnya.
     *
     * Nilainya milik kolom yang dimulai pada jam itu. Huruf yang berdiri tepat
     * di atas garis jam terbaca sebagai milik dua kolom sekaligus.
     */
    public function testSymbolSitsInTheMiddleOfItsColumn(): void
    {
        $svg = self::gambar(
            ['type' => 'symbol', 'title' => 'Air Ketuban', 'height' => 20,
             'series' => [['field' => 'ketuban']]],
            ['ketuban' => '[{"x":"2026-09-29 10:00","y":"J"}]']
        );

        // kiri 64 + (2 jam × 40) = 144, ditambah setengah kolom = 164.
        self::assertStringContainsString('x="164"', $svg);
        self::assertStringContainsString('>J<', $svg);
    }

    /** Panel sel tidak menggambar sumbu Y — angkanya tidak punya arti di situ. */
    public function testCellPanelHasNoYAxis(): void
    {
        $svg = self::gambar(
            ['type' => 'symbol', 'height' => 20, 'series' => [['field' => 'a']]],
            ['a' => '[{"x":"2026-09-29 09:00","y":"U"}]']
        );

        self::assertSame(0, preg_match('/text-anchor="end"/', $svg), 'angka sumbu Y ikut tergambar di baris sel');
    }

    /** Teks panjang tetap muat karena hurufnya lebih kecil dari simbol. */
    public function testTextPanelUsesSmallerType(): void
    {
        $simbol = self::gambar(
            ['type' => 'symbol', 'height' => 20, 'series' => [['field' => 'a']]],
            ['a' => '[{"x":"2026-09-29 09:00","y":"J"}]']
        );
        $teks = self::gambar(
            ['type' => 'text', 'height' => 20, 'series' => [['field' => 'a']]],
            ['a' => '[{"x":"2026-09-29 09:00","y":"Tidak ada"}]']
        );

        self::assertStringContainsString('font-size="9"', $simbol);
        self::assertStringContainsString('font-size="7"', $teks);
        self::assertStringContainsString('>Tidak ada<', $teks);
    }

    // ── Panel batang ─────────────────────────────────────────────────────

    /**
     * Tiga tingkat isian benar-benar berbeda.
     *
     * Inilah yang gagal diam-diam kalau `<pattern>` dipakai: ketiganya muncul,
     * ketiganya hitam pekat, dan tidak ada yang menyadari artinya hilang.
     */
    public function testThreeBarFillsAreActuallyDifferent(): void
    {
        $svg = self::gambar(
            [
                'type' => 'bar',
                'height' => 60,
                'y' => ['min' => 0, 'max' => 5, 'step' => 1],
                'levels' => ['1' => 'dots', '2' => 'hatch', '3' => 'solid'],
                'series' => [['field' => 'jumlah'], ['field' => 'lama']],
            ],
            [
                'jumlah' => '[{"x":"2026-09-29 09:00","y":3},{"x":"2026-09-29 10:00","y":4},{"x":"2026-09-29 11:00","y":5}]',
                'lama' => '[{"x":"2026-09-29 09:00","y":1},{"x":"2026-09-29 10:00","y":2},{"x":"2026-09-29 11:00","y":3}]',
            ]
        );

        // Dihitung batangnya saja: latar papan dan bingkai panel juga <rect>.
        self::assertSame(
            3,
            preg_match_all('/<rect [^>]*stroke-width="0\.8"\/>/', $svg),
            'jumlah batangnya tidak tiga'
        );
        self::assertGreaterThan(4, substr_count($svg, '<circle'), 'batang bertitik-titik tidak berisi titik');
        self::assertGreaterThan(4, substr_count($svg, '<line'), 'batang berarsir tidak berisi garis');
        self::assertStringNotContainsString('<pattern', $svg, 'arsiran memakai pattern — gagal senyap di Dompdf');
    }

    /**
     * Arsirannya garis 45° sejajar, bukan kipas dari satu sudut.
     *
     * Versi pertama menebak ujung garisnya dan menghasilkan kipas. Tergambar,
     * terlihat "berarsir", dan salah. Sekarang tiap garis memenuhi u + v = c,
     * jadi selisih x dan selisih y tiap garis harus sama besar.
     */
    public function testHatchLinesAreParallel(): void
    {
        $svg = self::gambar(
            [
                'type' => 'bar',
                'height' => 60,
                'y' => ['min' => 0, 'max' => 5, 'step' => 1],
                'levels' => ['1' => 'hatch'],
                'series' => [['field' => 'jumlah'], ['field' => 'lama']],
            ],
            [
                'jumlah' => '[{"x":"2026-09-29 09:00","y":5}]',
                'lama' => '[{"x":"2026-09-29 09:00","y":1}]',
            ]
        );

        // Hanya garis DI DALAM kelompok arsirnya; kisi panel juga <line>, dan
        // kisi memang mendatar — ikut terhitung, ujinya lulus tanpa sebab.
        self::assertSame(
            1,
            preg_match('/<g stroke="#cc0000" stroke-width="0\.8">(.*?)<\/g>/s', $svg, $kelompok),
            'kelompok arsirnya tidak ketemu'
        );
        preg_match_all(
            '/<line x1="([\d.-]+)" y1="([\d.-]+)" x2="([\d.-]+)" y2="([\d.-]+)"\/>/',
            $kelompok[1],
            $m,
            PREG_SET_ORDER
        );
        self::assertGreaterThan(3, count($m), 'arsirnya hampir tidak bergaris');

        foreach ($m as $garis) {
            $dx = (float) $garis[3] - (float) $garis[1];
            $dy = (float) $garis[4] - (float) $garis[2];
            self::assertEqualsWithDelta(
                abs($dx),
                abs($dy),
                0.01,
                'garis arsirnya tidak 45 derajat — menyebar seperti kipas'
            );
        }
    }

    /** Isian yang tidak dikenal ditolak saat spesifikasi dibaca. */
    public function testUnknownFillIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/isian batang/i');

        ChartSpec::fromArray([
            'panels' => [[
                'type' => 'bar',
                'levels' => ['1' => 'garis-garis'],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    // ── Tanda dan label ──────────────────────────────────────────────────

    /** Segitiga naik dan turun benar-benar berlawanan arah. */
    public function testTrianglesPointOppositeWays(): void
    {
        $naik = self::gambar(
            ['height' => 100, 'y' => ['min' => 0, 'max' => 10],
             'series' => [['field' => 'a', 'draw' => 'point', 'marker' => 'triangle-up']]],
            ['a' => '[{"x":"2026-09-29 09:00","y":5}]']
        );
        $turun = self::gambar(
            ['height' => 100, 'y' => ['min' => 0, 'max' => 10],
             'series' => [['field' => 'a', 'draw' => 'point', 'marker' => 'triangle-down']]],
            ['a' => '[{"x":"2026-09-29 09:00","y":5}]']
        );

        self::assertSame(1, preg_match('/<polygon points="([^"]+)"/', $naik, $a));
        self::assertSame(1, preg_match('/<polygon points="([^"]+)"/', $turun, $b));
        self::assertNotSame($a[1], $b[1], 'dua segitiga itu bentuknya sama');
    }

    /** Angka di sebelah titik hanya muncul kalau diminta. */
    public function testValueLabelsOnlyWhenAsked(): void
    {
        $tanpa = self::gambar(
            ['height' => 100, 'y' => ['min' => 100, 'max' => 200, 'step' => 10],
             'series' => [['field' => 'djj']]],
            ['djj' => '[{"x":"2026-09-29 09:00","y":140}]']
        );
        $dengan = self::gambar(
            ['height' => 100, 'y' => ['min' => 100, 'max' => 200, 'step' => 10],
             'series' => [['field' => 'djj', 'labels' => true]]],
            ['djj' => '[{"x":"2026-09-29 09:00","y":140}]']
        );

        self::assertSame(0, substr_count($tanpa, '>140<') - substr_count($tanpa, 'text-anchor="end">140<'));
        self::assertStringContainsString('text-anchor="middle">140<', $dengan);
    }

    /**
     * Nama garis acuan ditulis miring MENGIKUTI garisnya.
     *
     * Sudutnya dihitung dari garis itu sendiri. Kalau ditebak, mengubah
     * kemiringan garis membuat tulisannya meleset, dan melesetnya baru
     * ketahuan saat dicetak.
     */
    public function testReferenceLabelFollowsTheLineAngle(): void
    {
        $svg = self::gambar(
            [
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10],
                'references' => [
                    ['from' => [0, 0], 'to' => [4, 10], 'label' => 'WASPADA', 'label_on_line' => true],
                ],
                'series' => [['field' => 'a']],
            ],
            []
        );

        self::assertSame(1, preg_match('/rotate\((-?[\d.]+)\)/', $svg, $m), 'nama acuannya tidak diputar');
        // Naik 100px dalam 160px mendatar → sekitar -32 derajat (y ke bawah).
        self::assertEqualsWithDelta(-32.0, (float) $m[1], 1.0);
        self::assertStringContainsString('>WASPADA<', $svg);
    }

    /** Tanpa diminta, namanya tidak ditulis di garis. */
    public function testReferenceLabelStaysOffByDefault(): void
    {
        $svg = self::gambar(
            [
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10],
                'references' => [['from' => [0, 0], 'to' => [4, 10], 'label' => 'WASPADA']],
                'series' => [['field' => 'a']],
            ],
            []
        );

        self::assertStringNotContainsString('>WASPADA<', $svg);
    }

    // ── Garis tegak penanda waktu ────────────────────────────────────────

    /**
     * Waktunya dibaca dari field, bukan dari jam dinding.
     *
     * Yang ditandai harus ikut tersimpan bersama dokumennya, dan harus sama
     * tiap kali dokumen itu digambar ulang.
     */
    public function testVerticalMarkerComesFromAField(): void
    {
        $panel = [
            'height' => 100,
            'y' => ['min' => 0, 'max' => 10],
            'verticals' => [['at_field' => 'jam_periksa']],
            'series' => [['field' => 'a']],
        ];

        $ada = self::gambar($panel, ['jam_periksa' => '2026-09-29 10:00']);
        $kosong = self::gambar($panel, []);

        self::assertStringContainsString('>10:00<', $ada, 'kotak jamnya tidak tergambar');
        self::assertStringContainsString('x1="144" y1="16" x2="144"', $ada, 'garis tegaknya tidak di jam yang benar');

        // Tanpa isian, penanda merahnya tidak ada sama sekali. Label jam di
        // sumbu X tetap ada — itu bukan penanda.
        self::assertStringContainsString('#cc0000', $ada);
        self::assertStringNotContainsString('#cc0000', $kosong, 'penanda tergambar tanpa isian apa pun');
    }

    /** Field garis tegak ikut dihitung sebagai field papan. */
    public function testVerticalFieldCountsAsABoardField(): void
    {
        $spec = ChartSpec::fromArray([
            'start_field' => 'mulai',
            'panels' => [[
                'verticals' => [['at_field' => 'jam_periksa']],
                'series' => [['field' => 'a']],
            ]],
        ]);

        self::assertSame(['mulai', 'a', 'jam_periksa'], $spec->fieldNames());
    }

    // ── Tata letak papan ─────────────────────────────────────────────────

    /**
     * Jarak antar panel bisa dirapatkan.
     *
     * Tiga belas panel berjarak 14 memakan seperempat halaman untuk ruang
     * kosong — lembar sungguhan menempelkan barisnya.
     */
    public function testPanelGapCanBeTightened(): void
    {
        $renggang = ChartSpec::fromArray([
            'panels' => [
                ['height' => 50, 'series' => [['field' => 'a']]],
                ['height' => 50, 'series' => [['field' => 'b']]],
            ],
        ]);
        $rapat = ChartSpec::fromArray([
            'panel_gap' => 2,
            'panels' => [
                ['height' => 50, 'series' => [['field' => 'a']]],
                ['height' => 50, 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertSame(12.0, $renggang->height() - $rapat->height());
    }

    /** Panel berlegenda tetap diberi ruang, walau jaraknya dirapatkan. */
    public function testLegendPanelKeepsItsRoom(): void
    {
        $spec = ChartSpec::fromArray([
            'panel_gap' => 2,
            'panels' => [
                ['height' => 50, 'series' => [['field' => 'a']]],
                ['height' => 50, 'legend' => true, 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertSame(12.0, $spec->gapBefore(1), 'legenda akan menimpa panel di atasnya');
    }

    /** Kolom label kiri bisa dilebarkan untuk nama baris yang panjang. */
    public function testLeftColumnCanBeWidened(): void
    {
        $spec = ChartSpec::fromArray([
            'left' => 96,
            'hours' => 8,
            'column_width' => 30,
            'panels' => [['series' => [['field' => 'a']]]],
        ]);

        self::assertSame(96.0, $spec->left());
        self::assertSame(96 + (8 * 30) + 12, $spec->width());
    }

    /** Judul baris tipis ditulis mendatar; panel tinggi memakai tulisan berdiri. */
    public function testThinRowsGetHorizontalTitles(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [
                ['type' => 'symbol', 'title' => 'Air Ketuban', 'series' => [['field' => 'a']]],
                ['title' => 'Pembukaan', 'height' => 200, 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertSame('left', $spec->panels()[0]['title_align']);
        self::assertSame('rotated', $spec->panels()[1]['title_align']);
    }

    /** Kisi halus menambah garis, tanpa menambah label jam. */
    public function testSubColumnsAddMinorGridLines(): void
    {
        $satu = self::gambar(['height' => 60, 'series' => [['field' => 'a']]], [], 4);
        $empat = self::gambar(['height' => 60, 'sub_columns' => 4, 'series' => [['field' => 'a']]], [], 4);

        self::assertGreaterThan(substr_count($satu, '<line'), substr_count($empat, '<line'));
        self::assertSame(
            preg_match_all('/>\d{2}:\d{2}</', $satu),
            preg_match_all('/>\d{2}:\d{2}</', $empat),
            'kisi halus ikut menambah label jam'
        );
    }
}
