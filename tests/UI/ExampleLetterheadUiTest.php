<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Kop contoh diatur dari halaman contoh, bukan dari berkas config.
 *
 * Diminta pemakai: "maksudku konfigurasi di halaman example, karena tiap
 * consumer kan beda beda". Nilai di berkas mount tetap jadi titik awal, dan
 * yang diketik di halaman itu menimpanya.
 *
 * Yang dijaga di sini bukan jawaban permintaannya, melainkan apa yang jadi di
 * dokumen sesudahnya — itu yang dilihat orang, dan itu yang ikut tersimpan ke
 * tiap template yang dibuat dari contoh.
 */
final class ExampleLetterheadUiTest extends TestCase
{
    /** @var list<string> */
    private static $bersihkan = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$bersihkan as $dir) {
            foreach ((array) glob($dir . '/*') as $berkas) {
                @unlink((string) $berkas);
            }
            @rmdir($dir);
        }
        self::$bersihkan = [];
    }

    private static function tempat(): string
    {
        $dir = sys_get_temp_dir() . '/ezdoc-kop-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        self::$bersihkan[] = $dir;

        return $dir;
    }

    /**
     * @return array{jawaban: array<string,mixed>, tersimpan: array<string,mixed>, dokumen: array<string,mixed>, audit: list<array<string,mixed>>}
     */
    private static function jalankan(string $skenario, string $tempat, string $logo = ''): array
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_letterhead_save.php'),
            escapeshellarg($skenario),
            escapeshellarg($tempat),
            escapeshellarg($logo)
        ), $baris);
        $keluaran = implode("\n", $baris);

        $bagian = explode("\n---\n", $keluaran, 2);
        self::assertCount(2, $bagian, 'fixture tidak mencetak keadaan sesudahnya: ' . $keluaran);

        $jawaban = json_decode(trim($bagian[0]), true);
        $sesudah = json_decode(trim($bagian[1]), true);
        self::assertIsArray($jawaban, 'jawaban bukan JSON: ' . $bagian[0]);
        self::assertIsArray($sesudah, 'keadaan bukan JSON: ' . $bagian[1]);

        return [
            'jawaban' => $jawaban,
            'tersimpan' => (array) $sesudah['tersimpan'],
            'dokumen' => (array) $sesudah['di_dokumen'],
            'audit' => (array) $sesudah['audit'],
        ];
    }

    // ── Yang terjadi sesudah disimpan ────────────────────────────────────

    /** Yang diketik di halaman itu benar-benar jadi kop di dokumennya. */
    public function testKopTersimpanMunculDiDokumen(): void
    {
        $h = self::jalankan('simpan', self::tempat());

        self::assertTrue($h['jawaban']['success'] ?? false, json_encode($h['jawaban']));
        self::assertSame('RUMAH SAKIT UJI COBA', $h['dokumen']['nama']);
        self::assertSame('Kota Uji', $h['dokumen']['kota']);

        // Satu baris teks jadi satu baris kop; baris kosong tidak ikut.
        self::assertSame('Jalan Uji No. 1<br>Telp (021) 000-0000', $h['dokumen']['alamat']);

        self::assertSame('contoh.letterhead_saved', $h['audit'][0]['event_type'] ?? null);
    }

    /** Dikembalikan ke bawaan: berkas contohnya lagi yang berlaku. */
    public function testDikembalikanKeBawaan(): void
    {
        $tempat = self::tempat();
        self::jalankan('simpan', $tempat);
        $h = self::jalankan('reset', $tempat);

        self::assertTrue($h['jawaban']['success'] ?? false);
        self::assertSame([], $h['tersimpan'], 'pengaturannya masih ada');
        self::assertSame('RUMAH SAKIT CONTOH SEJAHTERA', $h['dokumen']['nama']);
        self::assertSame('contoh.letterhead_reset', $h['audit'][0]['event_type'] ?? null);
    }

    /**
     * Kolom yang dikosongkan kembali ke bawaan, bukan jadi kosong.
     *
     * Kop tanpa nama bukan kop. Kalau kosong berarti kosong, satu kesalahan
     * ketik menghapus nama rumah sakit dari tiap dokumen yang dicetak
     * sesudahnya.
     */
    public function testKolomKosongKembaliKeBawaan(): void
    {
        $tempat = self::tempat();
        self::jalankan('simpan', $tempat);
        $h = self::jalankan('kosong', $tempat);

        self::assertSame([], $h['tersimpan']);
        self::assertSame('RUMAH SAKIT CONTOH SEJAHTERA', $h['dokumen']['nama']);
    }

    /** Logo yang sudah tersimpan tidak ikut hilang saat teksnya diubah. */
    public function testLogoBertahanSaatTeksDiubah(): void
    {
        $tempat = self::tempat();
        file_put_contents(
            $tempat . '/letterhead.json',
            (string) json_encode(['name' => 'RS LAMA', 'logo' => 'data:image/png;base64,iVBORw0KGgo='])
        );

        $h = self::jalankan('simpan', $tempat);

        self::assertSame('data:image/png;base64,iVBORw0KGgo=', $h['tersimpan']['logo'] ?? null, 'logo ikut hilang');
        self::assertSame('RUMAH SAKIT UJI COBA', $h['dokumen']['nama']);
    }

    /** Dan bisa dibuang kalau memang diminta. */
    public function testLogoBisaDibuang(): void
    {
        $tempat = self::tempat();
        file_put_contents(
            $tempat . '/letterhead.json',
            (string) json_encode(['name' => 'RS LAMA', 'logo' => 'data:image/png;base64,iVBORw0KGgo='])
        );

        $h = self::jalankan('hapus-logo', $tempat);

        self::assertArrayNotHasKey('logo', $h['tersimpan']);
    }

    // ── Kop yang sama di semua tempat contohnya dilihat ──────────────────

    /**
     * Kop yang diatur ikut ke halaman pembuatan dokumen dan ke designer.
     *
     * Kalau hanya halaman contoh yang memakainya, yang mengisi formulir atau
     * merapikan rancangan melihat kop yang berbeda dari yang tadi ia atur —
     * dan tidak punya alasan menduga keduanya dibaca dari tempat berbeda.
     */
    public function testKopIkutKeHalamanBuatDokumenDanDesigner(): void
    {
        $tempat = self::tempatBerkop();

        $buat = self::halamanBuat($tempat);
        self::assertStringContainsString('RUMAH SAKIT UJI KOP', $buat, 'halaman buat dokumen memakai kop lain');
        self::assertStringNotContainsString('RUMAH SAKIT CONTOH SEJAHTERA', $buat);

        $designer = self::halamanDesigner($tempat);
        self::assertStringContainsString('RUMAH SAKIT UJI KOP', $designer, 'designer memakai kop lain');
        self::assertStringNotContainsString('RUMAH SAKIT CONTOH SEJAHTERA', $designer);
    }

    /**
     * Dan ikut ke template yang disimpan dari contoh, isi maupun logonya.
     *
     * Ini yang paling mahal kalau salah: templatenya dipakai berbulan-bulan,
     * dan kop karangan di dalamnya baru terlihat setelah dokumennya dicetak.
     */
    public function testKopIkutKeTemplateYangDisimpan(): void
    {
        $tempat = self::tempatBerkop();

        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_save.php'),
            escapeshellarg('baru'),
            escapeshellarg('skor-bishop'),
            escapeshellarg($tempat)
        ), $baris);
        $keluaran = implode("\n", $baris);

        $bagian = explode("\n---\n", $keluaran, 2);
        self::assertCount(2, $bagian, $keluaran);
        $isi = json_decode(trim($bagian[1]), true);
        self::assertIsArray($isi);

        $template = $isi['templates'][0] ?? null;
        self::assertIsArray($template, 'tidak ada template tersimpan');

        self::assertStringContainsString('RUMAH SAKIT UJI KOP', (string) $template['content']);
        self::assertStringContainsString('Kota Kop', (string) $template['content']);
        self::assertStringNotContainsString('RUMAH SAKIT CONTOH SEJAHTERA', (string) $template['content']);

        // Logo contohnya dan logo yang diatur berbeda panjangnya; yang ikut
        // tersimpan harus yang diatur.
        self::assertGreaterThan(0, (int) $template['logo_kop_panjang'], 'template tersimpan tanpa logo');
        self::assertNotSame(
            self::logoContohPanjang(),
            (int) $template['logo_kop_panjang'],
            'yang tersimpan masih logo bawaan contohnya'
        );
    }

    /** Folder berisi kop tersimpan, lengkap dengan logonya. */
    private static function tempatBerkop(): string
    {
        $tempat = self::tempat();

        $gambar = imagecreatetruecolor(600, 600);
        imagefilledrectangle($gambar, 0, 0, 599, 599, imagecolorallocate($gambar, 200, 30, 30));
        ob_start();
        imagepng($gambar);
        $png = (string) ob_get_clean();
        imagedestroy($gambar);

        file_put_contents($tempat . '/letterhead.json', (string) json_encode([
            'name' => 'RUMAH SAKIT UJI KOP',
            'address' => ['Jalan Uji No. 9', 'Telp 000'],
            'city' => 'Kota Kop',
            'logo' => 'data:image/png;base64,' . base64_encode($png),
        ]));

        return $tempat;
    }

    /** Panjang logo bawaan contohnya, untuk dibandingkan. */
    private static function logoContohPanjang(): int
    {
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/examples/skor-bishop/example.json'),
            true
        );

        return strlen((string) ($data['config_header']['logos']['kop'] ?? ''));
    }

    private static function halamanBuat(string $tempat): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_generate.php'),
            escapeshellarg('example:skor-bishop'),
            escapeshellarg('ya'),
            escapeshellarg(''),
            escapeshellarg($tempat)
        ), $baris);

        return implode("\n", $baris);
    }

    private static function halamanDesigner(string $tempat): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_designer.php'),
            escapeshellarg('example:skor-bishop'),
            escapeshellarg($tempat)
        ), $baris);

        return implode("\n", $baris);
    }

    // ── Yang TIDAK boleh terjadi ─────────────────────────────────────────

    /** Yang tidak berhak merancang template tidak bisa mengubah kop. */
    public function testTanpaHakTidakBisaMengubahKop(): void
    {
        $h = self::jalankan('tanpa-hak', self::tempat());

        self::assertFalse($h['jawaban']['success'] ?? true);
        self::assertSame([], $h['tersimpan']);
        self::assertSame('authz.denied', $h['audit'][0]['event_type'] ?? null);
    }

    /**
     * Berkas yang bukan hasil unggahan ditolak.
     *
     * Tanpa penjaga itu, satu nama berkas yang dikirim orang berarti isi berkas
     * mana pun di server bisa dibaca dan ditanam sebagai logo.
     */
    public function testBerkasYangBukanUnggahanDitolak(): void
    {
        $tempat = self::tempat();
        $palsu = $tempat . '/bukan-unggahan.png';
        $gambar = imagecreatetruecolor(20, 20);
        imagepng($gambar, $palsu);
        imagedestroy($gambar);

        $h = self::jalankan('logo', $tempat, $palsu);

        self::assertFalse($h['jawaban']['success'] ?? true, 'berkas yang bukan unggahan diterima');
        self::assertSame([], $h['tersimpan']);
    }

    /** Pemasangan tanpa folder penyimpanan mengatakannya, bukan gagal diam-diam. */
    public function testTanpaFolderPenyimpananDikatakan(): void
    {
        $h = self::jalankan('tanpa-tempat', self::tempat());

        self::assertFalse($h['jawaban']['success'] ?? true);
        self::assertStringContainsString('storage', strtolower((string) $h['jawaban']['message']));
    }

    // ── Penjaga di berkas aksinya ────────────────────────────────────────

    /** Jalur unggahan memeriksa asal, ukuran, dan jenis berkasnya. */
    public function testJalurUnggahanBerpenjaga(): void
    {
        $isi = (string) file_get_contents(dirname(__DIR__, 2) . '/actions/template/save_example_letterhead.php');

        self::assertStringContainsString('is_uploaded_file(', $isi, 'asal berkasnya tidak diperiksa');
        self::assertStringContainsString('getimagesize(', $isi, 'jenis berkasnya tidak diperiksa');
        self::assertStringContainsString('5 * 1024 * 1024', $isi, 'ukurannya tidak dibatasi');
        self::assertStringContainsString('ezdoc_require_manage_templates(', $isi, 'tidak ada penjaga hak');

        // Pengaturan satu pemasangan, bukan data dokumen: tidak menyentuh tabel.
        foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'CREATE TABLE'] as $larangan) {
            self::assertStringNotContainsString($larangan, $isi, 'jalur kop menyentuh basis data');
        }
    }

    /** Terdaftar di dispatcher — kalau tidak, permintaannya jatuh ke halaman biasa. */
    public function testTerdaftarDiDispatcher(): void
    {
        self::assertStringContainsString(
            "'save_letterhead'",
            (string) file_get_contents(dirname(__DIR__, 2) . '/actions/_dispatcher.php')
        );
    }

    /** Panelnya muncul di halaman contoh, dan hanya kalau ada tempat menyimpan. */
    public function testPanelnyaMunculDiHalamanContoh(): void
    {
        $tempat = self::tempat();

        self::assertStringContainsString('id="ezKopSimpan"', self::halaman($tempat), 'panel pengaturan kop tidak ada');
        self::assertStringNotContainsString('id="ezKopSimpan"', self::halaman(''), 'panelnya ditawarkan padahal tidak ada tempat menyimpan');
    }

    /** Kalimat jawabannya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $common = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/common.php';
            foreach (['kop_saved', 'kop_reset', 'kop_save_failed', 'kop_no_storage', 'kop_logo_invalid', 'kop_logo_too_big'] as $kunci) {
                self::assertArrayHasKey($kunci, $common['response'], $bahasa . ': ' . $kunci);
            }
        }
    }

    private static function halaman(string $tempat): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example.php'),
            escapeshellarg('ya'),
            escapeshellarg('skor-bishop'),
            escapeshellarg($tempat)
        ), $baris);

        return implode("\n", $baris);
    }
}
