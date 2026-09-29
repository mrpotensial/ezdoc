<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\SeriesValues;
use PHPUnit\Framework\TestCase;

/**
 * Bentuk angka sebuah deret — bagian yang paling mahal kalau salah.
 *
 * Nilai ini tersimpan di `field_values` dan ikut di-hash tanda tangan. Dua
 * dokumen beridentik isi wajib menghasilkan JSON yang sama persis, apa pun
 * urutan pengetikannya; kalau tidak, "dokumen ini bisa dibangun ulang dari
 * nilainya" tidak bisa dibuktikan.
 */
final class SeriesValuesTest extends TestCase
{
    /** Titik disimpan urut waktu, bukan urut ketik. */
    public function testPointsAreSortedByTime(): void
    {
        $points = SeriesValues::parse([
            ['x' => '2026-09-29 12:00', 'y' => 5],
            ['x' => '2026-09-29 08:00', 'y' => 4],
            ['x' => '2026-09-29 10:00', 'y' => 4],
        ]);

        self::assertSame(
            ['2026-09-29 08:00', '2026-09-29 10:00', '2026-09-29 12:00'],
            array_column($points, 'x')
        );
    }

    /**
     * Satu waktu satu nilai, dan yang menang yang terakhir.
     *
     * Orang yang mencatat ulang jam yang sama sedang membetulkan, bukan
     * menambah.
     */
    public function testSameTimeKeepsTheLastEntry(): void
    {
        $points = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'y' => 4],
            ['x' => '2026-09-29 08:00', 'y' => 6],
        ]);

        self::assertCount(1, $points);
        self::assertSame(6, $points[0]['y']);
    }

    /**
     * Urutan pengetikan tidak boleh mengubah hasil simpannya.
     *
     * Inilah yang membuat hash-nya berarti.
     */
    public function testTypingOrderDoesNotChangeStoredForm(): void
    {
        $a = SeriesValues::toJson([
            ['x' => '2026-09-29 10:00', 'y' => 5],
            ['x' => '2026-09-29 08:00', 'y' => 4],
        ]);
        $b = SeriesValues::toJson([
            ['x' => '2026-09-29 08:00', 'y' => 4],
            ['x' => '2026-09-29 10:00', 'y' => 5],
        ]);

        self::assertSame($a, $b);
        self::assertSame('[{"x":"2026-09-29 08:00","y":4},{"x":"2026-09-29 10:00","y":5}]', $a);
    }

    /** Teks JSON dan array sama-sama diterima. */
    public function testAcceptsJsonAndArray(): void
    {
        $json = '[{"x":"2026-09-29 08:00","y":4}]';

        self::assertSame(SeriesValues::parse($json), SeriesValues::parse(json_decode($json, true)));
    }

    /**
     * Baris rusak dibuang, sisanya tetap tergambar.
     *
     * Satu catatan yang salah bentuk tidak boleh membuat seluruh lembar
     * berhenti tampil — yang mengisi butuh melihat sisanya untuk tahu mana yang
     * salah.
     */
    public function testBrokenEntriesAreDroppedNotFatal(): void
    {
        $points = SeriesValues::parse([
            ['x' => '2026-09-29 08:00', 'y' => 4],
            ['x' => 'kemarin siang', 'y' => 5],
            ['y' => 6],
            'bukan titik',
            ['x' => '2026-09-29 09:00'],
            ['x' => '2026-09-29 10:00', 'y' => 7],
        ]);

        self::assertSame(['2026-09-29 08:00', '2026-09-29 10:00'], array_column($points, 'x'));
    }

    /**
     * Waktu ditulis satu bentuk, walau masuknya beberapa bentuk.
     *
     * Isian `datetime-local` peramban memakai "T", dan sumber lain membawa
     * detik. Detik dibuang: di lembar pemantauan ia tidak pernah berarti, dan
     * membiarkannya membuat dua catatan pada menit yang sama tidak saling
     * menimpa — padahal orangnya bermaksud mengoreksi.
     *
     * @dataProvider timeForms
     */
    public function testTimeIsStoredInOneForm(string $raw, ?string $expected): void
    {
        self::assertSame($expected, SeriesValues::normaliseTime($raw));
    }

    /** @return array<string,array{0:string,1:string|null}> */
    public static function timeForms(): array
    {
        return [
            'baku' => ['2026-09-29 08:00', '2026-09-29 08:00'],
            'datetime-local' => ['2026-09-29T08:00', '2026-09-29 08:00'],
            'berdetik' => ['2026-09-29 08:00:45', '2026-09-29 08:00'],
            'berspasi' => ['  2026-09-29 08:00  ', '2026-09-29 08:00'],
            'tanpa tanggal' => ['08:00', null],
            'tanggal saja' => ['2026-09-29', null],
            'tanggal tak ada' => ['2026-02-31 08:00', null],
            'kosong' => ['', null],
            'ngawur' => ['besok pagi', null],
        ];
    }

    /**
     * Angka disimpan satu bentuk.
     *
     * `4.0` dan `4` menggambarkan pengukuran yang sama; dua bentuk untuk satu
     * pengukuran berarti dua hash untuk satu dokumen.
     *
     * @param mixed $raw
     * @param mixed $expected
     * @dataProvider valueForms
     */
    public function testValueIsStoredInOneForm($raw, $expected): void
    {
        self::assertSame($expected, SeriesValues::normaliseValue($raw));
    }

    /** @return array<string,array{0:mixed,1:mixed}> */
    public static function valueForms(): array
    {
        return [
            'bulat' => [4, 4],
            'pecahan bulat' => [4.0, 4],
            'pecahan' => [36.75, 36.75],
            'dibulatkan dua angka' => [36.756, 36.76],
            'teks angka' => ['144', 144],
            'teks pecahan' => ['36.5', 36.5],
            'simbol' => ['J', 'J'],
            'simbol berspasi' => ['  U  ', 'U'],
            'teks kepanjangan' => [str_repeat('a', 17), null],
            'kosong' => ['', null],
            'null' => [null, null],
            'boolean' => [true, null],
            'larik' => [[1, 2], null],
        ];
    }

    /** Deret kosong tetap deret, bukan galat. */
    public function testEmptyStaysEmpty(): void
    {
        self::assertSame([], SeriesValues::parse(null));
        self::assertSame([], SeriesValues::parse('bukan json'));
        self::assertSame('[]', SeriesValues::toJson(null));
    }

    /** Titik paling awal di antara beberapa deret. */
    public function testEarliestAcrossSeries(): void
    {
        $a = SeriesValues::parse([['x' => '2026-09-29 10:00', 'y' => 1]]);
        $b = SeriesValues::parse([['x' => '2026-09-29 07:30', 'y' => 2]]);

        self::assertSame('2026-09-29 07:30', SeriesValues::earliest([$a, $b, []]));
        self::assertNull(SeriesValues::earliest([[], []]));
    }
}
