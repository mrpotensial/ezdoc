<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\Scale;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Dua cara gambar baru: tangga dan arsiran.
 *
 * ## Kenapa tangga bukan hiasan
 *
 * Tetes/menit, dosis, aliran oksigen: angkanya **berlaku sepanjang rentang**,
 * bukan pengukuran sesaat. Garis lurus di situ berbohong — ia menggambarkan
 * perubahan bertahap dari 20 ke 40 tetes/menit sepanjang dua jam, sedangkan
 * yang terjadi 20 sampai pukul 10, lalu 40. Dokumen yang ditandatangani lalu
 * memperlihatkan nilai yang tidak pernah disetel siapa pun.
 *
 * Karena itu yang diuji di sini bukan "ada garisnya", melainkan **ada simpul
 * di sudutnya** — tanpa simpul itu, tangga dan garis lurus menghasilkan
 * gambar yang sama.
 */
final class StepAndAreaTest extends TestCase
{
    /**
     * Papan sederhana, angkanya mudah dihitung di kepala.
     *
     * kiri 64, lebar kolom 40, panel setinggi 100 dengan sumbu 0–10.
     *   jam 0 → x 64    nilai 10 → y 16
     *   jam 2 → x 144   nilai  5 → y 66
     *   jam 4 → x 224   nilai  0 → y 116
     */
    private static function spec(string $draw): ChartSpec
    {
        return ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'series' => [['field' => 'tetes', 'draw' => $draw, 'color' => '#cc0000']],
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private static function nilai(): array
    {
        return [
            'mulai' => '2026-09-29 08:00',
            'tetes' => [
                ['x' => '2026-09-29 08:00', 'y' => 2],
                ['x' => '2026-09-29 10:00', 'y' => 8],
            ],
        ];
    }

    private static function jalur(string $svg): string
    {
        self::assertSame(1, preg_match('/<path d="([^"]+)" fill="none"/', $svg, $m), 'tidak ada garis');

        return $m[1];
    }

    // ── Tangga ───────────────────────────────────────────────────────────

    /**
     * Tangga menahan nilainya, lalu berubah tegak lurus pada waktunya.
     *
     * 2 di jam 0 (x 64, y 96) bertahan sampai jam 2 (x 144), baru naik ke 8
     * (y 36). Simpul tengahnya (144, 96) itulah tangganya.
     */
    public function testAStepHoldsItsValueThenTurnsAtTheRecordedTime(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('step'), self::nilai());

        self::assertSame('M64 96 L144 96 L144 36', self::jalur($svg));
    }

    /**
     * Dan garis lurus memang berbeda — kalau tidak, ujinya tidak menguji apa pun.
     */
    public function testAPlainLineHasNoCornerNode(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('line'), self::nilai());

        self::assertSame('M64 96 L144 36', self::jalur($svg));
    }

    /** Tangganya berubah SESUDAH waktunya, bukan sebelum. */
    public function testTheStepTurnsAfterTheReadingNotBefore(): void
    {
        $jalur = self::jalur((new SvgChartRenderer())->render(self::spec('step'), self::nilai()));

        // Sudutnya ada di x titik KEDUA dengan y titik PERTAMA. Step-before
        // akan menaruhnya di x pertama dengan y kedua: 'M64 96 L64 36 L144 36'.
        self::assertStringContainsString('L144 96', $jalur, 'tangganya step-before — nilai baru berlaku mundur');
        self::assertStringNotContainsString('L64 36', $jalur);
    }

    /** `point+step` juga menggambar tandanya. */
    public function testPointStepDrawsMarkersToo(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('point+step'), self::nilai());

        self::assertSame('M64 96 L144 96 L144 36', self::jalur($svg));
        self::assertSame(2, substr_count($svg, '<circle'), 'tandanya bukan dua');
    }

    /** `step` tanpa titik tidak menggambar tanda. */
    public function testPlainStepDrawsNoMarkers(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('step'), self::nilai());

        self::assertSame(0, substr_count($svg, '<circle'));
    }

    /**
     * Pengukuran yang hilang tetap memutus tangganya.
     *
     * Aturan yang sama seperti garis biasa: tangga yang melewati lubang
     * menyatakan nilainya bertahan sepanjang waktu yang tidak diamati
     * siapa pun.
     */
    public function testAGapStillBreaksTheStep(): void
    {
        $nilai = self::nilai();
        $nilai['tetes'][] = ['x' => '2026-09-29 20:00', 'y' => 5]; // di luar sumbu 4 jam

        $svg = (new SvgChartRenderer())->render(self::spec('step'), $nilai);

        self::assertSame(1, substr_count($svg, 'fill="none" stroke="#cc0000"'), 'garisnya tidak terputus');
    }

    // ── Arsiran ──────────────────────────────────────────────────────────

    /**
     * Bidangnya tertutup sampai dasar sumbu, dan setengah tembus.
     *
     * Sumbu 0–10, jadi dasarnya nol → y 116. Bentuknya: turun ke dasar di x
     * awal, ikuti garisnya, lalu turun lagi ke dasar di x akhir.
     */
    public function testAnAreaClosesDownToTheBaseline(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('area'), self::nilai());

        self::assertSame(
            1,
            preg_match('/<path d="([^"]+)" fill="#cc0000" fill-opacity="0.22"/', $svg, $m),
            'tidak ada bidang terisi'
        );
        self::assertSame('M64 116 L64 96 L144 36 L144 116 Z', $m[1]);
    }

    /**
     * Dasarnya tepi terdekat kalau nol di luar jangkauan.
     *
     * Panel suhu 35–40: bidang yang terisi sampai nol akan menjulur jauh di
     * luar panelnya, menutupi panel-panel di bawahnya.
     */
    public function testTheBaselineIsTheNearestEdgeWhenZeroIsOutOfRange(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 35, 'max' => 40, 'step' => 1],
                'series' => [['field' => 'suhu', 'draw' => 'area', 'color' => '#cc0000']],
            ]],
        ]);
        $scale = Scale::create($spec, '2026-09-29 08:00');

        // Dasar panel = y untuk nilai 35 = 16 + 100 = 116.
        self::assertSame(116.0, $scale->baselineY(0));

        $svg = (new SvgChartRenderer())->render($spec, [
            'mulai' => '2026-09-29 08:00',
            'suhu' => [
                ['x' => '2026-09-29 08:00', 'y' => 37],
                ['x' => '2026-09-29 10:00', 'y' => 38],
            ],
        ]);

        self::assertStringContainsString('M64 116 L64', $svg);
        self::assertStringNotContainsString('cy="516"', $svg, 'bidangnya menjulur ke luar panel');
    }

    /**
     * Panel yang melewati nol terisi DARI nol, ke atas dan ke bawah.
     *
     * Balans cairan berjalan dari −1000 sampai +2000: yang menarik dibaca
     * selisihnya terhadap nol, bukan terhadap dasar panel. Bidang yang terisi
     * dari −1000 membuat hari yang seimbang terlihat sama penuhnya dengan hari
     * yang kelebihan satu liter.
     *
     * Aturan `max(min, min(max, 0))` cuma berbeda dari `min` di panel seperti
     * ini — dan tanpa uji ini keduanya tidak terbedakan.
     */
    public function testAPanelStraddlingZeroFillsFromZero(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 120,
                'y' => ['min' => -1000, 'max' => 2000, 'step' => 500],
                'series' => [['field' => 'balans', 'draw' => 'area', 'color' => '#cc0000']],
            ]],
        ]);
        $scale = Scale::create($spec, '2026-09-29 08:00');

        // Nol di panel −1000..2000 setinggi 120: 16 + ((2000−0)/3000 × 120) = 96.
        self::assertSame(96.0, $scale->baselineY(0), 'dasarnya bukan nol');
        self::assertNotSame($scale->y(0, -1000), $scale->baselineY(0), 'dasarnya jatuh ke tepi bawah');

        $svg = (new SvgChartRenderer())->render($spec, [
            'mulai' => '2026-09-29 08:00',
            'balans' => [
                ['x' => '2026-09-29 08:00', 'y' => -500],
                ['x' => '2026-09-29 10:00', 'y' => 1000],
            ],
        ]);

        self::assertStringContainsString('M64 96 L64 116 L144 56 L144 96 Z', $svg);
    }

    /** Bidang digambar lebih dulu supaya garisnya tidak ikut pudar. */
    public function testTheAreaIsDrawnBeneathItsOwnLine(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('point+area'), self::nilai());

        $bidang = strpos($svg, 'fill-opacity="0.22"');
        $garis = strpos($svg, 'fill="none" stroke="#cc0000"');

        self::assertNotFalse($bidang);
        self::assertNotFalse($garis);
        self::assertLessThan($garis, $bidang, 'bidangnya digambar di atas garisnya sendiri');
    }

    /** Satu titik sendirian tidak membentuk bidang. */
    public function testOnePointMakesNoArea(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('point+area'), [
            'mulai' => '2026-09-29 08:00',
            'tetes' => [['x' => '2026-09-29 08:00', 'y' => 2]],
        ]);

        self::assertStringNotContainsString('fill-opacity', $svg);
        self::assertSame(1, substr_count($svg, '<circle'), 'titiknya ikut hilang');
    }

    // ── Bentuk spesifikasi ───────────────────────────────────────────────

    public function testTheNewDrawModesAreAccepted(): void
    {
        foreach (['point+step', 'step', 'point+area', 'area'] as $draw) {
            self::assertSame($draw, self::spec($draw)->panels()[0]['series'][0]['draw']);
        }
    }

    public function testAnUnknownDrawModeIsStillRejected(): void
    {
        $this->expectException(ValidationException::class);

        self::spec('zigzag');
    }

    /** Keluarannya tetap sama byte demi byte. */
    public function testStillDeterministic(): void
    {
        $r = new SvgChartRenderer();

        foreach (['step', 'area', 'point+step', 'point+area'] as $draw) {
            self::assertSame(
                $r->render(self::spec($draw), self::nilai()),
                $r->render(self::spec($draw), self::nilai()),
                $draw
            );
        }
    }

    /**
     * Tidak ada `<pattern>` di mana pun.
     *
     * Dompdf memberinya hitam pekat, diam-diam. Arsiran bidang ini memakai
     * `fill-opacity`, bukan pola.
     */
    public function testNoPatternIsUsed(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec('area'), self::nilai());

        self::assertStringNotContainsString('<pattern', $svg);
    }
}
