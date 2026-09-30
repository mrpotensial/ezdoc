<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\TimeAxis;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Aritmetika kalender sumbu waktu.
 *
 * Salahnya kecil dan tidak menghasilkan galat apa pun — cuma titik yang
 * mendarat di kolom sebelah. Karena itu yang diuji di sini bukan "jalan atau
 * tidak", melainkan angka persisnya, pada tanggal-tanggal yang justru paling
 * sering membuat rumus naif runtuh: akhir bulan, bulan 28 hari, tahun kabisat,
 * dan pergantian tahun.
 */
final class TimeAxisTest extends TestCase
{
    private static function jam(string $awal = '2026-09-29 08:00'): TimeAxis
    {
        /** @var TimeAxis $axis */
        $axis = TimeAxis::create('hour')->withOrigin($awal);

        return $axis;
    }

    private static function hari(string $awal = '2026-09-29 00:00'): TimeAxis
    {
        /** @var TimeAxis $axis */
        $axis = TimeAxis::create('day')->withOrigin($awal);

        return $axis;
    }

    private static function bulan(string $awal = '2026-01-01 00:00'): TimeAxis
    {
        /** @var TimeAxis $axis */
        $axis = TimeAxis::create('month')->withOrigin($awal);

        return $axis;
    }

    // ── Jam ──────────────────────────────────────────────────────────────

    public function testHoursAreWholeAndFractional(): void
    {
        $axis = self::jam();

        self::assertSame(0.0, $axis->column('2026-09-29 08:00'));
        self::assertSame(2.0, $axis->column('2026-09-29 10:00'));
        self::assertSame(0.5, $axis->column('2026-09-29 08:30'));
        self::assertSame(-1.0, $axis->column('2026-09-29 07:00'));
    }

    /** Lewat tengah malam tetap maju, bukan kembali ke nol. */
    public function testHoursCrossMidnight(): void
    {
        self::assertSame(18.0, self::jam()->column('2026-09-30 02:00'));
    }

    // ── Hari ─────────────────────────────────────────────────────────────

    public function testDaysAreCountedByCalendar(): void
    {
        $axis = self::hari();

        self::assertSame(0.0, $axis->column('2026-09-29 00:00'));
        self::assertSame(1.0, $axis->column('2026-09-30 00:00'));
        self::assertSame(0.5, $axis->column('2026-09-29 12:00'));
        self::assertSame(-2.0, $axis->column('2026-09-27 00:00'));
    }

    /**
     * Akhir bulan bukan kejadian istimewa.
     *
     * 30 September ke 1 Oktober adalah satu hari, sama seperti hari lainnya.
     * Rumus yang menghitung lewat nomor tanggal akan menghasilkan −29.
     */
    public function testDaysCrossMonthEnd(): void
    {
        self::assertSame(2.0, self::hari('2026-09-30 00:00')->column('2026-10-02 00:00'));
    }

    /** Dan pergantian tahun pun tidak. */
    public function testDaysCrossYearEnd(): void
    {
        self::assertSame(3.0, self::hari('2026-12-30 00:00')->column('2027-01-02 00:00'));
    }

    // ── Bulan ────────────────────────────────────────────────────────────

    /**
     * Satu bulan tetap satu kolom, biar isinya 28 hari atau 31.
     *
     * Rumus "selisih detik dibagi 2592000" memberi 1,03 untuk Januari dan 0,93
     * untuk Februari — dua kolom yang sama lebarnya di gambar, tapi tidak di
     * hitungan. Titiknya lalu meleset makin jauh tiap bulan.
     */
    public function testWholeMonthsStayWholeWhateverTheirLength(): void
    {
        $axis = self::bulan();

        self::assertSame(0.0, $axis->column('2026-01-01 00:00'));
        self::assertSame(1.0, $axis->column('2026-02-01 00:00'), 'Januari 31 hari');
        self::assertSame(2.0, $axis->column('2026-03-01 00:00'), 'Februari 28 hari');
        self::assertSame(12.0, $axis->column('2027-01-01 00:00'));
    }

    /** Detiknya dibagi 2592000: begini salahnya kalau ditempuh. */
    public function testTheNaiveSecondsFormulaWouldBeWrong(): void
    {
        $awal = strtotime('2026-01-01 00:00');
        $naif = (strtotime('2026-02-01 00:00') - $awal) / 2592000;

        self::assertNotEqualsWithDelta(1.0, $naif, 0.001, 'rumus naifnya ternyata benar — ujinya yang salah');
        self::assertSame(1.0, self::bulan()->column('2026-02-01 00:00'));
    }

    /** Tengah bulan ada di tengah kolomnya, ikut panjang bulan itu sendiri. */
    public function testMidMonthIsHalfwayThroughItsOwnMonth(): void
    {
        // Februari 2026: 28 hari. Tanggal 15 = (15-1)/28 = 0,5.
        self::assertSame(1.5, self::bulan()->column('2026-02-15 00:00'));

        // Januari: 31 hari. Tanggal 15 = (15-1)/31 ≈ 0,4516.
        self::assertEqualsWithDelta(0.4516, (float) self::bulan()->column('2026-01-15 00:00'), 0.0005);
    }

    /** Tahun kabisat: Februari 2028 punya 29 hari. */
    public function testLeapFebruary(): void
    {
        $axis = self::bulan('2028-02-01 00:00');

        self::assertSame(1.0, $axis->column('2028-03-01 00:00'));
        // Tanggal 15 dari 29 hari = (15-1)/29 ≈ 0,4828 — bukan 0,5.
        self::assertEqualsWithDelta(0.4828, (float) $axis->column('2028-02-15 00:00'), 0.0005);
    }

    /** Mundur pun benar, termasuk melewati pergantian tahun. */
    public function testMonthsGoBackwards(): void
    {
        self::assertSame(-1.0, self::bulan('2026-01-01 00:00')->column('2025-12-01 00:00'));
    }

    /**
     * Awal sumbu bulan dibulatkan ke tanggal 1 bulannya.
     *
     * Tanpa itu, papan yang dimulai 31 Januari tidak punya jawaban untuk
     * "satu bulan berikutnya": 31 Februari tidak ada. Kolom ke-1 lalu berisi
     * sesuatu antara Februari dan Maret sementara labelnya berbunyi
     * "02/2026" — angka dan tulisan menunjuk bulan yang berbeda.
     */
    public function testAMonthOriginSnapsToTheFirst(): void
    {
        $axis = self::bulan('2026-01-31 17:45');

        self::assertSame('2026-01-01 00:00', $axis->origin());
        self::assertSame(1.0, $axis->column('2026-02-01 00:00'), 'kolom ke-1 bukan tepat Februari');
        self::assertSame(2.0, $axis->column('2026-03-01 00:00'));
        self::assertSame('02/2026', $axis->label(1));
    }

    /** Dan awal sumbu hari dibulatkan ke tengah malam. */
    public function testADayOriginSnapsToMidnight(): void
    {
        $axis = self::hari('2026-09-29 17:45');

        self::assertSame('2026-09-29 00:00', $axis->origin());
        self::assertSame(1.0, $axis->column('2026-09-30 00:00'));
        self::assertSame(0.75, $axis->column('2026-09-29 18:00'));
    }

    /**
     * Sumbu jam TIDAK dibulatkan.
     *
     * Papan yang dimulai 08.30 sudah dipakai, dan kolomnya memang 09.30,
     * 10.30, dan seterusnya. Membulatkannya akan menggeser tiap titik di tiap
     * papan yang sudah ada.
     */
    public function testAnHourOriginIsLeftAlone(): void
    {
        $axis = self::jam('2026-09-29 08:30');

        self::assertSame('2026-09-29 08:30', $axis->origin());
        self::assertSame(1.0, $axis->column('2026-09-29 09:30'));
        self::assertSame('09:30', $axis->label(1));
    }

    // ── Label ────────────────────────────────────────────────────────────

    public function testDefaultLabelsPerUnit(): void
    {
        self::assertSame('10:00', self::jam()->label(2));
        self::assertSame('01/10', self::hari()->label(2));
        self::assertSame('03/2026', self::bulan()->label(2));
    }

    /**
     * Label bulan tidak meluap ke bulan berikutnya.
     *
     * `(new DateTimeImmutable('2026-01-31'))->modify('+1 month')` menghasilkan
     * 3 Maret: PHP menambah bulannya lalu meluapkan tanggalnya. Papan yang
     * dimulai 31 Januari lalu memberi label "03/2026" pada kolom ke-1, dan
     * melewatkan Februari sama sekali.
     */
    public function testMonthLabelsDoNotOverflowFromTheEndOfAMonth(): void
    {
        $axis = self::bulan('2026-01-31 00:00');

        self::assertSame('01/2026', $axis->label(0));
        self::assertSame('02/2026', $axis->label(1), 'Februari dilewati — tanggalnya meluap');
        self::assertSame('03/2026', $axis->label(2));
    }

    /** Dan mundur melewati Januari tetap benar tahunnya. */
    public function testMonthLabelsGoBackAcrossTheYear(): void
    {
        $axis = self::bulan('2026-02-15 00:00');

        self::assertSame('01/2026', $axis->label(-1));
        self::assertSame('12/2025', $axis->label(-2));
    }

    public function testLabelFormatCanBeSet(): void
    {
        /** @var TimeAxis $axis */
        $axis = TimeAxis::create('month', 'M Y')->withOrigin('2026-09-01 00:00');

        self::assertSame('Sep 2026', $axis->label(0));
    }

    // ── Bentuk baku ──────────────────────────────────────────────────────

    public function testNormaliseAcceptsTheBrowserShapeAndDropsSeconds(): void
    {
        $axis = TimeAxis::create();

        self::assertSame('2026-09-29 08:00', $axis->normalise('2026-09-29T08:00'));
        self::assertSame('2026-09-29 08:00', $axis->normalise('2026-09-29 08:00:37'));
        self::assertSame('2026-09-29 08:00', $axis->normalise('  2026-09-29 08:00  '));
        self::assertNull($axis->normalise('29/09/2026'));
        self::assertNull($axis->normalise(''));
    }

    /** Membakukan yang sudah baku tidak mengubah apa pun. */
    public function testNormaliseIsIdempotent(): void
    {
        $axis = TimeAxis::create();
        $sekali = $axis->normalise('2026-09-29T08:00:59');

        self::assertNotNull($sekali);
        self::assertSame($sekali, $axis->normalise($sekali));
    }

    // ── Tanpa awal ───────────────────────────────────────────────────────

    public function testWithoutAnOriginThereIsNoPositionAndNoLabel(): void
    {
        $axis = TimeAxis::create('hour');

        self::assertFalse($axis->hasOrigin());
        self::assertNull($axis->column('2026-09-29 08:00'));
        self::assertSame('', $axis->label(0));
        // Tapi membakukan tetap bisa — mencari titik paling awal butuh itu.
        self::assertSame('2026-09-29 08:00', $axis->normalise('2026-09-29 08:00'));
    }

    /** Awalnya disalin, bukan diubah di tempat. */
    public function testWithOriginReturnsACopy(): void
    {
        $kosong = TimeAxis::create('hour');
        $terisi = $kosong->withOrigin('2026-09-29 08:00');

        self::assertFalse($kosong->hasOrigin(), 'sumbu asalnya ikut berubah');
        self::assertTrue($terisi->hasOrigin());
        self::assertNotSame($kosong, $terisi);
    }

    public function testAnUnknownUnitIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('hour, day, month');

        TimeAxis::create('minggu');
    }

    // ── Zona waktu ───────────────────────────────────────────────────────

    /**
     * Satu hari tetap satu kolom, termasuk hari yang panjangnya 23 atau 25 jam.
     *
     * Uji lain di berkas ini berjalan di UTC, yang tidak pernah ber-DST — dan
     * di situ "selisih detik dibagi 86400" kebetulan benar. Uji inilah yang
     * membedakannya. Library ini tidak boleh menganggap semua pemakainya
     * berada di zona tanpa DST.
     *
     * 29 Maret 2026 pukul 01.00 di London, jam melompat ke 02.00: hari itu
     * panjangnya 23 jam. 25 Oktober 2026 sebaliknya, 25 jam.
     */
    public function testADayStaysOneColumnAcrossDaylightSaving(): void
    {
        $semula = date_default_timezone_get();
        date_default_timezone_set('Europe/London');

        try {
            /** @var TimeAxis $maju */
            $maju = TimeAxis::create('day')->withOrigin('2026-03-28 00:00');
            self::assertSame(1.0, $maju->column('2026-03-29 00:00'), 'hari 23 jam');
            self::assertSame(2.0, $maju->column('2026-03-30 00:00'));

            /** @var TimeAxis $mundur */
            $mundur = TimeAxis::create('day')->withOrigin('2026-10-24 00:00');
            self::assertSame(1.0, $mundur->column('2026-10-25 00:00'), 'hari 25 jam');
            self::assertSame(2.0, $mundur->column('2026-10-26 00:00'));

            // Dan tengah hari tetap setengah kolom, bukan 0,52 atau 0,48.
            self::assertSame(0.5, $maju->column('2026-03-28 12:00'));
        } finally {
            date_default_timezone_set($semula);
        }
    }

    /**
     * Begini salahnya kalau detiknya yang dibagi.
     *
     * Ditulis supaya yang membaca tahu uji di atas menjaga sesuatu yang nyata,
     * bukan mengulang yang sudah pasti.
     */
    public function testTheNaiveDayFormulaBreaksOnADaylightSavingDay(): void
    {
        $semula = date_default_timezone_get();
        date_default_timezone_set('Europe/London');

        try {
            // Jam melompat maju pada 29 Maret pukul 01.00, jadi hari yang
            // panjangnya 23 jam adalah 29 Maret — bukan 28.
            $a = (new \DateTimeImmutable('2026-03-29 00:00'))->getTimestamp();
            $b = (new \DateTimeImmutable('2026-03-30 00:00'))->getTimestamp();

            self::assertSame(23.0, ($b - $a) / 3600.0, 'ternyata bukan hari 23 jam — ujinya yang salah');
            self::assertNotEqualsWithDelta(1.0, ($b - $a) / 86400.0, 0.001, 'rumus naifnya ternyata benar');
            self::assertSame(1.0, TimeAxis::create('day')->withOrigin('2026-03-29 00:00')->column('2026-03-30 00:00'));
        } finally {
            date_default_timezone_set($semula);
        }
    }

    /**
     * Detik sejak tengah malam dibaca dari jamnya, bukan dari timestamp.
     *
     * `timestamp % 86400` memberi detik sejak tengah malam **UTC**. Di zona
     * yang bergeser dari UTC, angkanya melompat satu hari penuh begitu
     * pengukurannya melewati tengah malam UTC — dan di UTC sendiri tidak
     * pernah kelihatan.
     */
    public function testSecondsOfDayAreReadFromTheClockNotTheTimestamp(): void
    {
        $semula = date_default_timezone_get();
        date_default_timezone_set('Asia/Jakarta');

        try {
            /** @var TimeAxis $axis */
            $axis = TimeAxis::create('day')->withOrigin('2026-09-28 00:00');

            // 18.00 di Jakarta = 11.00 UTC; tengah malam Jakarta = 17.00 UTC
            // hari sebelumnya. Lewat timestamp, selisihnya jadi negatif.
            self::assertSame(0.75, $axis->column('2026-09-28 18:00'));
            self::assertSame(2.75, $axis->column('2026-09-30 18:00'));
        } finally {
            date_default_timezone_set($semula);
        }
    }

    // ── Sifat yang diwajibkan antarmuka ──────────────────────────────────

    /** Urutan tersimpan dan urutan di gambar tidak boleh berbeda. */
    public function testColumnIsMonotonic(): void
    {
        foreach (['hour', 'day', 'month'] as $unit) {
            /** @var TimeAxis $axis */
            $axis = TimeAxis::create($unit)->withOrigin('2026-01-01 00:00');

            $waktu = [
                '2025-12-31 23:00', '2026-01-01 00:00', '2026-01-31 23:59',
                '2026-02-01 00:00', '2026-02-28 12:00', '2026-03-01 00:00',
                '2027-01-01 00:00',
            ];

            $sebelumnya = null;
            foreach ($waktu as $t) {
                $kolom = $axis->column($t);
                self::assertNotNull($kolom, $unit . ' — ' . $t);
                if ($sebelumnya !== null) {
                    self::assertGreaterThan($sebelumnya, $kolom, $unit . ' — urutannya terbalik di ' . $t);
                }
                $sebelumnya = $kolom;
            }
        }
    }
}
