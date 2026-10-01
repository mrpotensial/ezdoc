<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use PHPUnit\Framework\TestCase;

/**
 * Lapisan isian rentang di peramban.
 *
 * Yang dibuktikan benar-benar bekerja dibuktikan di peramban sungguhan
 * (`scratchpad/uji_timeline.js`, lewat DevTools Protocol): seretan membuat
 * rentang, badannya digeser, ujung kanannya diseret melewati tepi jadi
 * rentang terbuka, klik ganda memberi label, pilih lalu Delete menghapusnya.
 *
 * Yang dijaga DI SINI keputusan-keputusan yang mudah hilang saat kodenya
 * disunting lagi, dan yang hilangnya tidak terlihat sampai ada yang mengisi
 * lembar sungguhan. Tiga di antaranya ditemukan justru saat mencobanya di
 * peramban, bukan saat menulisnya.
 */
final class IsianRentangTest extends TestCase
{
    private static function skrip(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');
    }

    /**
     * Penangan titik tidak menyentuh kotak rentang.
     *
     * Keduanya memakai kelas `ez-chart-handle` supaya aturan "muncul saat
     * disentuh pointer" berlaku untuk keduanya — dan itu membuat penangan
     * titik ikut menjaringnya. Akibatnya: menyeret rentang menulis ulang
     * isinya sebagai titik, dan jam selesainya hilang. Gambarnya tetap
     * muncul, cuma artinya berubah.
     */
    public function testThePointHandlerLeavesRangesAlone(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            2,
            preg_match_all('/if \(!pegangan \|\| pegangan\.dataset\.rentang\) return;/', $skrip),
            'penangan titik masih menjaring kotak rentang'
        );
    }

    /**
     * Memilih rentang TIDAK menggambar ulang lapisannya.
     *
     * Menggambar ulang menghapus elemen yang barusan diklik. Klik kedua
     * sepersekian detik kemudian lalu mendarat di elemen lain, dan klik ganda
     * untuk memberi label tidak pernah sampai. Ketahuan saat mencobanya di
     * peramban — di kode tidak ada yang mencurigakan.
     */
    public function testSelectingARangeDoesNotRebuildTheLayer(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/keadaan\.pilih = \{ field: seret\.field, index: seret\.index \};[\s\S]{0,400}?ezChartSorotRentang\(lapisan, kotak\);/', $skrip),
            'sorotannya tidak disetel di tempat'
        );
        self::assertSame(
            0,
            preg_match('/keadaan\.pilih = \{ field: seret\.field, index: seret\.index \};\s*ezChartGambarPegangan/', $skrip),
            'memilih rentang menggambar ulang seluruh lapisan'
        );
    }

    /**
     * Delete didengarkan di dokumen, bukan di lapisannya.
     *
     * Lapisan sebuah `<div>`: ia tidak menerima tombol papan ketik kecuali
     * sedang terpilih, dan mengklik rentang tidak selalu memindahkan pilihan
     * ke situ.
     */
    public function testDeleteIsHeardOnTheDocument(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/document\.addEventListener\('keydown', function \(ev\) \{\s*if \(ev\.key !== 'Delete'/", $skrip),
            'Delete didengarkan di lapisan, yang tidak menerima tombol papan ketik'
        );
        self::assertSame(
            1,
            preg_match('/if \(fokus && \/\^\(INPUT\|TEXTAREA\|SELECT\)\$\/\.test\(fokus\.tagName\)\) return;/', $skrip),
            'yang sedang mengetik di isian lain ikut kehilangan rentangnya'
        );
    }

    /**
     * Ujung kanan yang diseret melewati tepi berarti "belum selesai".
     *
     * Bukan "selesai tepat di tepi" — dan itu justru keadaan yang paling
     * sering dipakai saat mengisi: infus yang masih jalan.
     */
    public function testDraggingPastTheEdgeOpensTheRange(): void
    {
        self::assertSame(
            1,
            preg_match('/if \(kolom > scale\.hours \+ 0\.5\) \{\s*r\.x2 = false;/', self::skrip()),
            'diseret melewati tepi tidak membuat rentangnya terbuka'
        );
    }

    /** Satu kolom yang diseret berarti kolom itu, dari awalnya sampai awal berikutnya. */
    public function testOneDraggedColumnCoversThatWholeColumn(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const sampai = Math\.min\(scale\.hours, Math\.floor\(Math\.max\(kolomAwal, kolomAkhir\)\) \+ 1\);/', $skrip),
            'kolom terakhir yang diseret tidak ikut tercakup'
        );
    }

    /** Rentang terbuka tidak diberi pegangan ujung kanan. */
    public function testAnOpenRangeHasNoRightGrip(): void
    {
        self::assertSame(
            1,
            preg_match("/if \(ujung === 'akhir' && r\.x2 === false\) return;/", self::skrip()),
            'ujung yang terbuka tetap bisa diseret tanpa sengaja'
        );
    }

    /**
     * Satu klik di panel jalur tidak membuat apa-apa.
     *
     * Rentang butuh dua tempat. Penangan titik yang ikut berjalan di situ akan
     * menaruh pengukuran di panel yang tidak punya sumbu Y.
     */
    public function testASingleClickOnALaneCreatesNothing(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/if \(iPanelKlik !== null && ezChartJalurTimeline\(keadaan\.scale\.panels\[iPanelKlik\]\)\) return;/', $skrip),
            'satu klik di jalur masih menaruh titik'
        );
        self::assertSame(
            1,
            preg_match('/if \(ezChartJalurTimeline\(panel\)\) return null;/', $skrip),
            'panel jalur masih dipetakan jadi titik'
        );
    }

    /**
     * Bentuk baku di peramban menulis label kosong, bukan menghilangkannya.
     *
     * `JSON.stringify` membuang kunci yang nilainya `undefined`, dan bentuk
     * tersimpannya lalu berbeda dari yang ditulis server untuk isi yang sama:
     * dua byte, dua hash, satu dokumen. Ditemukan uji kesamaan PHP↔JS, bukan
     * dengan membacanya.
     */
    public function testTheBrowserWritesAnEmptyLabelNotAMissingOne(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/const label = \(t\.y === undefined \|\| t\.y === null\) \? '' : t\.y;/", $skrip),
            'label yang tidak ada ditulis sebagai kunci yang hilang'
        );
        self::assertSame(
            1,
            preg_match("/if \(!punyaX2 && label === ''\) return;/", $skrip),
            'titik tanpa nilai ikut disimpan peramban, padahal server membuangnya'
        );
    }

    /** Jumlah jalur ikut diekspor, supaya klik bisa dipetakan ke deret yang benar. */
    public function testTheLaneCountIsExported(): void
    {
        $skala = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Chart/Scale.php');

        self::assertSame(
            1,
            preg_match("/'lanes' => count\(\\\$deret\),/", $skala),
            'jumlah jalur tidak diekspor — klik akan selalu jatuh ke deret pertama'
        );
    }

    /** Kalimat yang dipakai lapisannya ada di kedua bahasa. */
    public function testItsWordsExistInBothLanguages(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';

            self::assertArrayHasKey('ask_range', $lang['chart'], $bahasa);
            self::assertArrayHasKey('range_open', $lang['chart'], $bahasa);
        }
    }
}
