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
final class KopContohUiTest extends TestCase
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
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_simpan_kop.php'),
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
            $tempat . '/kop-contoh.json',
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
            $tempat . '/kop-contoh.json',
            (string) json_encode(['name' => 'RS LAMA', 'logo' => 'data:image/png;base64,iVBORw0KGgo='])
        );

        $h = self::jalankan('hapus-logo', $tempat);

        self::assertArrayNotHasKey('logo', $h['tersimpan']);
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
        $isi = (string) file_get_contents(dirname(__DIR__, 2) . '/actions/template/save_contoh_kop.php');

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
            "'save_kop'",
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
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_contoh.php'),
            escapeshellarg('ya'),
            escapeshellarg('skor-bishop'),
            escapeshellarg($tempat)
        ), $baris);

        return implode("\n", $baris);
    }
}
