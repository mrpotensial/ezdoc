<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Pita nilai: satu aturan, dua bacaan.
 *
 * *"Nilai di rentang ini warnanya ini"* — yang berbeda cuma tempat
 * melukisnya, dan itu ditentukan bentuk panelnya:
 *
 *   baris sel        → latar tiap SEL, menurut nilai sel itu
 *   panel bersumbu Y → pita MENDATAR di latar panel
 *
 * Rancangan pertama menjadikannya jenis panel tersendiri (`heat`). Dibatalkan:
 * pita nilai berguna juga di panel garis, dan jenis panel yang cuma menutupi
 * separuh kebutuhannya bukan jenis. Dua contoh di §7 PRD sengaja dipasangkan
 * untuk memakai kunci yang sama dengan dua bacaan berbeda.
 */
final class PitaNilaiTest extends TestCase
{
    private const PITA = [
        ['from' => 0, 'to' => 2, 'color' => '#dcfce7'],
        ['from' => 3, 'to' => 5, 'color' => '#fef9c3'],
        ['from' => 6, 'color' => '#7f1d1d', 'label' => 'Lapor DPJP'],
    ];

    /** Papan bersumbu Y 0–10 setinggi 100, kiri 64, lebar kolom 40. */
    private static function panelNilai(): ChartSpec
    {
        return ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'bands' => self::PITA,
                'series' => [['field' => 'skor']],
            ]],
        ]);
    }

    /** Baris sel setinggi 20, dengan pita yang sama. */
    private static function barisSel(): ChartSpec
    {
        return ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'symbol',
                'height' => 20,
                'bands' => self::PITA,
                'series' => [['field' => 'skor', 'color' => '#cc0000']],
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private static function nilai(): array
    {
        return [
            'mulai' => '2026-09-29 08:00',
            'skor' => [
                ['x' => '2026-09-29 08:00', 'y' => 1],
                ['x' => '2026-09-29 09:00', 'y' => 4],
                ['x' => '2026-09-29 10:00', 'y' => 8],
            ],
        ];
    }

    /** @return array<int,array<string,string>> */
    private static function kotak(string $svg): array
    {
        preg_match_all(
            '/<rect x="([\d.\-]+)" y="([\d.\-]+)" width="([\d.\-]+)" height="([\d.\-]+)" fill="(#[0-9a-f]{6})" stroke="none"\/>/',
            $svg,
            $m,
            PREG_SET_ORDER
        );

        return $m;
    }

    // ── Bacaan pertama: pita mendatar ────────────────────────────────────

    /**
     * Di panel bersumbu Y, pita melintang selebar kisinya.
     *
     * Sumbu 0–10 setinggi 100 mulai y 16:
     *   nilai 0 → y 116, nilai 2 → y 96, nilai 3 → y 86, nilai 5 → y 66
     */
    public function testOnAValuePanelBandsAreHorizontalStripes(): void
    {
        $kotak = self::kotak((new SvgChartRenderer())->render(self::panelNilai(), self::nilai()));

        self::assertCount(3, $kotak, 'pitanya tidak tiga');

        // Urutannya naik, sesuai urutan pita yang sudah dirapikan spesifikasi.
        // Pita 0–2: dari y 96 sampai 116, setinggi 20, selebar kisi (4 × 40).
        self::assertSame(['64', '96', '160', '20', '#dcfce7'], array_slice($kotak[0], 1));
        // Pita 3–5: dari y 66 sampai 86.
        self::assertSame(['64', '66', '160', '20', '#fef9c3'], array_slice($kotak[1], 1));
    }

    /**
     * Pita tanpa ujung atas dipotong di tepi panelnya.
     *
     * Pita "6 ke atas" pada sumbu yang berhenti di 10 terlukis sampai 10,
     * bukan menjulur ke panel di atasnya. Yang menjulur akan menutupi baris
     * lain dan terbaca sebagai peringatan di baris yang salah.
     */
    public function testAnOpenEndedBandIsClippedToThePanel(): void
    {
        $kotak = self::kotak((new SvgChartRenderer())->render(self::panelNilai(), self::nilai()));

        // Pita 6..∞ : dari tepi atas panel (16) sampai nilai 6 (y 56).
        self::assertSame(['64', '16', '160', '40', '#7f1d1d'], array_slice($kotak[2], 1));
    }

    /** Pita dilukis di belakang kisi, bukan menutupinya. */
    public function testBandsAreDrawnBehindTheGrid(): void
    {
        $svg = (new SvgChartRenderer())->render(self::panelNilai(), self::nilai());

        $pita = strpos($svg, '#dcfce7');
        $kisi = strpos($svg, 'stroke="#b8b8b8"');

        self::assertNotFalse($pita);
        self::assertNotFalse($kisi);
        self::assertLessThan($kisi, $pita, 'pitanya menutupi garis kisi');
    }

    // ── Bacaan kedua: latar sel ──────────────────────────────────────────

    /**
     * Di baris sel, pita mewarnai SEL — satu kolom penuh, per nilai.
     *
     * Skor 1 di jam 0 → hijau, 4 di jam 1 → kuning, 8 di jam 2 → merah tua.
     */
    public function testOnACellRowBandsColourEachCell(): void
    {
        $kotak = self::kotak((new SvgChartRenderer())->render(self::barisSel(), self::nilai()));

        self::assertCount(3, $kotak, 'selnya tidak tiga');
        foreach ([[0, '64', '#dcfce7'], [1, '104', '#fef9c3'], [2, '144', '#7f1d1d']] as [$i, $x, $warna]) {
            self::assertSame($x, $kotak[$i][1], 'sel ke-' . $i . ' salah kolom');
            self::assertSame('40', $kotak[$i][3], 'lebarnya bukan satu kolom penuh');
            self::assertSame($warna, $kotak[$i][5]);
        }
    }

    /**
     * Angka di atas latar gelap ditulis putih, di atas latar muda hitam.
     *
     * Dihitung dari kecerahan latarnya (luminansi WCAG), bukan disetel: yang
     * menyetel warna pitanya tidak bisa diharapkan menghitungnya sendiri, dan
     * angka hitam di atas merah tua tidak terbaca oleh siapa pun.
     */
    public function testTheInkIsChosenForTheBandBeneathIt(): void
    {
        $svg = (new SvgChartRenderer())->render(self::barisSel(), self::nilai());

        self::assertSame(1, preg_match('/fill="#ffffff"[^>]*>8</', $svg), 'angka 8 tidak putih di atas merah tua');
        self::assertSame(1, preg_match('/fill="#111111"[^>]*>1</', $svg), 'angka 1 tidak hitam di atas hijau muda');
    }

    /**
     * Kecerahan dihitung berbobot, bukan dirata-rata.
     *
     * Mata jauh lebih peka pada hijau daripada biru — itu sebabnya WCAG
     * membobotinya 0,7152 berbanding 0,0722. Rata-rata tiga kanal memberi
     * jawaban yang sama untuk hampir semua warna, dan **berbeda pada biru**:
     * `#1d4ed8` rata-ratanya terang (tinta hitam), padahal bagi mata ia gelap
     * (tinta putih). Pita biru tua lalu berisi angka hitam yang tidak
     * terbaca.
     *
     * Tanpa uji ini kedua rumus tidak terbedakan — uji mutasi menunjukkannya.
     */
    public function testTheInkUsesWeightedLuminanceNotAPlainAverage(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'symbol',
                'height' => 20,
                'bands' => [['from' => 0, 'to' => 2, 'color' => '#1d4ed8']],
                'series' => [['field' => 'skor', 'color' => '#cc0000']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($spec, [
            'mulai' => '2026-09-29 08:00',
            'skor' => [['x' => '2026-09-29 08:00', 'y' => 1]],
        ]);

        self::assertSame(
            1,
            preg_match('/fill="#ffffff"[^>]*>1</', $svg),
            'angka di atas biru tua ditulis hitam — rumusnya dirata-rata, bukan dibobot'
        );
    }

    /** Sel tanpa pita tetap memakai warna deretnya. */
    public function testACellWithNoBandKeepsTheSeriesColour(): void
    {
        $nilai = self::nilai();
        $nilai['skor'][] = ['x' => '2026-09-29 11:00', 'y' => 2.5]; // celah antara pita 0–2 dan 3–5

        $svg = (new SvgChartRenderer())->render(self::barisSel(), $nilai);

        self::assertSame(1, preg_match('/fill="#cc0000"[^>]*>2\.5</', $svg), 'warna deretnya ikut ditimpa');
        self::assertCount(3, self::kotak($svg), 'nilai di celah ikut diwarnai');
    }

    /**
     * Latar sel tidak pernah terlukis di luar kisinya.
     *
     * Papan 4 kolom punya 4 sel; batas ke-5 cuma garis penutup. Nilai yang
     * jatuh tepat di situ tidak punya kolom di sebelah kanannya, dan latarnya
     * akan terlukis di luar papan — menimpa apa pun yang ada di sebelahnya di
     * halaman. Ketahuan dari membaca hasil cetak, bukan dari uji.
     */
    public function testACellBackgroundNeverSpillsPastTheGrid(): void
    {
        $svg = (new SvgChartRenderer())->render(self::barisSel(), [
            'mulai' => '2026-09-29 08:00',
            'skor' => [
                ['x' => '2026-09-29 11:00', 'y' => 1],   // kolom 3, sel terakhir
                ['x' => '2026-09-29 12:00', 'y' => 8],   // batas penutup — tanpa kolom
            ],
        ]);

        $kotak = self::kotak($svg);
        self::assertCount(1, $kotak, 'nilai di batas penutup ikut diberi latar');

        // Kisi berakhir di 64 + (4 × 40) = 224. Sel terakhir 184..224.
        self::assertSame('184', $kotak[0][1]);
        self::assertSame('40', $kotak[0][3]);
    }

    /**
     * Nilai bukan angka tidak masuk pita mana pun.
     *
     * "✓", "U", "positif": pita menyatakan jangkauan angka, dan memaksakan
     * huruf ke dalamnya berarti menebak urutannya.
     */
    public function testNonNumericValuesMatchNoBand(): void
    {
        $svg = (new SvgChartRenderer())->render(self::barisSel(), [
            'mulai' => '2026-09-29 08:00',
            'skor' => [['x' => '2026-09-29 08:00', 'y' => 'U']],
        ]);

        self::assertSame([], self::kotak($svg));
        self::assertSame(1, preg_match('/fill="#cc0000"[^>]*>U</', $svg));
    }

    // ── Bentuk spesifikasi ───────────────────────────────────────────────

    /** Pita bertumpang tindih ditolak: satu nilai tidak boleh punya dua warna. */
    public function testOverlappingBandsAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('bertumpang tindih');

        ChartSpec::fromArray([
            'panels' => [[
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'bands' => [
                    ['from' => 0, 'to' => 5, 'color' => '#dcfce7'],
                    ['from' => 4, 'to' => 8, 'color' => '#fef9c3'],
                ],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    /** Termasuk kalau yang bertumpang tindih adalah pita tak berujung. */
    public function testAnOpenEndedBandInTheMiddleIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('bertumpang tindih');

        ChartSpec::fromArray([
            'panels' => [[
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'bands' => [
                    ['from' => 0, 'color' => '#dcfce7'],
                    ['from' => 6, 'to' => 8, 'color' => '#fef9c3'],
                ],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    /** Celah di antara pita boleh — nilai di situ memang tidak diwarnai. */
    public function testAGapBetweenBandsIsAllowed(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [[
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'bands' => [
                    ['from' => 0, 'to' => 2, 'color' => '#dcfce7'],
                    ['from' => 6, 'color' => '#fecaca'],
                ],
                'series' => [['field' => 'a']],
            ]],
        ]);

        self::assertCount(2, $spec->panels()[0]['bands']);
    }

    /** Urutannya dirapikan sendiri; yang menulisnya tidak perlu mengurutkan. */
    public function testBandsAreSortedByTheirLowerEdge(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [[
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'bands' => [
                    ['from' => 6, 'color' => '#fecaca'],
                    ['from' => 0, 'to' => 2, 'color' => '#dcfce7'],
                ],
                'series' => [['field' => 'a']],
            ]],
        ]);

        self::assertSame([0.0, 6.0], array_column($spec->panels()[0]['bands'], 'from'));
    }

    public function testABandNeedsANumericLowerEdge(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("'from' wajib");

        ChartSpec::fromArray([
            'panels' => [[
                'bands' => [['to' => 5, 'color' => '#dcfce7']],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    public function testATopEdgeBelowTheBottomIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("lebih besar");

        ChartSpec::fromArray([
            'panels' => [[
                'bands' => [['from' => 5, 'to' => 2, 'color' => '#dcfce7']],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    /**
     * Sumbu Y kedua ditolak, bukan diabaikan.
     *
     * Kuncinya sudah dibaca supaya bentuk spesifikasinya tidak berubah lagi
     * nanti (§9 fase 10), tapi pita yang diam-diam dilukis di skala yang salah
     * menandai nilai yang tidak pernah terjadi.
     */
    public function testTheSecondYAxisIsRejectedForNow(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belum ada');

        ChartSpec::fromArray([
            'panels' => [[
                'bands' => [['from' => 0, 'to' => 5, 'color' => '#dcfce7', 'axis' => 'right']],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    /** Papan tanpa pita tergambar persis seperti sebelumnya. */
    public function testABoardWithoutBandsIsUnchanged(): void
    {
        $tanpa = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'series' => [['field' => 'skor']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($tanpa, self::nilai());

        self::assertSame([], self::kotak($svg), 'ada kotak latar di papan tanpa pita');
    }

    /** Dan keluarannya tetap sama byte demi byte. */
    public function testStillDeterministic(): void
    {
        $r = new SvgChartRenderer();

        self::assertSame(
            $r->render(self::barisSel(), self::nilai()),
            $r->render(self::barisSel(), self::nilai())
        );
        self::assertSame(
            $r->render(self::panelNilai(), self::nilai()),
            $r->render(self::panelNilai(), self::nilai())
        );
    }
}
