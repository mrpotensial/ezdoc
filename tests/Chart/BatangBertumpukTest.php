<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Batang bertumpuk.
 *
 * ## Diisi dengan mengetik, bukan diseret
 *
 * Bukan karena seretnya sulit dibuat: nilainya bukan jenis yang ditaksir
 * mata. Orang menulis "250 cc" karena membaca kantong, bukan karena membidik
 * tinggi batang — dan potongan bernilai nol tidak punya tinggi, jadi tidak
 * punya pegangan untuk diseret sama sekali. Aturan seret apa pun punya lubang
 * itu.
 *
 * Polanya bukan pengecualian baru: baris sel sudah diisi dengan mengetik sejak
 * awal. Lihat `docs/260930-grafik-jenis-baru.md` §6.4 dan §10 butir 2.
 */
final class BatangBertumpukTest extends TestCase
{
    /**
     * Balans cairan: masuk ke atas, keluar ke bawah.
     *
     * Sumbu −1000..2000 setinggi 120 mulai y 16, jadi 1 mL = 0,04 px dan
     * nol ada di y 16 + ((2000/3000) × 120) = 96.
     */
    private static function spec(bool $stacked = true): ChartSpec
    {
        return ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'bar',
                'height' => 120,
                'stacked' => $stacked,
                'y' => ['min' => -1000, 'max' => 2000, 'step' => 500],
                'series' => [
                    ['field' => 'oral', 'title' => 'Oral', 'color' => '#1d4ed8'],
                    ['field' => 'infus', 'title' => 'Infus', 'color' => '#16a34a'],
                    ['field' => 'urine', 'title' => 'Urine', 'color' => '#eab308'],
                ],
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private static function nilai(): array
    {
        return [
            'mulai' => '2026-09-29 08:00',
            'oral' => [['x' => '2026-09-29 08:00', 'y' => 250]],
            'infus' => [['x' => '2026-09-29 08:00', 'y' => 500]],
            'urine' => [['x' => '2026-09-29 08:00', 'y' => -300]],
        ];
    }

    /** @return array<int,array<int,string>> */
    private static function batang(string $svg): array
    {
        // Batang selalu bergaris tepi sewarna isinya — itu yang membedakannya
        // dari latar halaman dan dari kotak pita nilai.
        preg_match_all(
            '/<rect x="([\d.\-]+)" y="([\d.\-]+)" width="([\d.\-]+)" height="([\d.\-]+)"'
            . ' fill="(#[0-9a-f]{6})" stroke="#[0-9a-f]{6}" stroke-width="0\.8"\/>/',
            $svg,
            $m,
            PREG_SET_ORDER
        );

        return $m;
    }

    /**
     * Potongan ditumpuk berurutan dari dasar, tiap deret warnanya sendiri.
     *
     * Kolom 0 mulai x 64, lebar batang 0,6 × 40 = 24, jadi kiri 72.
     * 1 mL = 120/3000 = 0,04 px. Nol ada di y 96.
     *   oral  250  → 96 sampai 86    (tinggi 10)
     *   infus 500  → 86 sampai 66    (tinggi 20, di ATAS oral)
     */
    public function testSegmentsStackFromTheBaseline(): void
    {
        $batang = self::batang((new SvgChartRenderer())->render(self::spec(), self::nilai()));

        self::assertCount(3, $batang, 'potongannya tidak tiga');
        self::assertSame(['72', '86', '24', '10', '#1d4ed8'], array_slice($batang[0], 1), 'oral');
        self::assertSame(['72', '66', '24', '20', '#16a34a'], array_slice($batang[1], 1), 'infus di atas oral');
    }

    /**
     * Nilai negatif ditumpuk ke BAWAH, dalam tumpukannya sendiri.
     *
     * Urine −300 bukan bagian dari tumpukan masuk. Menumpuknya searah
     * menghasilkan batang setinggi 1050 mL yang tidak menggambarkan apa pun —
     * bukan masuk, bukan keluar, bukan selisihnya.
     */
    public function testNegativeValuesStackDownwards(): void
    {
        $batang = self::batang((new SvgChartRenderer())->render(self::spec(), self::nilai()));

        // Urine −300 → dari nol (96) turun 12 px, jadi 96..108.
        self::assertSame(['72', '96', '24', '12', '#eab308'], array_slice($batang[2], 1));
    }

    /**
     * Jumlah yang melewati tepi sumbu dipotong, bukan dibuang.
     *
     * Aturan "nilai di luar sumbu tidak digambar" berlaku untuk pengukuran
     * tunggal — DJJ 1440 adalah salah ketik. Di sini yang melewati tepi adalah
     * JUMLAH, dan jumlah yang lebih besar dari sumbunya keadaan yang sah.
     * Membuangnya menghilangkan seluruh kolom, bukan satu angka.
     */
    public function testATotalBeyondTheAxisIsClippedNotDropped(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), [
            'mulai' => '2026-09-29 08:00',
            'oral' => [['x' => '2026-09-29 08:00', 'y' => 1800]],
            'infus' => [['x' => '2026-09-29 08:00', 'y' => 900]],
        ]);
        $batang = self::batang($svg);

        self::assertCount(2, $batang, 'potongan yang melewati tepi ikut hilang');

        // Puncak panel y 16; potongan kedua berhenti di situ, tidak melewatinya.
        $atas = min((float) $batang[0][2], (float) $batang[1][2]);
        self::assertGreaterThanOrEqual(16.0, $atas, 'potongannya terlukis di luar panel');
    }

    /** Kolom tanpa nilai tidak menghasilkan batang. */
    public function testAnEmptyColumnDrawsNothing(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), [
            'mulai' => '2026-09-29 08:00',
            'oral' => [['x' => '2026-09-29 08:00', 'y' => 0]],
        ]);

        self::assertSame([], self::batang($svg), 'nilai nol menghasilkan potongan setinggi nol');
    }

    /** Panel `bar` biasa tetap seperti sebelumnya: deret kedua pemilih isian. */
    public function testAPlainBarPanelIsUnchanged(): void
    {
        $biasa = ChartSpec::fromArray([
            'hours' => 4,
            'column_width' => 40,
            'left' => 64,
            'start_field' => 'mulai',
            'panels' => [[
                'type' => 'bar',
                'height' => 120,
                'y' => ['min' => 0, 'max' => 10, 'step' => 5],
                'series' => [['field' => 'kontraksi', 'color' => '#111111']],
            ]],
        ]);

        $svg = (new SvgChartRenderer())->render($biasa, [
            'mulai' => '2026-09-29 08:00',
            'kontraksi' => [['x' => '2026-09-29 08:00', 'y' => 5]],
        ]);

        // Satu batang, dari nilai 5 (y 76) sampai dasar panel (y 136).
        $batang = self::batang($svg);
        self::assertCount(1, $batang);
        self::assertSame(['72', '76', '24', '60'], array_slice($batang[0], 1, 4));
    }

    /**
     * Nilai bukan angka tidak ikut ditumpuk.
     *
     * `(float) "3 kali"` adalah 3 di PHP. Tanpa penjagaan, catatan berupa
     * teks — "3 kali", "banyak" — diam-diam jadi potongan setinggi angka yang
     * kebetulan ada di depannya. Nilai nol tidak memperlihatkan itu: ia
     * menghasilkan potongan setinggi nol yang memang tidak digambar.
     */
    public function testNonNumericValuesAreNotStacked(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), [
            'mulai' => '2026-09-29 08:00',
            'oral' => [['x' => '2026-09-29 08:00', 'y' => '3 kali']],
            'infus' => [['x' => '2026-09-29 08:00', 'y' => 500]],
        ]);
        $batang = self::batang($svg);

        self::assertCount(1, $batang, 'teks ikut jadi potongan');
        // Infus 500 berdiri dari dasar (96), bukan di atas potongan hantu.
        self::assertSame(['72', '76', '24', '20', '#16a34a'], array_slice($batang[0], 1));
    }

    /**
     * Kolomnya digambar urut waktu, bukan urut deret mana yang menyebutnya
     * lebih dulu.
     *
     * Tidak mengubah gambarnya — kolom tidak saling menindih — tapi mengubah
     * urutan elemen di SVG. Urutan yang mengikuti waktu membuat berkasnya
     * bisa dibaca dan dibandingkan orang; urutan yang mengikuti kebetulan
     * tidak.
     */
    public function testColumnsAreDrawnLeftToRight(): void
    {
        $svg = (new SvgChartRenderer())->render(self::spec(), [
            'mulai' => '2026-09-29 08:00',
            // Deret pertama menyebut kolom KEDUA; deret kedua kolom pertama.
            'oral' => [['x' => '2026-09-29 09:00', 'y' => 200]],
            'infus' => [['x' => '2026-09-29 08:00', 'y' => 400]],
        ]);

        $batang = self::batang($svg);
        self::assertCount(2, $batang);
        self::assertSame('72', $batang[0][1], 'kolom pertama bukan yang digambar duluan');
        self::assertSame('112', $batang[1][1]);
    }

    // ── Bentuk spesifikasi ───────────────────────────────────────────────

    /**
     * `stacked` bersama `levels` ditolak.
     *
     * Keduanya memperebutkan deret kedua: yang satu menganggapnya potongan,
     * yang lain menganggapnya pemilih isian. Mengabaikan salah satunya
     * diam-diam menghasilkan gambar yang tidak diminta siapa pun.
     */
    public function testStackedAndFillLevelsCannotBeCombined(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak berlaku di batang bertumpuk');

        ChartSpec::fromArray([
            'panels' => [[
                'type' => 'bar',
                'stacked' => true,
                'levels' => ['1' => 'solid'],
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    public function testStackedOnlyAppliesToBarPanels(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("jenis 'bar'");

        ChartSpec::fromArray([
            'panels' => [[
                'type' => 'line',
                'stacked' => true,
                'series' => [['field' => 'a']],
            ]],
        ]);
    }

    // ── Isian di peramban ────────────────────────────────────────────────

    /**
     * Skala yang diekspor membawa penanda bertumpuk.
     *
     * Tanpa itu, yang di peramban tidak punya cara tahu panel mana yang harus
     * diketik — dan akan memetakan tinggi klik jadi angka, di panel yang
     * angkanya jumlah beberapa deret.
     */
    public function testTheExportedScaleSaysWhichPanelsAreStacked(): void
    {
        $ekspor = (new SvgChartRenderer())->scale(self::spec(), self::nilai())->export();

        self::assertTrue($ekspor['panels'][0]['stacked']);

        $biasa = (new SvgChartRenderer())->scale(self::spec(false), self::nilai())->export();
        self::assertFalse($biasa['panels'][0]['stacked']);
    }

    /** Dan lapisan pegangan menanyakan angkanya, bukan menebak dari tingginya. */
    public function testTheFillLayerAsksInsteadOfGuessing(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match('/function ezChartDiketik\(panel\) \{\s*return ezChartSel\(panel\) \|\| !!\(panel && panel\.stacked\);/', $skrip),
            'panel bertumpuk tidak ikut dihitung sebagai panel yang diketik'
        );
        self::assertSame(
            1,
            preg_match('/if \(ezChartDiketik\(panel\)\) \{\s*return \{ panel: iPanel,[^}]*tanya: true \};/', $skrip),
            'klik di panel bertumpuk masih memetakan tinggi jadi angka'
        );
    }

    /** Keluarannya tetap sama byte demi byte. */
    public function testStillDeterministic(): void
    {
        $r = new SvgChartRenderer();

        self::assertSame(
            $r->render(self::spec(), self::nilai()),
            $r->render(self::spec(), self::nilai())
        );
    }
}
