<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Contoh dibuka di halaman pembuatan dokumen.
 *
 * Diminta pemakai: "apa tidak bisa dilihat langsung di generate, misal
 * ?ezdoc_page=generate&template_id=contoh". Yang dinilai orang sebelum memakai
 * sesuatu adalah tampilan tempat ia akan bekerja, bukan halaman penjelasan.
 *
 * Yang dijaga di sini satu kalimat: **mode contoh tidak boleh bisa menulis apa
 * pun**. Halamannya dikunci lewat jalur yang sama dengan dokumen terkunci,
 * tombol Simpan mati, tanda tangan tidak ditawarkan, dan tidak ada dokumen yang
 * dicari maupun dibuat. Prosesnya sengaja dijalankan tanpa database MySQL sama
 * sekali: kalau ada jalur yang diam-diam bertanya ke sana, uji ini yang gagal
 * duluan.
 */
final class ContohDiGenerateTest extends TestCase
{
    private static function halaman(string $templateId = 'contoh', string $boleh = 'ya'): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_contoh_generate.php'),
            escapeshellarg($templateId),
            escapeshellarg($boleh)
        ), $baris);

        return implode("\n", $baris);
    }

    private static function sumberGenerate(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');
    }

    /** Contohnya tampil sebagai dokumen, lengkap dengan nilai contohnya. */
    public function testContohTampilSebagaiDokumen(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Siti Rahmawati', $halaman, $halaman);
        self::assertStringContainsString('042/SKT/IX/2026', $halaman);
        self::assertStringContainsString('Rina Kusuma', $halaman);
        self::assertStringNotContainsString('field-placeholder', $halaman, 'penanda field tidak diproses');
        self::assertStringNotContainsString('ttd-placeholder', $halaman, 'penanda TTD tidak diproses');
    }

    /** Orang yang membukanya diberi tahu bahwa ini contoh. */
    public function testAdaPenandaModeContoh(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Mode contoh', $halaman);
        self::assertStringContainsString('contoh/surat-keterangan-tindakan/', $halaman);
        self::assertStringContainsString('ezdoc_page=contoh', $halaman, 'tidak ada jalan kembali ke penjelasannya');
    }

    /**
     * Tidak ada yang bisa ditulis.
     *
     * Tiga lapis sekaligus, karena satu lapis selalu ada yang melewatinya:
     * halamannya terkunci, tombol Simpan mati, dan tombol tanda tangan tidak
     * pernah dirender.
     */
    public function testTidakAdaYangBisaDitulis(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('edit-off', $halaman, 'halaman contoh harus baca-saja');
        self::assertSame(
            1,
            preg_match('/<button[^>]*onclick="submitForm\(\)"[^>]*disabled/', $halaman),
            'tombol Simpan harus mati'
        );

        // Yang dihitung tombol di halamannya, bukan teks di dalam skrip: skripnya
        // memang menyimpan cetakan tombol untuk keadaan halaman yang lain.
        $tanpaSkrip = (string) preg_replace('/<script\b[\s\S]*?<\/script>/i', '', $halaman);
        self::assertSame(0, substr_count($tanpaSkrip, 'openSign('), 'tanda tangan tidak boleh ditawarkan');

        // Tidak menunjuk dokumen maupun template mana pun: kalaupun ada yang
        // memaksa mengirim formulirnya, tidak ada sasaran yang bisa ditimpa.
        self::assertStringContainsString('name="_doc_id" id="docIdInput" value="0"', $halaman);
        self::assertStringContainsString('name="template_id" value="0"', $halaman);
    }

    /** Nama contoh yang tidak ada tidak menghasilkan halaman apa pun. */
    public function testNamaContohTidakDikenalTidakMenampilkanDokumen(): void
    {
        $halaman = self::halaman('contoh:tidak-ada');

        self::assertStringNotContainsString('Siti Rahmawati', $halaman);
        self::assertStringNotContainsString('Mode contoh', $halaman);
    }

    /**
     * Nomor template biasa tidak ikut terbaca sebagai contoh.
     *
     * Pengenalnya terikat awal dan akhir, jadi `12contoh` atau `contoh lain`
     * tidak lolos, dan `template_id=29` tetap nomor biasa yang dicari di
     * database seperti sebelumnya.
     */
    public function testAngkaBiasaTidakDianggapContoh(): void
    {
        self::assertStringContainsString(
            "'/^contoh(?::([a-z0-9-]+))?$/'",
            self::sumberGenerate(),
            'pengenal contoh harus terikat awal-akhir'
        );
    }

    /** Contohnya dibaca dari berkas — bukan dari baris template. */
    public function testTemplatenyaDariBerkas(): void
    {
        $sumber = self::sumberGenerate();

        self::assertStringContainsString('\Ezdoc\Template\Contoh::muat($contohSlug)', $sumber, 'templatenya harus datang dari pemuat berkas');
        self::assertStringContainsString('$param_is_locked = 1;', $sumber);
        self::assertStringContainsString("\$GLOBALS['ezdocModeContoh'] = true;", $sumber);
    }
}
