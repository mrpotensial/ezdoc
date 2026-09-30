<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Legenda: di atas panel, di bawahnya, atau di kolom kiri.
 *
 * Ketiganya ada di satu lembar yang sama — lembar pemantauan sungguhan yang
 * dikirim pemakai. Pembukaan serviks berlegenda di atas, nadi & tekanan darah
 * di bawah, dan keterangan isian kontraksi bertumpuk di kolom kiri.
 *
 * Yang paling mudah salah bukan menggambarnya, melainkan RUANGNYA: legenda
 * ditulis di luar bingkai panel, jadi tanpa jarak tambahan ia menimpa baris
 * tetangganya — dan tumpang-tindihnya baru terlihat saat dicetak.
 */
final class LegendaTest extends TestCase
{
    /**
     * @param array<string,mixed> $panel
     * @param array<string,mixed> $values
     */
    private static function gambar(array $panel, array $values = []): string
    {
        $spec = ChartSpec::fromArray([
            'hours' => 6,
            'column_width' => 40,
            'start_field' => 'mulai',
            'panels' => [$panel],
        ]);

        return (new SvgChartRenderer())->render($spec, ['mulai' => '2026-09-29 08:00'] + $values);
    }

    /** @return array<string,mixed> */
    private static function panelGaris(string $legend): array
    {
        return [
            'height' => 80,
            'y' => ['min' => 0, 'max' => 10],
            'legend' => $legend,
            'series' => [['field' => 'a', 'title' => 'Pembukaan', 'marker' => 'cross']],
        ];
    }

    /** Legenda atas berada di atas bingkai panel; legenda bawah di bawahnya. */
    public function testAboveAndBelowSitOnOppositeSides(): void
    {
        $atas = self::gambar(self::panelGaris('above'));
        $bawah = self::gambar(self::panelGaris('below'));

        self::assertSame(1, preg_match('/y="([\d.]+)"[^>]*>Pembukaan</', $atas, $a));
        self::assertSame(1, preg_match('/y="([\d.]+)"[^>]*>Pembukaan</', $bawah, $b));

        // Panel pertama selalu dimulai di 16 dan tingginya 80, jadi bingkainya
        // membentang 16..96. Legendanya harus di luar rentang itu.
        self::assertLessThan(16.0, (float) $a[1], 'legenda "above" tidak di atas panel');
        self::assertGreaterThan(96.0, (float) $b[1], 'legenda "below" tidak di bawah panel');
    }

    /**
     * `legend: true` tetap berarti "di atas".
     *
     * Spesifikasi yang sudah ditulis sebelum letaknya bisa dipilih tidak boleh
     * mendadak kehilangan legendanya.
     */
    public function testTrueStillMeansAbove(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [['legend' => true, 'series' => [['field' => 'a']]]],
        ]);

        self::assertSame('above', $spec->panels()[0]['legend']);
    }

    /** Tanpa legenda, tidak ada ruang tambahan yang dipesan. */
    public function testNoLegendNoExtraRoom(): void
    {
        $polos = ChartSpec::fromArray([
            'panel_gap' => 2,
            'panels' => [
                ['height' => 50, 'series' => [['field' => 'a']]],
                ['height' => 50, 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertSame(2.0, $polos->gapBefore(1));
    }

    /**
     * Panel SESUDAH legenda bawah juga diberi ruang.
     *
     * Ini yang terlewat mula-mula: ruang hanya dipesan untuk legenda "above",
     * jadi legenda "below" menimpa baris di bawahnya — terlihat di hasil cetak
     * sebagai tulisan yang menumpuk garis.
     */
    public function testRoomIsReservedAfterABelowLegend(): void
    {
        $spec = ChartSpec::fromArray([
            'panel_gap' => 2,
            'panels' => [
                ['height' => 50, 'legend' => 'below', 'series' => [['field' => 'a']]],
                ['height' => 50, 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertSame(12.0, $spec->gapBefore(1), 'legenda bawah akan menimpa baris berikutnya');
    }

    /** Legenda kiri menggambar contoh isian, bukan sekadar warna. */
    public function testLeftLegendDrawsTheActualFills(): void
    {
        $svg = self::gambar([
            'type' => 'bar',
            'height' => 60,
            'y' => ['min' => 0, 'max' => 5],
            'legend' => 'left',
            'levels' => [
                '1' => ['fill' => 'dots', 'label' => '< 20'],
                '2' => ['fill' => 'hatch', 'label' => '20-40'],
                '3' => ['fill' => 'solid', 'label' => '> 40'],
            ],
            'series' => [['field' => 'a'], ['field' => 'b']],
        ]);

        self::assertStringContainsString('>&lt; 20<', $svg);
        self::assertStringContainsString('>20-40<', $svg);
        self::assertStringContainsString('>&gt; 40<', $svg);

        // Tiga contoh kotak, walau tidak ada satu pun batang yang digambar:
        // legenda menjelaskan artinya, bukan melaporkan isinya.
        self::assertSame(3, preg_match_all('/<rect [^>]*stroke-width="0\.8"\/>/', $svg));
        self::assertGreaterThan(3, substr_count($svg, '<circle'), 'contoh titik-titiknya kosong');
    }

    /**
     * Judul panel menyingkir kalau legendanya juga di kolom kiri.
     *
     * Keduanya di tengah tinggi panel berarti keduanya bertumpuk.
     */
    public function testTitleMovesUpWhenTheLegendTakesTheLeftColumn(): void
    {
        $dengan = self::gambar([
            'type' => 'bar', 'height' => 60, 'title' => 'Kontraksi', 'title_align' => 'left', 'legend' => 'left',
            'levels' => ['1' => ['fill' => 'solid', 'label' => 'x']],
            'series' => [['field' => 'a'], ['field' => 'b']],
        ]);
        $tanpa = self::gambar([
            'type' => 'bar', 'height' => 60, 'title' => 'Kontraksi', 'title_align' => 'left',
            'levels' => ['1' => ['fill' => 'solid', 'label' => 'x']],
            'series' => [['field' => 'a'], ['field' => 'b']],
        ]);

        self::assertSame(1, preg_match('/y="([\d.]+)"[^>]*>Kontraksi</', $dengan, $a));
        self::assertSame(1, preg_match('/y="([\d.]+)"[^>]*>Kontraksi</', $tanpa, $b));
        self::assertLessThan((float) $b[1], (float) $a[1], 'judulnya tidak menyingkir dari legenda kiri');
    }

    /** Letak legenda yang tidak dikenal ditolak, dengan menyebut pilihannya. */
    public function testUnknownLegendPositionIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/letak legenda/i');

        ChartSpec::fromArray([
            'panels' => [['legend' => 'samping', 'series' => [['field' => 'a']]]],
        ]);
    }

    /** Tingkat isian boleh ditulis ringkas tanpa nama, seperti sebelumnya. */
    public function testLevelsStillAcceptAPlainFillName(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [[
                'type' => 'bar',
                'levels' => ['1' => 'hatch'],
                'series' => [['field' => 'a']],
            ]],
        ]);

        self::assertSame(['fill' => 'hatch', 'label' => ''], $spec->panels()[0]['levels']['1']);
    }

    /** Tanda wajik ada, dan bentuknya bukan segitiga. */
    public function testDiamondMarkerExists(): void
    {
        $svg = self::gambar(
            ['height' => 60, 'y' => ['min' => 0, 'max' => 10],
             'series' => [['field' => 'a', 'draw' => 'point', 'marker' => 'diamond']]],
            ['a' => '[{"x":"2026-09-29 09:00","y":5}]']
        );

        self::assertSame(1, preg_match('/<polygon points="([^"]+)"/', $svg, $m));
        self::assertCount(8, explode(' ', $m[1]), 'wajik harus bersudut empat');
    }
}
