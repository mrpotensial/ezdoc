<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Contoh dibuka di halaman pembuatan dokumen.
 *
 * Diminta pemakai: "apa tidak bisa dilihat langsung di generate, misal
 * ?ezdoc_page=generate&template_id=example" — lalu, setelah mencobanya:
 * "tombolnya kok read only semua, aku ingin bisa aksi".
 *
 * Jadi halamannya HIDUP: isian bisa diketik, tanda tangan bisa digambar,
 * tombol Simpan bisa ditekan. Yang menjaga database bukan tombol yang mati,
 * melainkan tiga hal yang tidak bisa ditawar: tidak ada baris template, tidak
 * ada dokumen yang ditunjuk, dan simpannya dibatalkan sebelum berangkat lalu
 * dialihkan ke peramban (lihat ExampleBrowserSaveTest).
 *
 * Prosesnya sengaja dijalankan tanpa MySQL sama sekali: kalau ada jalur yang
 * diam-diam bertanya ke sana, uji ini yang gagal duluan.
 */
final class ExampleInGenerateTest extends TestCase
{
    private static function halaman(string $templateId = 'example', string $boleh = 'ya', string $tambahan = ''): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_generate.php'),
            escapeshellarg($templateId),
            escapeshellarg($boleh),
            escapeshellarg($tambahan)
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

    /**
     * Tabel kuerinya TERISI, tanpa kueri yang berjalan.
     *
     * Dilaporkan pemakai: "kok tabelnya kosong ya?". Tabel kosong tidak
     * menunjukkan apa pun tentang fitur tabel — sementara menjalankan kuerinya
     * berarti contoh ini menyentuh database orang.
     */
    public function testTabelKueriTerisiDariBerkas(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Konsultasi dokter umum', $halaman);
        self::assertStringContainsString('Pemeriksaan laboratorium', $halaman);
        self::assertStringContainsString('595.000', $halaman, 'penjumlahannya ikut terhitung');
        self::assertStringNotContainsString('{{tabledb', $halaman);
    }

    /** Orang yang membukanya diberi tahu bahwa ini contoh, dan ke mana simpanannya pergi. */
    public function testAdaPenandaModeContoh(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Mode contoh', $halaman);
        self::assertStringContainsString('ez-example-banner', $halaman, 'pitanya harus punya gayanya sendiri');
        self::assertStringContainsString('peramban ini saja', $halaman, 'tujuan simpannya harus disebut');
        self::assertStringContainsString('examples/surat-keterangan-tindakan/', $halaman);
        self::assertStringContainsString('ezdoc_page=example', $halaman, 'tidak ada jalan kembali ke penjelasannya');
        self::assertStringContainsString('ezExampleClear', $halaman, 'harus ada cara membuang simpanan percobaan');
        // Pintu ke PDF-nya di pita ini, bukan di bagian Admin — yang memang
        // superadmin saja. Tanpa raw=1: contoh tidak punya artefak beku.
        self::assertSame(
            1,
            preg_match('/<a href="\?template_id=example%3A[^"]*view=pdf"[^>]*>/', $halaman),
            'contoh tidak punya jalan melihat PDF-nya'
        );
    }

    /**
     * Halamannya bisa dicoba — dan tetap tidak menyentuh database.
     *
     * Dua hal yang gampang dikira bertentangan. Yang menjaganya bukan tombol
     * yang mati: tidak ada template maupun dokumen yang ditunjuk, jadi tidak
     * ada sasaran yang bisa ditimpa seandainya satu permintaan lolos.
     */
    public function testBisaDicobaTanpaSasaranYangBisaDitimpa(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('<body class="edit-on"', $halaman, 'isian contoh harus hidup');
        self::assertSame(
            0,
            preg_match('/<button[^>]*onclick="submitForm\(\)"[^>]*disabled/', $halaman),
            'tombol Simpan tidak perlu dimatikan — simpannya yang dialihkan'
        );

        // Tombol tanda tangan dihitung di halamannya, bukan di dalam skrip.
        $tanpaSkrip = (string) preg_replace('/<script\b[\s\S]*?<\/script>/i', '', $halaman);
        self::assertGreaterThan(0, substr_count($tanpaSkrip, 'openSign('), 'tanda tangan harus bisa dicoba');

        self::assertStringContainsString('name="_doc_id" id="docIdInput" value="0"', $halaman);
        self::assertStringContainsString('name="template_id" value="0"', $halaman);
        self::assertStringContainsString("window.addEventListener('ezcetak:before-save'", $halaman, 'simpannya harus dibatalkan');
    }

    /** Identitas dokumennya diisi contoh, bukan nomor rekam medis milik orang. */
    public function testIdentitasnyaContohJuga(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('00-12-3456', $halaman);
        self::assertStringContainsString('2026-000042', $halaman);
    }

    /**
     * PDF mentahnya bisa dilihat juga untuk contoh.
     *
     * Dilaporkan pemakai: "pdf raw nya gak bisa dilihat ya contoh?". Sebabnya
     * tautan mandiri halaman membawa `template_id=0` — dan halaman menjawab
     * nomor nol dengan pemilih template, bukan dengan dokumen.
     */
    public function testPdfMentahBisaDilihat(): void
    {
        // debug=html: jalur yang sama, berhenti tepat sebelum diserahkan ke
        // mesin PDF — yang memang disediakan aplikasi pemakai, bukan pustaka.
        $keluaran = self::halaman('example', 'ya', 'view=pdf&raw=1&debug=html');

        self::assertStringContainsString('<!DOCTYPE html>', $keluaran);
        self::assertStringContainsString('Siti Rahmawati', $keluaran);
        self::assertStringContainsString('Konsultasi dokter umum', $keluaran, 'tabelnya ikut terisi di PDF');
        self::assertStringNotContainsString('field-placeholder', $keluaran);
        self::assertStringNotContainsString('{{', $keluaran);
    }

    /** Tautan mandiri halaman membawa nama contohnya, bukan nomor nol. */
    public function testTautanMandiriMembawaNamaContoh(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('template_id=example%3Asurat-keterangan-tindakan', $halaman, 'alamat halaman sendiri kehilangan nama contoh');
        self::assertStringContainsString('const templateId = "example:surat-keterangan-tindakan"', $halaman, 'sisi JS masih memakai nomor');
    }

    /**
     * QR-nya ikut tampil di contoh.
     *
     * Dilaporkan pemakai: "qr nya pun tidak tampil di contoh". Dua QR yang
     * berbeda urusan: yang di kaki surat datang dari field `link_verifikasi`
     * dan dirender di server, sedangkan QR tanda tangan menunjuk record yang —
     * di contoh — memang tidak ada. Yang kedua diberi alamat contoh supaya
     * bentuknya tetap terlihat.
     */
    public function testKeduaQrTampilDiContoh(): void
    {
        $halaman = self::halaman();

        self::assertSame(
            1,
            preg_match('/<img src="data:image\/[^"]+" class="qr-img"/', $halaman),
            'QR verifikasi di kaki surat tidak dirender'
        );
        self::assertStringContainsString('window.EZDOC_EXAMPLE_VERIFY = "https:', $halaman, 'contoh tidak punya alamat verifikasi contoh');

        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');
        self::assertStringContainsString(
            "if (!qrData && window.EZDOC_EXAMPLE_VERIFY) {",
            $skrip,
            'QR tanda tangan tidak punya isi cadangan untuk mode contoh'
        );
    }

    /** Nama contoh yang tidak ada tidak menghasilkan halaman apa pun. */
    public function testNamaContohTidakDikenalTidakMenampilkanDokumen(): void
    {
        $halaman = self::halaman('example:tidak-ada');

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
            "'/^example(?::([a-z0-9-]+))?$/'",
            self::sumberGenerate(),
            'pengenal contoh harus terikat awal-akhir'
        );
    }

    /** Contohnya dibaca dari berkas — bukan dari baris template. */
    public function testTemplatenyaDariBerkas(): void
    {
        $sumber = self::sumberGenerate();

        self::assertStringContainsString('\Ezdoc\Template\Example::load($exampleSlug', $sumber, 'templatenya harus datang dari pemuat berkas');
        self::assertStringContainsString("\$GLOBALS['ezdocModeContoh'] = true;", $sumber);
        self::assertStringContainsString("'/\\s+data-(require-otp|otp-phone-source|otp-ttl)=\"[^\"]*\"/", $sumber, 'OTP harus dilucuti di mode contoh');
    }
}
