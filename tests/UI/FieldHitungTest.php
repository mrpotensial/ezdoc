<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Field hitung: dari designer, ke layar, ke PDF.
 *
 * Diminta pemakai untuk lembar skor (Bishop, Flamm & Geiger): "aku lebih suka
 * [fitur hitung], sekaligus agar ada fitur baru yang bisa direuse, pastikan
 * kompatibel berbagai bentuk, dan bisa digunakan lebih dari 1" — lalu
 * "pastikan fitur B bisa diatur via designer juga".
 *
 * Nilainya dihitung DUA KALI dengan sengaja: di peramban supaya angkanya
 * berubah saat diketik, dan di server supaya yang tercetak tidak pernah
 * bergantung pada peramban. Yang dijaga di sini: keduanya ada, keduanya
 * memakai satu bahasa rumus, dan designer bisa mengaturnya.
 */
final class FieldHitungTest extends TestCase
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

    private static function baca(string $berkas): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $berkas);
    }

    /** Halaman contoh Skor Bishop, dirender apa adanya. */
    private static function halamanBishop(): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_contoh.php'),
            escapeshellarg('ya'),
            escapeshellarg('skor-bishop')
        ), $baris);

        return implode("\n", $baris);
    }

    // ── Hasilnya ─────────────────────────────────────────────────────────

    /**
     * Totalnya benar-benar terhitung di dokumen yang dirender.
     *
     * 2 + 2 + 1 + 2 + 1 = 8. Kalau angkanya tidak muncul, yang rusak bisa di
     * mana saja antara atribut, perender, dan penghitungnya — jadi uji ini
     * sengaja memakai dokumen utuh, bukan potongan.
     */
    public function testTotalTerhitungDiDokumen(): void
    {
        $halaman = self::halamanBishop();

        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/s', $halaman, $m), 'pratinjau tidak ada');
        $pratinjau = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        self::assertSame(
            1,
            preg_match('/TOTAL SKOR BISHOP[\s\S]{0,200}?>8</', $pratinjau),
            'total skor tidak terhitung di dokumen'
        );
        self::assertStringNotContainsString('{{total_bishop}}', $pratinjau, 'penanda field hitung tidak diproses');
    }

    /**
     * Blok bersyarat membaca hasil hitungan, bukan kekosongan.
     *
     * Skor contohnya 8, jadi kalimat "skor di bawah 6" TIDAK boleh muncul.
     * Sebelum nilai hitung dilengkapi lebih dulu, syaratnya membandingkan
     * dengan kosong — dibaca nol — dan kalimat itu muncul di dokumen yang
     * skornya justru matang.
     */
    public function testBlokBersyaratMembacaHasilHitungan(): void
    {
        $halaman = self::halamanBishop();
        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/s', $halaman, $m));
        $pratinjau = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        self::assertStringNotContainsString('Skor di bawah 6', $pratinjau, 'kalimat saran muncul padahal skornya 8');
    }

    /** Kedua perender memakai penghitung yang sama. */
    public function testLayarDanPdfMemakaiPenghitungYangSama(): void
    {
        foreach (['views/document/generate.php', 'lib/pdf_compose.php'] as $berkas) {
            self::assertStringContainsString(
                '\Ezdoc\Format\CalcField::hitung(',
                self::baca($berkas),
                $berkas . ' tidak memakai penghitung bersama'
            );
        }

        // Dan nilainya dilengkapi sebelum blok bersyarat dievaluasi.
        $pdf = self::baca('lib/pdf_compose.php');
        $posLengkap = strpos($pdf, 'CalcField::lengkapiNilai(');
        $posSyarat = strpos($pdf, 'processConditionalSections($html, $dbFields)');
        self::assertNotFalse($posLengkap, 'nilai hitung tidak pernah dilengkapi');
        self::assertNotFalse($posSyarat);
        self::assertLessThan($posSyarat, $posLengkap, 'syarat dievaluasi sebelum angkanya ada');
    }

    // ── Designer ─────────────────────────────────────────────────────────

    /** Bisa disisipkan dari toolbar, dan rumusnya ditanyakan saat itu. */
    public function testBisaDisisipkanDariToolbar(): void
    {
        $skrip = self::baca('views/_partials/designer_scripts.php');

        self::assertStringContainsString("insertFieldPrompt('calc')", $skrip, 'tidak ada menu sisip field hitung');
        self::assertSame(
            1,
            preg_match("/fieldType === 'calc'[\s\S]{0,600}?data-type=\\\\\"calc\\\\\"|fieldType === 'calc'[\s\S]{0,600}?data-type=\"calc\"/", $skrip),
            'menyisipkan field hitung tidak menulis tipe calc'
        );
        self::assertStringContainsString('toolbar_insert.formula_prompt', $skrip, 'rumusnya tidak ditanyakan saat disisipkan');
    }

    /** Bisa diatur dari panel: tipe, rumus, dan jumlah desimalnya. */
    public function testBisaDiaturDariPanel(): void
    {
        $skrip = self::baca('views/_partials/designer_scripts.php');

        self::assertStringContainsString('<option value="calc"', $skrip, 'tipe hitung tidak ada di pilihan tipe');
        self::assertStringContainsString('updateFieldFormula(', $skrip, 'rumus tidak bisa diubah dari panel');
        self::assertStringContainsString('updateFieldDecimals(', $skrip, 'jumlah desimal tidak bisa diubah dari panel');
        self::assertStringContainsString("const needsFormula = field.type === 'calc';", $skrip);

        // Rumusnya dibaca kembali dari templatenya, kalau tidak panelnya kosong
        // setiap kali halaman dibuka.
        self::assertStringContainsString("attrs.match(/data-formula=", $skrip, 'rumus tidak dibaca ulang dari template');
    }

    /** Kalimatnya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $designer = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/designer.php';
            self::assertArrayHasKey('calc', $designer['field_type'], $bahasa);
            foreach (['formula_label', 'formula_placeholder', 'formula_hint', 'decimals_label'] as $kunci) {
                self::assertArrayHasKey($kunci, $designer['field'], $bahasa . ': ' . $kunci);
            }
            foreach (['field_type_calc', 'formula_prompt'] as $kunci) {
                self::assertArrayHasKey($kunci, $designer['toolbar_insert'], $bahasa . ': ' . $kunci);
            }

            $generate = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            self::assertArrayHasKey('calc_title', $generate['field'], $bahasa);
        }
    }

    // ── Sisi peramban ────────────────────────────────────────────────────

    /** Penghitung di peramban sepakat dengan yang di server. */
    public function testPenghitungPeramba​nSepakatDenganServer(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = self::baca('views/_partials/generate_scripts.php');
        $awal = strpos($skrip, 'function _pecahRumus(rumus) {');
        self::assertNotFalse($awal, 'pemecah rumus tidak ketemu');
        $tanda = 'return nilai.length === 1 && isFinite(nilai[0]) ? nilai[0] : null;';
        $akhir = strpos($skrip, $tanda, $awal);
        self::assertNotFalse($akhir, 'penghitung rumus tidak ketemu');
        $akhir = strpos($skrip, '}', $akhir + strlen($tanda)) + 1;

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-hitung-') . '.js';
        file_put_contents($js, substr($skrip, $awal, $akhir - $awal)
            . "\nthis._pecahRumus = _pecahRumus;\nthis._hitungRumus = _hitungRumus;\n");

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/field-hitung.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }

    /** Menghitung ulang juga menyegarkan blok bersyarat, bukan menunggu pewaktu. */
    public function testMenghitungMenyegarkanBlokBersyarat(): void
    {
        $skrip = self::baca('views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match('/function terapkanFieldHitung\(\) \{[\s\S]{0,600}?applyConditionalSections\(\);/', $skrip),
            'hasil hitungan tidak menyegarkan blok bersyarat'
        );
    }
}
