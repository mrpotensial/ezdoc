<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Garis acuan berbentuk kurva.
 *
 * Kurva pertumbuhan WHO tidak pernah lurus, dan mendekatinya dengan garis
 * lurus menggeser batasnya justru di umur yang paling sering dibaca. Kunci
 * `points` menerima daftar titik; `from`/`to` yang lama tidak berubah sama
 * sekali.
 *
 * Lihat `docs/260930-grafik-jenis-baru.md` §6.6.
 */
final class AcuanBerkurvaTest extends TestCase
{
    /**
     * Papan 12 kolom: kiri 64, lebar kolom 20, jadi kolom n ada di 64 + 20n.
     * Panel 0–10 setinggi 100 mulai y 16: nilai v ada di 16 + ((10−v) × 10).
     */
    private static function spec(array $acuan): ChartSpec
    {
        return ChartSpec::fromArray([
            'hours' => 12,
            'column_width' => 20,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10, 'step' => 2],
                'references' => [$acuan],
                'series' => [['field' => 'berat']],
            ]],
        ]);
    }

    private static function jalur(string $svg): string
    {
        self::assertSame(
            1,
            preg_match('/<path d="([^"]+)" fill="none" stroke="#16a34a"/', $svg, $m),
            'tidak ada kurva acuan'
        );

        return $m[1];
    }

    // ── Bentuknya ────────────────────────────────────────────────────────

    /**
     * Daftar titik jadi satu polyline, titik demi titik.
     *
     * (0, 3) → x 64, y 86   (3, 6) → x 124, y 56   (6, 8) → x 184, y 36
     */
    public function testPointsBecomeAPolyline(): void
    {
        $svg = (new SvgChartRenderer())->render(
            self::spec(['points' => [[0, 3], [3, 6], [6, 8]], 'color' => '#16a34a']),
            ['mulai' => '2026-09-29 08:00']
        );

        self::assertSame('M64 86 L124 56 L184 36', self::jalur($svg));
    }

    /** Dan acuan bentuk lama tetap digambar sebagai satu `<line>`. */
    public function testTheOldStraightFormIsUnchanged(): void
    {
        $svg = (new SvgChartRenderer())->render(
            self::spec(['from' => [0, 3], 'to' => [6, 8], 'color' => '#16a34a']),
            ['mulai' => '2026-09-29 08:00']
        );

        self::assertSame(1, preg_match('/<line x1="64" y1="86" x2="184" y2="36"/', $svg));
        self::assertSame(0, preg_match('/<path d="M64 86/', $svg), 'acuan lurus ikut jadi path');
    }

    /** Gaya putus-putus tetap berlaku di kurva. */
    public function testDashedStillWorks(): void
    {
        $svg = (new SvgChartRenderer())->render(
            self::spec(['points' => [[0, 3], [6, 8]], 'style' => 'dashed', 'color' => '#16a34a']),
            ['mulai' => '2026-09-29 08:00']
        );

        self::assertStringContainsString('stroke-dasharray="5 3"', $svg);
    }

    /**
     * Titik yang nilainya di luar sumbu DIPOTONG ke tepi, bukan dibuang.
     *
     * Aturan "nilai di luar sumbu tidak digambar" berlaku untuk pengukuran —
     * yang di luar jangkauan biasanya salah ketik. Acuan bukan pengukuran: ia
     * garis bantu yang memang boleh melewati tepi panel, dan membuang
     * titiknya memutus kurvanya persis di tempat yang paling menarik.
     */
    public function testPointsBeyondTheAxisAreClippedNotDropped(): void
    {
        $svg = (new SvgChartRenderer())->render(
            self::spec(['points' => [[0, -5], [3, 5], [6, 99]], 'color' => '#16a34a']),
            ['mulai' => '2026-09-29 08:00']
        );

        // −5 → tepi bawah panel (116); 99 → tepi atas (16).
        self::assertSame('M64 116 L124 66 L184 16', self::jalur($svg));
    }

    /**
     * Namanya mengikuti potongan di TENGAH kurvanya.
     *
     * Potongan pertama sering yang paling curam, dan nama yang miring
     * mengikuti potongan itu terbaca sebagai nama kurva yang lain.
     */
    public function testTheLabelFollowsTheMiddleSegment(): void
    {
        $svg = (new SvgChartRenderer())->render(
            self::spec([
                // Potongan pertama curam, yang tengah datar.
                'points' => [[0, 0], [1, 8], [5, 8], [6, 8]],
                'label' => 'MEDIAN',
                'label_on_line' => true,
                'color' => '#16a34a',
            ]),
            ['mulai' => '2026-09-29 08:00']
        );

        self::assertSame(1, preg_match('/rotate\(([\d.\-]+)\)[\s\S]{0,200}?>MEDIAN</', $svg, $m), 'nama tidak tertulis');
        self::assertSame(0.0, (float) $m[1], 'namanya mengikuti potongan yang curam, bukan yang tengah');
    }

    /** Tanpa diminta, namanya tidak ditulis di garisnya. */
    public function testTheLabelStaysOffTheLineUnlessAsked(): void
    {
        $svg = (new SvgChartRenderer())->render(
            self::spec(['points' => [[0, 3], [6, 8]], 'label' => 'MEDIAN', 'color' => '#16a34a']),
            ['mulai' => '2026-09-29 08:00']
        );

        self::assertStringNotContainsString('>MEDIAN<', $svg);
    }

    // ── Yang ditolak ─────────────────────────────────────────────────────

    /**
     * Kolom titiknya wajib naik terus.
     *
     * Kurva acuan adalah fungsi dari sumbunya — berat menurut umur. Yang
     * berbalik arah bukan kurva acuan melainkan daftar yang salah urut, dan
     * menggambarnya menghasilkan zigzag yang tetap terbaca sebagai batas.
     */
    public function testColumnsMustIncrease(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('naik terus');

        self::spec(['points' => [[0, 3], [6, 8], [3, 5]]]);
    }

    /**
     * Dua titik di kolom yang SAMA juga ditolak.
     *
     * Bukan sekadar "tidak mundur": dua nilai di umur yang sama membuat kurva
     * melompat tegak lurus, dan lompatan itu terbaca sebagai batas yang
     * berubah mendadak. Kurva acuan adalah fungsi — satu kolom, satu nilai.
     */
    public function testTwoPointsInTheSameColumnAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('naik terus');

        self::spec(['points' => [[0, 3], [3, 6], [3, 8]]]);
    }

    public function testTwoPointsAreTheMinimum(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sedikitnya dua titik');

        self::spec(['points' => [[0, 3]]]);
    }

    public function testEachPointIsTwoNumbers(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dua angka [kolom, nilai]');

        self::spec(['points' => [[0, 3], ['enam', 8]]]);
    }

    /** Tanpa `points` MAUPUN `from`/`to`, pesannya menyebut keduanya. */
    public function testTheErrorMentionsBothForms(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("atau pakai 'points'");

        self::spec(['label' => 'lupa']);
    }

    // ── Sisanya ──────────────────────────────────────────────────────────

    /** Ujung kurvanya ikut jadi `from`/`to`, supaya legenda tetap bekerja. */
    public function testTheEndsAreStillAvailableAsFromAndTo(): void
    {
        $acuan = self::spec(['points' => [[0, 3], [3, 6], [6, 8]]])->panels()[0]['references'][0];

        self::assertSame(0.0, $acuan['x1']);
        self::assertSame(3.0, $acuan['y1']);
        self::assertSame(6.0, $acuan['x2']);
        self::assertSame(8.0, $acuan['y2']);
    }

    /** Keluarannya tetap sama byte demi byte. */
    public function testStillDeterministic(): void
    {
        $r = new SvgChartRenderer();
        $spec = self::spec(['points' => [[0, 3], [3, 6], [6, 8]], 'color' => '#16a34a']);

        self::assertSame(
            $r->render($spec, ['mulai' => '2026-09-29 08:00']),
            $r->render($spec, ['mulai' => '2026-09-29 08:00'])
        );
    }
}
