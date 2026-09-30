<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SeriesValues;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Chart\ValueAxis;
use PHPUnit\Framework\TestCase;

/**
 * Bukti bahwa sumbu X benar-benar bisa bukan waktu.
 *
 * ## Kenapa uji ini ada, dan kenapa ia menggambar papan penuh
 *
 * Antarmuka yang dirancang dari satu contoh biasanya tidak muat untuk contoh
 * kedua: bentuknya ditebak dari yang ada, lalu yang berikutnya tidak masuk.
 * Penangkal yang biasa — "tulis pelaksana kedua" — tidak cukup kalau
 * pelaksananya cuma tempelan yang memanggil tiap metode sekali: tempelan bisa
 * dibentuk mengikuti antarmuka apa pun, jadi ia membuktikan dirinya sendiri.
 *
 * Karena itu di sini papannya digambar **sungguhan**: dua panel, deret berisi,
 * lewat `SvgChartRenderer` yang sama yang dipakai partograf. Kalau `XAxis`
 * ternyata kurang, yang gagal bukan satu asersi melainkan gambarnya.
 *
 * {@see ValueAxis} berbeda dari waktu justru di tempat yang penting: tidak
 * punya field jam mulai, tidak menyentuh kalender, labelnya angka, dan bentuk
 * bakunya pecahan, bukan teks tanggal.
 *
 * Lihat `docs/260930-grafik-jenis-baru.md` §5 dan §10 butir 4.
 */
final class PapanSumbuNilaiTest extends TestCase
{
    /** Papan berat badan terhadap usia (bulan) — sumbunya angka, bukan waktu. */
    private static function papan(): ChartSpec
    {
        $spec = ChartSpec::fromArray([
            'columns' => 12,
            'column_width' => 30,
            'left' => 50,
            'start_field' => 'usia_mulai',
            'label_every' => 3,
            'panels' => [
                [
                    'title' => 'Berat (kg)',
                    'height' => 120,
                    'y' => ['min' => 2, 'max' => 12, 'step' => 2],
                    'series' => [['field' => 'berat', 'marker' => 'circle']],
                ],
                [
                    'title' => 'Lingkar kepala (cm)',
                    'height' => 80,
                    'y' => ['min' => 30, 'max' => 50, 'step' => 5],
                    'series' => [['field' => 'lingkar_kepala', 'marker' => 'square']],
                ],
            ],
        ]);

        return $spec->withAxis(ValueAxis::create(1.0));
    }

    /** @return array<string,mixed> */
    private static function nilai(): array
    {
        return [
            'usia_mulai' => '0',
            'berat' => [
                ['x' => '0', 'y' => 3.3],
                ['x' => '3', 'y' => 6],
                ['x' => '6', 'y' => 7.8],
                ['x' => '12', 'y' => 9.5],
            ],
            'lingkar_kepala' => [
                ['x' => '0', 'y' => 34],
                ['x' => '6', 'y' => 43],
            ],
        ];
    }

    /**
     * Papannya tergambar, dan titiknya mendarat di tempat yang dihitung.
     *
     * Usia 3 bulan: kiri 50 + (3 × 30) = 140.
     * Berat 6 kg di panel 0: atas 16 + ((12−6)/(12−2) × 120) = 88.
     */
    public function testAValueAxisBoardRendersEndToEnd(): void
    {
        $svg = (new SvgChartRenderer())->render(self::papan(), self::nilai());

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString('cx="140" cy="88"', $svg, 'titik 3 bulan / 6 kg tidak di tempatnya');
        self::assertStringContainsString('Berat (kg)', $svg);
        self::assertStringContainsString('Lingkar kepala (cm)', $svg);
    }

    /** Labelnya angka usia, bukan jam. */
    public function testTheColumnLabelsAreNumbers(): void
    {
        $svg = (new SvgChartRenderer())->render(self::papan(), self::nilai());

        foreach (['>0<', '>3<', '>6<', '>9<', '>12<'] as $label) {
            self::assertStringContainsString($label, $svg, 'label kolom ' . $label . ' tidak ada');
        }
        self::assertSame(0, preg_match('/>\d{2}:\d{2}</', $svg), 'ada label berbentuk jam');
    }

    /**
     * Urutannya angka, bukan abjad.
     *
     * Ini yang paling mungkin lolos: dengan urutan abjad, "10" datang sebelum
     * "9", dan garis penghubungnya berbalik arah di tengah papan tanpa ada
     * yang salah mengisi.
     */
    public function testValuesSortNumericallyNotAlphabetically(): void
    {
        $titik = SeriesValues::parse(
            [
                ['x' => '10', 'y' => 9],
                ['x' => '9', 'y' => 8],
                ['x' => '2', 'y' => 5],
            ],
            ValueAxis::create()
        );

        self::assertSame(['2', '9', '10'], array_column($titik, 'x'));
    }

    /** Dan pada sumbu waktu urutannya tetap seperti dulu. */
    public function testTimeValuesStillSortByTime(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 10:00', 'y' => 2],
            ['x' => '2026-09-29 08:00', 'y' => 1],
        ]);

        self::assertSame(['2026-09-29 08:00', '2026-09-29 10:00'], array_column($titik, 'x'));
    }

    /**
     * Titik paling awal dicari menurut sumbunya, bukan menurut abjad.
     *
     * Dipakai menebak awal sumbu ketika papannya belum punya field jam mulai.
     * Lewat `strcmp`, "10" datang sebelum "9" — dan seluruh papan lalu digambar
     * mulai dari titik yang salah, bukan dari yang paling awal.
     */
    public function testTheEarliestPointIsFoundByTheAxis(): void
    {
        $axis = ValueAxis::create();
        $deret = [
            SeriesValues::parse([['x' => '10', 'y' => 1], ['x' => '20', 'y' => 2]], $axis),
            SeriesValues::parse([['x' => '9', 'y' => 3]], $axis),
        ];

        self::assertSame('9', SeriesValues::earliest($deret, $axis), 'abjad menaruh "10" lebih awal dari "9"');
    }

    /** Bentuk bakunya milik sumbunya: "3.50" ditulis "3.5", bukan ditolak. */
    public function testTheAxisOwnsTheStoredShape(): void
    {
        $json = SeriesValues::toJson(
            [['x' => '3.50', 'y' => 6], ['x' => '3.5', 'y' => 7]],
            ValueAxis::create()
        );

        // Dua ejaan untuk satu kedudukan adalah satu titik, dan yang terakhir
        // menang — aturan yang sama seperti pada sumbu waktu.
        self::assertSame('[{"x":"3.5","y":7}]', $json);
    }

    /** Nilai di luar sumbu tetap tidak digambar, sama seperti pada waktu. */
    public function testOutOfRangeValuesAreStillNotDrawn(): void
    {
        $nilai = self::nilai();
        $nilai['berat'][] = ['x' => '99', 'y' => 8];

        $svg = (new SvgChartRenderer())->render(self::papan(), $nilai);

        // Kolom 99 akan mendarat di x = 50 + 2970 = 3020.
        self::assertStringNotContainsString('cx="3020"', $svg);
    }

    /** Papan yang sama, digambar dua kali, menghasilkan byte yang sama. */
    public function testItIsStillDeterministic(): void
    {
        $r = new SvgChartRenderer();

        self::assertSame(
            $r->render(self::papan(), self::nilai()),
            $r->render(self::papan(), self::nilai())
        );
    }

    /**
     * Sumbu berangka tidak bisa diminta dari spesifikasi.
     *
     * Bentuknya belum diputuskan (§9 fase 11), dan spesifikasi tersimpan yang
     * telanjur memakainya akan mengikat keputusan itu — termasuk di dokumen
     * yang sudah ditandatangani.
     */
    public function testAValueAxisCannotBeAskedForInASpec(): void
    {
        $this->expectExceptionMessage('belum didukung');

        ChartSpec::fromArray([
            'x' => ['kind' => 'value'],
            'panels' => [['series' => [['field' => 'a']]]],
        ]);
    }
}
