<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\Geometry;
use Ezdoc\Chart\Scale;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Baris jam boleh berdiri di bawah panel mana pun, bukan hanya di dasar papan.
 *
 * Lembar partograf lama menaruh jamnya tepat di bawah pembukaan serviks —
 * tempat orang benar-benar membacanya — dan tidak mengulanginya di dasar.
 * Dengan barisnya terpaku di dasar, tiga belas baris memisahkan angka jam dari
 * titik yang ditandainya, dan yang membaca harus menyusuri kolom dengan jari.
 *
 * Yang dijaga di sini ada tiga, dan ketiganya pernah salah di perender lain:
 *
 * 1. barisnya muncul di ruang antar panel, bukan menimpa kisi panel berikutnya;
 * 2. papan lama tanpa kunci `time_axis` tergambar persis seperti sebelumnya;
 * 3. tingginya ikut berubah, supaya "muat di halaman" tidak berbohong.
 */
final class BarisJamTest extends TestCase
{
    /**
     * @param array<string,mixed> $extra
     * @param array<string,mixed> $panelSatu
     */
    private static function spec(array $extra = [], array $panelSatu = []): ChartSpec
    {
        return ChartSpec::fromArray($extra + [
            'hours' => 4,
            'column_width' => 40,
            'start_field' => 'start_time',
            'panels' => [
                $panelSatu + [
                    'title' => 'Pembukaan',
                    'height' => 100,
                    'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                    'series' => [['field' => 'dilatation']],
                ],
                [
                    'title' => 'Nadi',
                    'height' => 60,
                    'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                    'series' => [['field' => 'pulse']],
                ],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private static function values(): array
    {
        return ['start_time' => '2026-09-29 08:00'];
    }

    /** Semua angka y pada elemen <text> yang isinya jam. */
    private static function barisJam(string $svg): array
    {
        preg_match_all(
            '/<text x="[\d.\-]+" y="([\d.\-]+)"[^>]*text-anchor="middle">(\d{2}:\d{2})<\/text>/',
            $svg,
            $m
        );

        return array_map('floatval', array_unique($m[1]));
    }

    // ── Letaknya ─────────────────────────────────────────────────────────

    /** Bawaannya tidak berubah: barisnya di dasar papan. */
    public function testByDefaultTheHourRowSitsAtTheBottom(): void
    {
        $spec = self::spec();
        $svg = (new SvgChartRenderer())->render($spec, self::values());

        $baris = self::barisJam($svg);
        self::assertCount(1, $baris, 'ada lebih dari satu baris jam');
        self::assertSame($spec->height() - Geometry::BOTTOM + 12, $baris[0]);
    }

    /**
     * Panel yang memintanya memuat barisnya sendiri, di ruang di bawahnya.
     *
     * Yang dijaga bukan cuma "ada teksnya": angkanya harus jatuh DI ANTARA
     * dasar panel ini dan puncak panel berikutnya. Tanpa ruang tambahan ia
     * akan tertulis di atas kisi panel di bawahnya — dan itu terbaca sebagai
     * angka milik panel yang salah, bukan sebagai kesalahan gambar.
     */
    public function testAPanelCanCarryItsOwnHourRow(): void
    {
        $spec = self::spec(['time_axis' => 'none'], ['time_axis' => true]);
        $scale = Scale::create($spec, '2026-09-29 08:00');
        $svg = (new SvgChartRenderer())->render($spec, self::values());

        $baris = self::barisJam($svg);
        self::assertCount(1, $baris, 'barisnya tidak tepat satu');

        $dasarPanelSatu = $scale->top(0) + 100.0;
        $puncakPanelDua = $scale->top(1);

        self::assertGreaterThan($dasarPanelSatu, $baris[0], 'angkanya jatuh di dalam panelnya sendiri');
        self::assertLessThan($puncakPanelDua, $baris[0], 'angkanya jatuh di atas kisi panel berikutnya');
    }

    /** Dan dasarnya boleh tetap ada — dua baris, kalau memang diminta dua. */
    public function testBothRowsCanExistAtOnce(): void
    {
        $spec = self::spec([], ['time_axis' => true]);
        $svg = (new SvgChartRenderer())->render($spec, self::values());

        self::assertCount(2, self::barisJam($svg), 'salah satu barisnya hilang');
    }

    /** 'none' benar-benar menghapus baris di dasar. */
    public function testNoneRemovesTheBottomRow(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(['time_axis' => 'none']), self::values());

        self::assertSame([], self::barisJam($svg), 'masih ada baris jam yang tergambar');
    }

    // ── Tingginya ────────────────────────────────────────────────────────

    /**
     * Panel yang memuat baris jam menambah tinggi papan.
     *
     * Tinggi papan dipakai ringkasan penyunting untuk mengatakan "muat di
     * halaman". Kalau barisnya digambar tanpa ruangnya dihitung, kalimat itu
     * berbohong pada papan yang justru paling mepet.
     */
    public function testAPanelHourRowAddsToTheBoardHeight(): void
    {
        $tanpa = self::spec()->height();
        $dengan = self::spec([], ['time_axis' => true])->height();

        self::assertSame($tanpa + Geometry::HOUR_ROW, $dengan);
    }

    /** Dasar tanpa baris jam tidak menyisakan ruang untuk baris yang tidak ada. */
    public function testABareBottomIsShorter(): void
    {
        $penuh = self::spec()->height();
        $telanjang = self::spec(['time_axis' => 'none'])->height();

        self::assertSame($penuh - Geometry::BOTTOM + Geometry::BOTTOM_BARE, $telanjang);
    }

    /** Tapi kalau panel terakhir yang memuatnya, ruang itu tetap perlu. */
    public function testTheLastPanelsRowKeepsTheBottomSpace(): void
    {
        $spec = ChartSpec::fromArray([
            'hours' => 4,
            'time_axis' => 'none',
            'start_field' => 'start_time',
            'panels' => [[
                'height' => 100,
                'time_axis' => true,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'series' => [['field' => 'dilatation']],
            ]],
        ]);

        self::assertSame(Geometry::TOP + 100.0 + Geometry::BOTTOM, $spec->height());

        // Dan angkanya benar-benar muat di ruang itu.
        $svg = (new SvgChartRenderer())->render($spec, self::values());
        $baris = self::barisJam($svg);
        self::assertCount(1, $baris);
        self::assertLessThan($spec->height(), $baris[0], 'angkanya jatuh di luar papan');
    }

    // ── Papan lama ───────────────────────────────────────────────────────

    /**
     * Spesifikasi lama tergambar byte demi byte seperti sebelumnya.
     *
     * Papan yang sudah ditandatangani harus bisa dibangun ulang persis. Kunci
     * baru yang diam-diam menggeser satu piksel pun membuat PDF beku yang
     * tersimpan berbeda dari yang digambar ulang hari ini.
     */
    public function testOldSpecsAreUnchanged(): void
    {
        $lama = self::spec();
        $baruTapiSama = self::spec(['time_axis' => 'bottom']);

        $r = new SvgChartRenderer();
        self::assertSame($r->render($lama, self::values()), $r->render($baruTapiSama, self::values()));
    }

    /** `true` dan `false` diterima, karena orang menulisnya begitu di JSON. */
    public function testBooleansAreAccepted(): void
    {
        self::assertSame('bottom', self::spec(['time_axis' => true])->timeAxis());
        self::assertSame('none', self::spec(['time_axis' => false])->timeAxis());
    }

    /** Letak yang tidak dikenal ditolak, dengan pilihannya disebutkan. */
    public function testAnUnknownPlacementIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('bottom, none');

        self::spec(['time_axis' => 'tengah']);
    }

    /**
     * Tanpa jam mulai tidak ada baris jam di mana pun.
     *
     * Bukan sekadar "tidak ada angka yang terbaca": tanpa penjagaannya,
     * perender tetap menulis satu elemen teks KOSONG per kolom. Di layar
     * keduanya sama-sama tak terlihat, dan sampah itu ikut masuk PDF yang
     * ditandatangani tanpa ada yang tahu.
     */
    public function testWithoutAStartNothingIsWrittenAtAll(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec([], ['time_axis' => true]), []);

        self::assertSame([], self::barisJam($svg));
        self::assertSame(
            0,
            preg_match_all('/<text[^>]*><\\/text>/', $svg),
            'ada elemen teks kosong yang tetap ditulis'
        );
    }
}
