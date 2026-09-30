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
final class PapanGrafikDesainerTest extends TestCase
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
