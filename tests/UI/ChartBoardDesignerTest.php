<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Papan grafik bisa diatur dengan cara yang sama seperti elemen lain.
 *
 * Ditanya pemakai saat mencoba: "di designer memang belum bisa di atur kah
 * papan grafiknya?" Panelnya sudah ada — tapi di tab "All sections", sementara
 * ia sedang di tab "Selected". Dan **mengklik papannya tidak melakukan apa-apa**.
 *
 * Papan grafik tampil sebagai kotak seukuran cetakannya: besar, dan tanpa
 * tanggapan apa pun terhadap klik ia terbaca seperti gambar mati. Naluri
 * pertama orang mengkliknya, dan klik itu harus membuka pengaturannya — sama
 * seperti field, tanda tangan, logo, dan QR.
 */
final class ChartBoardDesignerTest extends TestCase
{
    private static function skrip(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/designer_scripts.php');
    }

    /** Klik papan di editor dikenali sebagai elemen berjenis `chart`. */
    public function testClickingTheBoardIsRecognised(): void
    {
        self::assertSame(
            1,
            preg_match(
                // Jaraknya longgar: di antara keduanya ada komentar yang
                // menjelaskan kenapa, dan em dash di situ tiga byte.
                "/el\.classList\.contains\('chart-placeholder'\)[\s\S]{0,900}?type = 'chart'/",
                self::skrip()
            ),
            'klik papan grafik tidak dikenali penyalur klik editor'
        );
    }

    /** Dan jenis itu tahu daftar panel mana yang memuat pengaturannya. */
    public function testTheTypeKnowsItsPanel(): void
    {
        self::assertSame(
            1,
            preg_match("/chart:\s*'chartList'/", self::skrip()),
            'jenis chart tidak dipetakan ke daftar panelnya'
        );
    }

    /**
     * Kartunya bisa ditemukan panel.
     *
     * Pencariannya lewat `[data-focus-target="<jenis>:<id>"]`. Kartu tanpa
     * atribut itu tidak akan pernah ditemukan, dan kliknya berakhir sunyi.
     */
    public function testTheCardCanBeFound(): void
    {
        self::assertSame(
            1,
            preg_match("/data-focus-target=\"chart:' \+ escapeHtml\(c\.id\)/", self::skrip()),
            'kartu grafik tidak punya sasaran fokus'
        );
    }

    /**
     * Tab "Selected" mengenal grafik.
     *
     * Inilah tab yang sedang dibuka pemakai saat bertanya. Tanpa baris ini,
     * mengklik papan memilihnya — tapi panelnya tetap kosong.
     */
    public function testSelectedTabKnowsCharts(): void
    {
        self::assertSame(
            1,
            preg_match("/const SEKSI = \{[\s\S]{0,300}?chart: 'chart',/", self::skrip()),
            'mode Selected tidak mengenal seksi grafik'
        );
    }

    /** Bagian panelnya ada, dan namanya ada di kedua bahasa. */
    public function testThePanelSectionExists(): void
    {
        $halaman = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/designer.php');

        self::assertStringContainsString('data-ez-section="chart"', $halaman);
        self::assertStringContainsString('id="chartList"', $halaman);
        self::assertStringContainsString('id="chartCount"', $halaman);

        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/designer.php';
            foreach (['panel_title', 'preview_button', 'apply_button', 'bad_json'] as $kunci) {
                self::assertArrayHasKey($kunci, $lang['chart'], $bahasa . ': ' . $kunci);
            }
            self::assertArrayHasKey('chart_label', $lang['toolbar_insert'], $bahasa);
        }
    }

    /** Tombol sisipnya ada di toolbar, bukan cuma terdaftar. */
    public function testTheInsertButtonIsOnTheToolbar(): void
    {
        $skrip = self::skrip();

        self::assertSame(1, preg_match("/addButton\('insertchart'/", $skrip), 'tombolnya tidak didaftarkan');
        self::assertSame(
            1,
            preg_match('/toolbar: .*insertchart/', $skrip),
            'tombolnya terdaftar tapi tidak muncul di toolbar'
        );
    }

    // ── Ubah ukuran dengan menyeret sudutnya ─────────────────────────────

    /**
     * Pegangannya bertanda bogus, jadi tidak pernah ikut tersimpan.
     *
     * Tanpa tanda itu, pegangan ubah-ukuran ikut masuk HTML template dan muncul
     * di hasil cetak sebagai kotak biru kecil di sudut grafik.
     */
    public function testTheGripIsNeverSavedIntoTheTemplate(): void
    {
        self::assertSame(
            1,
            preg_match("/grip\.setAttribute\('data-mce-bogus', 'all'\)/", self::skrip()),
            'pegangannya tidak bertanda bogus — ia akan ikut tersimpan'
        );
    }

    /**
     * Seretannya didengarkan di fase tangkap.
     *
     * TinyMCE juga mendengarkan mousedown di dokumen yang sama untuk memilih
     * elemen contenteditable=false, dan penanganannya berhenti di situ.
     */
    public function testDragIsListenedInTheCapturePhase(): void
    {
        self::assertSame(
            1,
            preg_match("/doc\.addEventListener\('mousedown', function \(ev\) \{[\s\S]{0,1600}?\}, true\);/", self::skrip()),
            'mousedown didengarkan di fase gelembung — TinyMCE keburu menangkapnya'
        );
    }

    /**
     * Dan TIDAK ada pendengar `mouseleave`.
     *
     * Di fase tangkap, `mouseleave` milik setiap elemen yang ditinggalkan
     * pointer ikut lewat dokumen — termasuk pegangan yang baru saja ditekan.
     * Seretannya dibatalkan pada gerakan pertama, sebelum sempat mengubah apa
     * pun, dan dari luar itu terlihat seperti pegangannya mati. Terjadi, dan
     * butuh menelusuri jejak event di peramban untuk menemukannya.
     */
    public function testNoMouseleaveCancelsTheDrag(): void
    {
        $skrip = self::skrip();
        $awal = strpos($skrip, 'function siapkanChartResize()');
        self::assertNotFalse($awal);
        $blok = substr($skrip, $awal, 3600);

        self::assertStringNotContainsString(
            "addEventListener('mouseleave'",
            $blok,
            'mouseleave di fase tangkap membatalkan seretan pada gerakan pertama'
        );
        self::assertStringContainsString("addEventListener('mouseup', selesai, true)", $blok);
    }

    /**
     * Ukuran baru diterjemahkan jadi spesifikasi, bukan sekadar gaya CSS.
     *
     * Kotak yang dilebarkan tanpa mengubah spesifikasinya akan kembali ke
     * ukuran lama begitu dokumennya dibuka lagi — gambarnya dihitung dari
     * spesifikasi, bukan dari gaya kotaknya.
     */
    public function testResizeRewritesTheSpecification(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/spec\.column_width = Math\.round\(Math\.max\(6, \(lebarBaru - sisaLebar\) \/ jam\)/', $skrip),
            'lebar seretan tidak diterjemahkan jadi lebar kolom'
        );
        self::assertSame(
            1,
            preg_match("/papan\.setAttribute\('data-chart-spec', JSON\.stringify\(spec\)\)/", $skrip),
            'spesifikasinya tidak ditulis balik ke penanda'
        );
    }

    /**
     * Sisa ukuran di luar kisi dihitung dari jawaban server.
     *
     * Kolom label kiri, ruang label jam, jarak antar panel — angkanya milik
     * ChartSpec. Menirunya di peramban berarti dua salinan angka yang sama,
     * dan dua salinan selalu berpisah.
     */
    public function testChromeSizeComesFromTheServer(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/sisaLebar = awal\.scale\.width - \(jam \* awal\.scale\.column_width\)/', $skrip),
            'sisa lebarnya ditebak, bukan dihitung dari jawaban server'
        );
        self::assertSame(
            1,
            preg_match('/sisaTinggi = awal\.scale\.height - jumlahTinggi/', $skrip),
            'sisa tingginya ditebak'
        );
    }

    /**
     * Seretan dibatasi selebar isi halaman.
     *
     * Papan yang lebih lebar dari halamannya tetap bisa dibuat, tapi bagian
     * kanannya terpotong saat dicetak — dan terpotongnya baru ketahuan di
     * kertas, setelah dokumennya ditandatangani.
     */
    public function testDragIsCappedAtThePageWidth(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const lebar = Math\.min\(\s*seret\.lebarMaks,/', $skrip),
            'seretan tidak dibatasi lebar halaman'
        );
        self::assertSame(
            1,
            preg_match('/lebarMaks = Math\.max\(180, induk\.clientWidth/', $skrip),
            'batas lebarnya ditebak, bukan diukur dari induknya'
        );
    }

    /**
     * Ukuran kotaknya diminta ke server, bukan ditebak.
     *
     * Yang menghitung tinggi papan adalah ChartSpec. Kotak di editor harus
     * seluas cetakannya — kalau ditebak, tata letak yang disusun orang di
     * designer tidak menggambarkan hasil cetaknya.
     */
    public function testTheBoxIsSizedByTheServer(): void
    {
        self::assertSame(
            1,
            preg_match('/chartPlaceholderHtml\(id, spec, hasil\.scale\.width, Math\.round\(hasil\.scale\.height\)\)/', self::skrip()),
            'ukuran kotak papan ditebak sisi peramban'
        );
    }
}
