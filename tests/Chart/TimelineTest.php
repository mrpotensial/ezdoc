<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SvgChartRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Panel timeline: tiap deret satu jalur, nilainya rentang.
 *
 * ## Rentang yang melewati tepi dipotong, bukan dibuang
 *
 * Aturan "nilai di luar sumbu tidak digambar" sengaja tidak berlaku di sini,
 * dan pengecualiannya punya alasan yang berbeda arah:
 *
 * - Titik di luar sumbu hampir selalu salah ketik. Menggambarnya di tepi
 *   memasukkan angka yang tidak pernah diukur ke dokumen yang ditandatangani.
 * - Rentang yang mulai sebelum lembar ini dibuka adalah keadaan yang **lazim**
 *   — infus memang sudah jalan sejak semalam. Membuangnya menghilangkan obat
 *   yang benar-benar diberikan.
 *
 * Jadi rentang dipotong di tepi, dan ujung yang terpotong **ditandai takik**:
 * "mulai lebih awal" harus terbaca berbeda dari "mulai tepat di sini".
 *
 * Lihat `docs/260930-grafik-jenis-baru.md` §4 dan §6.5.
 */
final class TimelineTest extends TestCase
{
    /**
     * Papan 4 kolom: kiri 64, lebar kolom 40, jadi tepi kanan 224.
     * Panel setinggi 48 mulai y 16 → dua jalur setinggi 24 (16–40 dan 40–64).
     * Satu baris per jalur → batangnya y 18, tinggi 20.
     */
    private static function spec(int $jumlahDeret = 2): ChartSpec
    {
        $series = [
            ['field' => 'infus', 'title' => 'Infus', 'color' => '#1d4ed8'],
            ['field' => 'obat', 'title' => 'Obat', 'color' => '#16a34a'],
        ];

        return ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'timeline',
                'height' => 48,
                'series' => array_slice($series, 0, $jumlahDeret),
            ]],
        ]);
    }

    /** @return array<int,array<int,string>> */
    private static function batang(string $svg): array
    {
        preg_match_all(
            '/<rect x="([\d.\-]+)" y="([\d.\-]+)" width="([\d.\-]+)" height="([\d.\-]+)" rx="2"'
            . ' fill="(#[0-9a-f]{6})"/',
            $svg,
            $m,
            PREG_SET_ORDER
        );

        return $m;
    }

    private static function takik(string $svg): int
    {
        return preg_match_all('/<path d="M[\d.\-]+ [\d.\-]+ L[\d.\-]+ [\d.\-]+ L[\d.\-]+ [\d.\-]+ Z"/', $svg);
    }

    // ── Bentuk dasar ─────────────────────────────────────────────────────

    /**
     * Rentang tertutup jadi satu batang, dari jam mulai ke jam selesai.
     *
     * 08.00 → 10.00 pada kolom selebar 40: x 64 sampai 144.
     */
    public function testAClosedRangeBecomesOneBar(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'RL 500']],
        ]);

        $batang = self::batang($svg);
        self::assertCount(1, $batang);
        self::assertSame(['64', '18', '80', '44'], array_slice($batang[0], 1, 4));
        self::assertSame('#1d4ed8', $batang[0][5]);
        self::assertSame(0, self::takik($svg), 'ada takik padahal tidak ada ujung yang terpotong');
    }

    /** Tiap deret satu jalur, tingginya dibagi rata. */
    public function testEachSeriesGetsItsOwnLane(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => 'A']],
            'obat' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => 'B']],
        ]);

        $batang = self::batang($svg);
        self::assertCount(2, $batang);
        self::assertSame('18', $batang[0][2], 'jalur pertama');
        self::assertSame('42', $batang[1][2], 'jalur kedua tidak di bawah jalur pertama');
    }

    /** Nama deret jadi label jalur di kolom kiri. */
    public function testTheSeriesNameLabelsTheLane(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => '']],
        ]);

        self::assertSame(1, preg_match('/text-anchor="end">Infus</', $svg));
        self::assertSame(1, preg_match('/text-anchor="end">Obat</', $svg));
    }

    // ── Ujung yang terpotong ─────────────────────────────────────────────

    /**
     * Rentang terbuka digambar sampai tepi kanan, dengan takik.
     *
     * Infus yang masih jalan tidak punya jam selesai. Tanpa takiknya, batang
     * yang berhenti di tepi terbaca sebagai "selesai tepat di akhir lembar" —
     * padahal artinya "belum selesai".
     */
    public function testAnOpenRangeRunsToTheEdgeAndIsMarked(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 10:00', 'x2' => false, 'y' => 'jalan']],
        ]);

        $batang = self::batang($svg);
        self::assertCount(1, $batang);
        // Dari x 144 sampai tepi kanan 224.
        self::assertSame(['144', '18', '80'], array_slice($batang[0], 1, 3));
        self::assertSame(1, self::takik($svg), 'ujung terbukanya tidak ditandai');
    }

    /**
     * Rentang yang mulai sebelum sumbu dipotong di kiri, dengan takik.
     *
     * Bukan dibuang: obatnya benar-benar diberikan, cuma mulainya sebelum
     * lembar ini dibuka.
     */
    public function testARangeStartingBeforeTheAxisIsClippedAndMarked(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 05:00', 'x2' => '2026-09-29 09:00', 'y' => 'semalam']],
        ]);

        $batang = self::batang($svg);
        self::assertCount(1, $batang, 'rentang yang mulai lebih awal ikut hilang');
        self::assertSame('64', $batang[0][1], 'tidak dipotong di tepi kiri');
        self::assertSame('40', $batang[0][3]);
        self::assertSame(1, self::takik($svg), 'ujung kirinya tidak ditandai');
    }

    /** Dan yang berakhir sesudah sumbu dipotong di kanan. */
    public function testARangeEndingAfterTheAxisIsClipped(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 10:00', 'x2' => '2026-09-29 20:00', 'y' => 'lama']],
        ]);

        $batang = self::batang($svg);
        self::assertCount(1, $batang);
        self::assertSame('144', $batang[0][1]);
        self::assertSame('80', $batang[0][3], 'tidak berhenti di tepi kanan');
    }

    /**
     * Rentang yang kedua ujungnya di luar sumbu, di sisi yang sama, tidak
     * digambar — ia tidak menyentuh lembar ini sama sekali.
     */
    public function testARangeEntirelyOutsideIsNotDrawn(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 04:00', 'x2' => '2026-09-29 06:00', 'y' => 'kemarin']],
        ]);

        self::assertSame([], self::batang($svg));
    }

    // ── Bertindihan ──────────────────────────────────────────────────────

    /**
     * Dua rentang yang bertindih di satu jalur ditaruh di baris terpisah.
     *
     * Menyembunyikan yang kedua menghilangkan obat kedua tanpa jejak; menimpa
     * keduanya membuat keduanya tidak terbaca.
     */
    public function testOverlappingRangesGetTheirOwnRow(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 11:00', 'y' => 'A'],
                ['x' => '2026-09-29 09:00', 'x2' => '2026-09-29 12:00', 'y' => 'B'],
            ],
        ]);

        $batang = self::batang($svg);
        self::assertCount(2, $batang);
        self::assertNotSame($batang[0][2], $batang[1][2], 'keduanya di baris yang sama — saling menimpa');
    }

    /** Yang tidak bertindih tetap satu baris, setinggi jalurnya. */
    public function testRangesThatDoNotOverlapShareOneRow(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => 'A'],
                ['x' => '2026-09-29 10:00', 'x2' => '2026-09-29 11:00', 'y' => 'B'],
            ],
        ]);

        $batang = self::batang($svg);
        self::assertCount(2, $batang);
        self::assertSame($batang[0][2], $batang[1][2], 'dipisah padahal tidak bertindih');
        self::assertSame($batang[0][4], $batang[1][4]);
    }

    // ── Label ────────────────────────────────────────────────────────────

    public function testALabelThatFitsIsWrittenWhole(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 12:00', 'y' => 'RL 500']],
        ]);

        self::assertStringContainsString('>RL 500<', $svg);
    }

    /**
     * Label yang tidak muat dipotong, bukan dikecilkan hurufnya.
     *
     * Satu papan dengan tujuh ukuran huruf lebih sulit dibaca daripada satu
     * label terpotong.
     */
    public function testALabelThatDoesNotFitIsTruncated(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [[
                'x' => '2026-09-29 08:00',
                'x2' => '2026-09-29 09:00',
                'y' => 'Ceftriaxone 1g',
            ]],
        ]);

        self::assertSame(1, preg_match('/>([^<]*…)</u', $svg, $m), 'labelnya tidak dipotong');
        self::assertStringStartsWith('Ce', $m[1]);
        self::assertSame(1, substr_count($svg, 'font-size="6.5"'), 'ukuran hurufnya ikut diubah');
    }

    /**
     * Batang yang terlalu PENDEK tidak diberi label.
     *
     * Jalur yang tipis — banyak deret di panel yang sama — menghasilkan batang
     * setinggi empat piksel. Huruf setinggi 6,5 di dalamnya meluber ke jalur
     * di atas dan di bawahnya, dan yang terbaca di situ jadi milik jalur yang
     * salah. Lebarnya boleh cukup; tingginya yang tidak.
     */
    public function testAVeryShortBarGetsNoLabel(): void
    {
        $banyakJalur = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'timeline',
                'height' => 48,
                'series' => [
                    ['field' => 'a', 'title' => 'A'],
                    ['field' => 'b', 'title' => 'B'],
                    ['field' => 'c', 'title' => 'C'],
                    ['field' => 'd', 'title' => 'D'],
                    ['field' => 'e', 'title' => 'E'],
                    ['field' => 'f', 'title' => 'F'],
                ],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($banyakJalur, [
            'mulai' => '2026-09-29 08:00',
            'a' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 12:00', 'y' => 'lebar, pendek']],
        ]);

        $batang = self::batang($svg);
        self::assertCount(1, $batang);
        self::assertLessThan(7.0, (float) $batang[0][4], 'batangnya ternyata tidak pendek — ujinya yang salah');
        // Labelnya sengaja pendek: yang lebih dari batas panjang akan dibuang
        // SeriesValues dan ujinya lulus tanpa pernah menyentuh penjaga tinggi.
        self::assertLessThanOrEqual(16, mb_strlen('lebar, pendek'));
        self::assertSame(0, substr_count($svg, 'font-size="6.5"'), 'label meluber ke jalur sebelah');
    }

    /** Dan batang yang lebarnya cuma cukup untuk satu-dua huruf pun tidak. */
    public function testABarTooNarrowToSayAnythingGetsNoLabel(): void
    {
        $sempit = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 14,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'timeline',
                'height' => 48,
                'series' => [['field' => 'infus', 'title' => 'Infus']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($sempit, [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => 'RL 500']],
        ]);

        self::assertCount(1, self::batang($svg));
        self::assertSame(0, substr_count($svg, 'font-size="6.5"'), 'satu huruf plus titik-titik bukan label');
    }

    /** Batang yang terlalu sempit tidak diberi label sama sekali. */
    public function testAVeryNarrowBarGetsNoLabel(): void
    {
        $sempit = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 6,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'timeline',
                'height' => 48,
                'series' => [['field' => 'infus', 'title' => 'Infus', 'color' => '#1d4ed8']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($sempit, [
            'mulai' => '2026-09-29 08:00',
            'infus' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => 'RL 500']],
        ]);

        self::assertCount(1, self::batang($svg));
        self::assertSame(0, substr_count($svg, 'font-size="6.5"'));
    }

    // ── Batas-batasnya ───────────────────────────────────────────────────

    /** Nilai berupa titik diabaikan panel timeline — ia bukan rentang. */
    public function testPlainPointsAreIgnored(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(1), [
            'mulai' => '2026-09-29 08:00',
            'infus' => [
                ['x' => '2026-09-29 08:00', 'y' => 5],
                ['x' => '2026-09-29 09:00', 'x2' => '2026-09-29 10:00', 'y' => 'obat'],
            ],
        ]);

        self::assertCount(1, self::batang($svg), 'titik ikut digambar sebagai rentang');
    }

    /**
     * Judul panel timeline selalu berdiri, berapa pun tingginya.
     *
     * Kolom kiri bagian dalam sudah dipakai nama jalur. Judul mendatar
     * tertulis menimpanya, dan di layar keduanya sama-sama hitam — terbaca
     * sebagai satu kata acak, bukan sebagai dua tulisan yang bertabrakan.
     * Ketahuan dari membaca hasil cetak, bukan dari uji.
     */
    public function testATimelineTitleNeverSitsInTheLaneLabelColumn(): void
    {
        self::assertSame('rotated', self::spec()->panels()[0]['title_align']);

        $pendek = ChartSpec::fromArray([
            'panels' => [[
                'type' => 'timeline',
                'height' => 30,
                'title' => 'Obat',
                'series' => [['field' => 'a', 'title' => 'Infus']],
            ]],
        ]);
        self::assertSame('rotated', $pendek->panels()[0]['title_align'], 'panel pendek jatuh ke judul mendatar');

        // Baris sel tetap mendatar — aturannya cuma berubah untuk timeline.
        $sel = ChartSpec::fromArray([
            'panels' => [['type' => 'symbol', 'series' => [['field' => 'a']]]],
        ]);
        self::assertSame('left', $sel->panels()[0]['title_align']);
    }

    /** Panel timeline tidak punya sumbu Y berangka. */
    public function testATimelinePanelHasNoYAxis(): void
    {
        self::assertFalse(self::spec()->panels()[0]['y_axis']);
    }

    /** Papan tanpa nilai tetap menggambar kisi dan label jalurnya. */
    public function testAnEmptyTimelineStillDrawsItsLanes(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), ['mulai' => '2026-09-29 08:00']);

        self::assertSame([], self::batang($svg));
        self::assertStringContainsString('>Infus<', $svg);
    }

    /** Keluarannya tetap sama byte demi byte. */
    public function testStillDeterministic(): void
    {
        $r = new SvgChartRenderer();
        $nilai = [
            'mulai' => '2026-09-29 08:00',
            'infus' => [
                ['x' => '2026-09-29 05:00', 'x2' => '2026-09-29 09:00', 'y' => 'A'],
                ['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'B'],
            ],
        ];

        self::assertSame($r->render(self::spec(), $nilai), $r->render(self::spec(), $nilai));
    }
}
