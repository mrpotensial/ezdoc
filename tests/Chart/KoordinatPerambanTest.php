<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use PHPUnit\Framework\TestCase;

/**
 * Rumus koordinat di peramban sama dengan di server.
 *
 * Gambarnya cuma dibuat satu pihak — PHP. Tapi lapisan pegangan wajib
 * menghitung sendiri ke dua arah: angka jadi tempat saat menaruh pegangan, dan
 * tempat jadi angka saat orang mengklik. Rumusnya karena itu ada di dua bahasa.
 *
 * Dua salinan rumus selalu berpisah diam-diam, dan berpisahnya tidak pernah
 * terbaca sebagai bug — cuma sebagai "titiknya agak meleset dari tempat saya
 * klik". Uji ini memakai angka yang SAMA dengan {@see SvgChartRendererTest}:
 * kalau salah satu sisi berubah sendirian, salah satunya gagal.
 */
final class KoordinatPerambanTest extends TestCase
{
    private static function node(): ?string
    {
        foreach (['node', 'nodejs'] as $bin) {
            $probe = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
            if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
                return $bin;
            }
        }

        return null;
    }

    public function testBrowserMathAgreesWithTheServer(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        $awal = strpos($skrip, '/** Pembulatan waktu saat menaruh titik, dalam menit. */');
        self::assertNotFalse($awal, 'blok papan grafik tidak ketemu');
        $akhir = strpos($skrip, '// ===== Document versioning =====', $awal);
        self::assertNotFalse($akhir, 'ujung blok papan grafik tidak ketemu');

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-chart-') . '.js';
        file_put_contents(
            $js,
            substr($skrip, $awal, $akhir - $awal)
            . "\nthis.ezChartX = ezChartX;\nthis.ezChartY = ezChartY;\n"
            . "this.ezChartDariTempat = ezChartDariTempat;\nthis.ezChartRapikan = ezChartRapikan;\n"
            . "this.ezChartWaktuKeTeks = ezChartWaktuKeTeks;\n"
        );

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/chart-koordinat.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }

    /**
     * Nilainya ditulis ke isian SEBELUM server menjawab.
     *
     * Versi pertama menulisnya hanya setelah jawaban datang — dan saat
     * jawabannya tidak datang, titik yang barusan diletakkan orang hilang tanpa
     * jejak: gambarnya tidak berubah, isiannya tidak berubah, tidak ada pesan
     * apa pun. Ditemukan saat mengklik di peramban sungguhan.
     */
    public function testValuesAreWrittenBeforeTheServerAnswers(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        $awal = strpos($skrip, 'function ezChartSimpan(keadaan) {');
        self::assertNotFalse($awal);
        $blok = substr($skrip, $awal, 600);

        self::assertSame(
            1,
            preg_match('/input\.value = JSON\.stringify\(keadaan\.nilai\[field\]\)/', $blok),
            'isian baru ditulis sesudah server menjawab'
        );
        self::assertTrue(
            strpos($blok, 'input.value') < strpos($blok, 'setTimeout'),
            'penulisan isian terjadi sesudah penundaan, bukan seketika'
        );
    }

    /**
     * Pegangan dan pemilih deret tidak terlihat sampai pointer masuk papannya.
     *
     * Diminta pemakai setelah mencoba partograf: "lingkaran biru transparan
     * tiap titik ... apa bisa tampil saat hover aja, agar tidak ganggu visual".
     * Tujuh puluh bulatan biru yang selalu menyala membuat grafiknya sulit
     * dibaca sebagai grafik — dan dibaca jauh lebih sering daripada diisi.
     */
    public function testHandlesStayInvisibleUntilHovered(): void
    {
        $halaman = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertSame(
            1,
            preg_match('/\.ez-chart-handle,\s*\.ez-chart-legend \{ opacity: 0;/', $halaman),
            'pegangan dan pemilih deret terlihat terus'
        );
        self::assertSame(
            1,
            preg_match('/\.ez-chart:hover \.ez-chart-handle,/', $halaman),
            'tidak ada yang memunculkannya saat pointer masuk'
        );
        self::assertSame(
            1,
            preg_match('/\.ez-chart:focus-within \.ez-chart-handle,/', $halaman),
            'yang memakai papan ketik tidak pernah melihat pegangannya'
        );
    }

    /**
     * Pemilih deret berdiri di pojok kanan atas panelnya.
     *
     * Di kiri ia menutupi titik paling awal — justru titik yang paling sering
     * dilihat orang. Diminta pemakai: "tombol untuk pilih mau tambah itu
     * mungkin pindah ke ujung kanan atas aja".
     */
    public function testSeriesPickerSitsTopRight(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match("/pilih\.style\.cssText = 'position:absolute;right:'/", $skrip),
            'pemilih deret masih ditempel dari kiri'
        );
        self::assertSame(
            1,
            preg_match('/kananKisi = keadaan\.scale\.left\s*\+ \(keadaan\.scale\.hours \* keadaan\.scale\.column_width\)/', $skrip),
            'tepi kanan kisinya ditebak, bukan dihitung dari skala'
        );
    }

    /** Dan kalau gambarnya gagal diperbarui, itu dikatakan. */
    public function testStaleDrawingIsAnnounced(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match("/catch \(e\) \{[\s\S]{0,400}?ezChartKabari\(keadaan, t\('chart\.stale'/", $skrip),
            'gambar yang tertinggal tidak dikatakan kepada yang mengisi'
        );

        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            self::assertArrayHasKey('stale', $lang['chart'], $bahasa);
        }
    }
}
