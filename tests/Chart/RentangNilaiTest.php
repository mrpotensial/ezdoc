<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SeriesValues;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Chart\ValueAxis;
use PHPUnit\Framework\TestCase;

/**
 * Rentang: kunci `x2` yang boleh tidak ada.
 *
 * Bagian ini menyentuh **bentuk nilai tersimpan**, yang ikut di-hash tanda
 * tangan. Karena itu yang dijaga di sini bukan cuma "rentangnya terbaca",
 * melainkan juga bahwa nilai lama terbaca **persis** seperti sebelumnya.
 *
 * Empat keadaan, semuanya menjelaskan dirinya sendiri tanpa perlu membaca
 * spesifikasi papannya:
 *
 *   tidak ada  → titik
 *   `false`    → rentang terbuka: sudah mulai, belum selesai
 *   waktu sah  → rentang tertutup
 *   selain itu → seluruh rentangnya dibuang
 *
 * Lihat `docs/260930-grafik-jenis-baru.md` §4.
 */
final class RentangNilaiTest extends TestCase
{
    // ── Nilai lama tidak berubah sedikit pun ─────────────────────────────

    /**
     * Titik tanpa `x2` terbaca persis seperti sebelumnya.
     *
     * Ini yang paling penting di seluruh berkas ini: tiap dokumen yang sudah
     * ditandatangani berisi nilai bentuk lama, dan bentuk yang bergeser
     * setengah koma pun membuat hash-nya tidak cocok lagi.
     */
    public function testOldValuesAreUnchanged(): void
    {
        $json = SeriesValues::toJson([
            ['x' => '2026-09-29 10:00', 'y' => 8],
            ['x' => '2026-09-29 08:00', 'y' => 4.50],
        ]);

        self::assertSame('[{"x":"2026-09-29 08:00","y":4.5},{"x":"2026-09-29 10:00","y":8}]', $json);
    }

    /** Dan titik tanpa nilai tetap dibuang, seperti dulu. */
    public function testAPointWithoutAValueIsStillDropped(): void
    {
        self::assertSame([], SeriesValues::parse([['x' => '2026-09-29 08:00']]));
    }

    // ── Empat keadaan `x2` ───────────────────────────────────────────────

    public function testAClosedRangeIsKept(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 14:00', 'y' => 'RL 500'],
        ]);

        self::assertSame(
            [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 14:00', 'y' => 'RL 500']],
            $titik
        );
        self::assertTrue(SeriesValues::isRange($titik[0]));
    }

    /**
     * Rentang terbuka dicatat sebagai `false`, bukan dikosongkan.
     *
     * Infus yang masih jalan tidak punya jam selesai. Tanpa keadaan ini, yang
     * mengisi cuma punya dua pilihan: tidak menulisnya — datanya hilang — atau
     * mengarang jam selesai. Yang kedua persis kegagalan yang dijaga seluruh
     * berkas ini: dokumen yang memuat sesuatu yang tidak pernah dicatat.
     */
    public function testAnOpenRangeIsRecordedAsFalse(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'Oksitosin'],
        ]);

        self::assertSame(
            [['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'Oksitosin']],
            $titik
        );
        self::assertSame(
            '[{"x":"2026-09-29 08:00","x2":false,"y":"Oksitosin"}]',
            SeriesValues::toJson($titik)
        );
    }

    /**
     * Rentang yang berakhir sebelum mulai dibuang UTUH, bukan jadi titik.
     *
     * Menurunkannya jadi titik menyembunyikan salah isinya: gambarnya tetap
     * memperlihatkan sesuatu, dan tidak ada yang tahu ujung akhirnya salah.
     */
    public function testARangeThatEndsBeforeItStartsIsDropped(): void
    {
        self::assertSame([], SeriesValues::parse([
            ['x' => '2026-09-29 14:00', 'x2' => '2026-09-29 08:00', 'y' => 'salah'],
        ]));
        self::assertSame([], SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 08:00', 'y' => 'nol panjang'],
        ]));
        self::assertSame([], SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => 'bukan waktu', 'y' => 'rusak'],
        ]));
    }

    /** Rentang boleh tanpa label; titik tidak boleh tanpa nilai. */
    public function testARangeMayHaveNoLabel(): void
    {
        $titik = SeriesValues::parse([['x' => '2026-09-29 08:00', 'x2' => false]]);

        self::assertCount(1, $titik);
        self::assertSame('', $titik[0]['y']);
    }

    /**
     * Label rentang boleh jauh lebih panjang daripada isi sel.
     *
     * Enam belas huruf masuk akal untuk baris sel — "U", "J", "+", "20/30".
     * Untuk rentang, labelnya nama obat: "Ceftriaxone 1 gram" sudah delapan
     * belas, dan batas yang ketat membuangnya **diam-diam** — rentangnya tetap
     * tergambar, cuma tanpa nama.
     *
     * Ditemukan lewat uji mutasi, dan bukan pada kodenya: satu uji di berkas
     * lain memakai label 17 huruf dan lulus karena labelnya dibuang, bukan
     * karena yang diuji bekerja.
     */
    public function testARangeLabelMayBeLongerThanACellLabel(): void
    {
        $panjang = 'Ceftriaxone 1 gram';
        self::assertGreaterThan(SeriesValues::MAX_LABEL, mb_strlen($panjang));

        $rentang = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => $panjang],
        ]);
        self::assertSame($panjang, $rentang[0]['y']);

        // Titik biasa tetap dibatasi seperti dulu.
        self::assertSame([], SeriesValues::parse([['x' => '2026-09-29 08:00', 'y' => $panjang]]));
    }

    /** Tapi tetap ada batasnya — catatan sepanjang paragraf adalah salah isi. */
    public function testAnAbsurdlyLongRangeLabelIsStillRejected(): void
    {
        $rentang = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00', 'y' => str_repeat('a', 60)],
        ]);

        self::assertCount(1, $rentang, 'rentangnya ikut dibuang');
        self::assertSame('', $rentang[0]['y'], 'label sepanjang 60 huruf ikut disimpan');
    }

    // ── Urutan dan kembar ────────────────────────────────────────────────

    /**
     * Kuncinya `x` DAN `x2`.
     *
     * Dua rentang yang mulai bersamaan tapi berakhir berbeda adalah dua
     * rentang — dua obat yang dimulai pukul 08.00 dan berhenti di jam
     * berbeda. Kunci yang cuma `x` akan menelan salah satunya.
     */
    public function testTwoRangesStartingTogetherAreTwoRanges(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'A'],
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 14:00', 'y' => 'B'],
        ]);

        self::assertCount(2, $titik);
        self::assertSame(['A', 'B'], array_column($titik, 'y'));
    }

    /** Rentang yang benar-benar sama persis tetap ditimpa yang terakhir. */
    public function testAnIdenticalRangeIsStillOverwritten(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'lama'],
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'baru'],
        ]);

        self::assertCount(1, $titik);
        self::assertSame('baru', $titik[0]['y']);
    }

    /** Titik dan rentang yang mulai bersamaan tidak saling menimpa. */
    public function testAPointAndARangeStartingTogetherCoexist(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'y' => 5],
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'obat'],
        ]);

        self::assertCount(2, $titik);
        self::assertFalse(SeriesValues::isRange($titik[0]), 'titik harus lebih dulu');
        self::assertTrue(SeriesValues::isRange($titik[1]));
    }

    /** Yang awalnya sama diurutkan menurut ujungnya: titik, tertutup, terbuka. */
    public function testEqualStartsAreOrderedByTheirEnd(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'terbuka'],
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'tertutup'],
            ['x' => '2026-09-29 08:00', 'y' => 3],
        ]);

        self::assertSame([3, 'tertutup', 'terbuka'], array_column($titik, 'y'));
    }

    /** Urutan antar waktu tetap menurut sumbunya. */
    public function testRangesSortByTheirStart(): void
    {
        $titik = SeriesValues::parse([
            ['x' => '2026-09-29 14:00', 'x2' => false, 'y' => 'B'],
            ['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'A'],
        ]);

        self::assertSame(['A', 'B'], array_column($titik, 'y'));
    }

    /** Dan pada sumbu berangka, urutannya angka — bukan abjad. */
    public function testRangesOnAValueAxisSortNumerically(): void
    {
        $titik = SeriesValues::parse(
            [
                ['x' => '10', 'x2' => '12', 'y' => 'B'],
                ['x' => '9', 'x2' => '11', 'y' => 'A'],
            ],
            ValueAxis::create()
        );

        self::assertSame(['A', 'B'], array_column($titik, 'y'));
    }

    // ── Penyaringan ──────────────────────────────────────────────────────

    public function testPointsAndRangesCanBeSeparated(): void
    {
        $semua = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'y' => 5],
            ['x' => '2026-09-29 09:00', 'x2' => '2026-09-29 11:00', 'y' => 'obat'],
        ]);

        self::assertCount(1, SeriesValues::onlyPoints($semua));
        self::assertCount(1, SeriesValues::onlyRanges($semua));
        self::assertSame(5, SeriesValues::onlyPoints($semua)[0]['y']);
        self::assertSame('obat', SeriesValues::onlyRanges($semua)[0]['y']);
    }

    /**
     * Panel yang menggambar pengukuran mengabaikan rentang.
     *
     * Rentang bukan pengukuran. Menggambarnya sebagai titik di jam mulainya
     * mengarang bacaan yang tidak pernah diambil siapa pun — golongan
     * kesalahan yang sama dengan menggeser nilai di luar sumbu ke tepi.
     */
    public function testAMeasurementPanelIgnoresRanges(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'series' => [['field' => 'nilai', 'draw' => 'point']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($spec, [
            'mulai' => '2026-09-29 08:00',
            'nilai' => [
                ['x' => '2026-09-29 08:00', 'y' => 5],
                ['x' => '2026-09-29 10:00', 'x2' => '2026-09-29 11:00', 'y' => 8],
            ],
        ]);

        self::assertSame(1, substr_count($svg, '<circle'), 'rentangnya ikut digambar sebagai titik');
    }

    /**
     * Tapi awal sebuah rentang tetap boleh jadi awal sumbu.
     *
     * Papan tanpa field jam mulai menebaknya dari pengukuran paling awal.
     * Kalau yang paling awal kebetulan sebuah rentang, sumbunya tetap harus
     * mulai dari situ — kalau tidak, rentangnya terlukis di luar papan.
     */
    public function testARangeStartCanStillOpenTheAxis(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'panels' => [[
                'height' => 100,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'series' => [['field' => 'nilai']],
            ]],
        ]);

        $scale = (new SvgChartRenderer())->scale($spec, [
            'nilai' => [['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 11:00', 'y' => 'obat']],
        ]);

        self::assertSame('2026-09-29 08:00', $scale->axis()->origin());
    }

    /** Keluaran JSON-nya tetap baku dan bisa dibaca ulang tanpa berubah. */
    public function testTheStoredShapeSurvivesARoundTrip(): void
    {
        $asal = [
            ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 14:00', 'y' => 'RL 500'],
            ['x' => '2026-09-29 09:00', 'x2' => false, 'y' => ''],
            ['x' => '2026-09-29 10:00', 'y' => 7],
        ];

        $sekali = SeriesValues::toJson($asal);
        $duakali = SeriesValues::toJson(json_decode($sekali, true));

        self::assertSame($sekali, $duakali);
    }
}
