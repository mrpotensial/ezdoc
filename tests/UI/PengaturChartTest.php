<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Papan grafik diatur lewat formulir, bukan dengan mengetik JSON.
 *
 * Diminta pemakai setelah memakai partograf: "tidak mungkin manusia tulis
 * manual json nya". Untuk papan tiga belas baris memang bukan — satu koma yang
 * salah membuat seluruh papan berhenti tergambar, dan letak salahnya tidak
 * kelihatan.
 *
 * Syarat yang diminta bersamaan: **jangan buang penyuntingan JSON-nya**.
 * Formulir menutupi yang lazim; JSON menutupi sisanya, dan menghapusnya
 * membuat yang jarang jadi mustahil.
 */
final class PengaturChartTest extends TestCase
{
    private static function skrip(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/designer_scripts.php');
    }

    private static function halaman(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/designer.php');
    }

    /** Modalnya ada, dan terdaftar di tempat modal lain terdaftar. */
    public function testTheModalExistsAndIsRegistered(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('id="chartEditorModal"', $halaman);
        self::assertSame(
            1,
            preg_match('/chartEditor: false,/', $halaman),
            'modalnya tidak terdaftar di store Alpine — ia tidak akan pernah terbuka'
        );
        self::assertStringContainsString('$store.modals.chartEditor', $halaman);
    }

    /**
     * Penyuntingan JSON tetap ada.
     *
     * Diminta tegas oleh pemakai, dan alasannya benar: formulir tidak akan
     * pernah menutupi setiap kemungkinan spesifikasi.
     */
    public function testTheJsonEditorIsKept(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('id="chartEditorJson"', $halaman, 'penyuntingan JSON dihapus');
        self::assertStringContainsString('data-chart-tab="json"', $halaman);
        self::assertStringContainsString('data-chart-tab="form"', $halaman);
    }

    /**
     * Yang diketik di tab JSON yang berlaku saat diterapkan.
     *
     * Dua tempat menyunting hal yang sama harus punya urutan yang jelas.
     * Tanpa ini, suntingan JSON diam-diam dibuang oleh isi formulir.
     */
    public function testJsonWinsWhenItIsTheOpenTab(): void
    {
        self::assertSame(
            1,
            preg_match(
                '/if \(json && !json\.classList\.contains\(.hidden.\)\) \{[\s\S]{0,300}?JSON\.parse\(box\.value\)/',
                self::skrip()
            ),
            'suntingan di tab JSON tidak dipakai saat menerapkan'
        );
    }

    /** Pindah dari JSON ke formulir juga membawa suntingannya. */
    public function testMovingBackFromJsonKeepsTheEdit(): void
    {
        self::assertSame(
            1,
            preg_match(
                "/Kembali dari JSON[\s\S]{0,300}?chartEditor\.spec = JSON\.parse\(box\.value\)/",
                self::skrip()
            ),
            'suntingan JSON hilang begitu kembali ke formulir'
        );
    }

    /**
     * Pratinjaunya hidup, dan ditunda.
     *
     * Mengatur papan tiga belas baris tanpa melihat hasilnya berarti menebak
     * lalu menutup modal untuk memeriksa. Tapi menggambar ulang pada tiap
     * ketukan tombol berarti satu permintaan per huruf.
     */
    public function testThePreviewIsLiveAndDebounced(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/chartEditor\.tunda = setTimeout\(chartEditorPratinjau, \d+\);/', $skrip),
            'pratinjaunya tidak ditunda — satu permintaan per huruf'
        );
        self::assertSame(
            1,
            preg_match('/chartEditorPratinjauTunda\(\);/', $skrip),
            'tidak ada yang memicu pratinjau saat isian berubah'
        );
    }

    /**
     * Nilai angka disimpan sebagai angka.
     *
     * `"hours": "16"` lolos dari sisi peramban dan ditolak server — pesannya
     * benar tapi sebabnya tidak kelihatan, karena di layar tertulis 16.
     */
    public function testNumbersAreStoredAsNumbers(): void
    {
        self::assertSame(
            1,
            preg_match("/else if \(el\.dataset\.num\) nilai = nilai === '' \? undefined : Number\(nilai\);/", self::skrip()),
            'isian angka tersimpan sebagai teks'
        );
    }

    /**
     * Legenda kosong berarti tidak ada legenda.
     *
     * Pilihan kosong di menu tidak boleh tersimpan sebagai legenda bernama
     * kosong — ChartSpec akan menolaknya.
     */
    public function testEmptyLegendMeansNoLegend(): void
    {
        self::assertSame(
            1,
            preg_match("/endsWith\('\.legend'\) && nilai === undefined\) nilai = false;/", self::skrip()),
            'legenda kosong tersimpan sebagai nilai kosong, bukan sebagai tidak ada'
        );
    }

    /**
     * Mengganti jenis panel menyusun ulang formulirnya.
     *
     * Baris simbol tidak punya sumbu Y maupun garis acuan. Formulir yang tidak
     * disusun ulang menampilkan isian yang tidak berlaku, dan yang mengisinya
     * mengira isiannya diabaikan diam-diam.
     */
    public function testChangingPanelTypeRebuildsTheForm(): void
    {
        self::assertSame(
            1,
            preg_match("/\/\\\\\.type\\\$\/\.test\(el\.dataset\.path \|\| ''\)\) renderChartEditorForm\(\);/", self::skrip()),
            'mengganti jenis panel tidak menyusun ulang formulirnya'
        );
    }

    /** Tombol "Atur…" ada di kartu panel kanan. */
    public function testTheSettingsButtonIsOnThePanelCard(): void
    {
        self::assertSame(
            1,
            preg_match("/onclick=\"bukaChartEditor\(' \+ i \+ '\)/", self::skrip()),
            'tidak ada jalan membuka pengaturannya dari panel kanan'
        );
    }

    /** Semua kalimatnya ada di kedua bahasa. */
    public function testEveryLabelExistsInBothLanguages(): void
    {
        $id = require dirname(__DIR__, 2) . '/lang/id/designer.php';
        $en = require dirname(__DIR__, 2) . '/lang/en/designer.php';

        self::assertSame(
            array_keys($id['chart']),
            array_keys($en['chart']),
            'kunci chart di kedua bahasa tidak sama'
        );

        foreach (['editor_title', 'tab_form', 'open_editor', 'board_section', 'series_section',
                  'references_section', 'verticals_section', 'levels_section', 'add_panel'] as $kunci) {
            self::assertArrayHasKey($kunci, $id['chart'], 'id: ' . $kunci);
            self::assertNotSame('', $id['chart'][$kunci], 'id: ' . $kunci . ' kosong');
        }
    }
}
