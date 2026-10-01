<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Cara menandatangani dipilih sesudah slotnya diklik.
 *
 * Diminta pemakai: "jika mode ttd qr/image aktif, tab ganti mode nya jangan di
 * dokumen, tapi jadi pilihan setelah klik ttd nya, baru muncul modal ttdnya".
 *
 * Dua hal yang salah dari tab lama: ia mengubah tampilan DOKUMEN padahal bukan
 * bagian dokumen, dan orang menekannya sebelum tahu untuk apa. Sekarang ia
 * langkah pertama di dalam modal tanda tangan — pola yang sama dipakai DocuSign
 * dan Adobe Sign.
 *
 * Yang dijaga di sini: tab itu benar-benar hilang dari dokumen, pilihannya ada
 * di modal, dan perpindahan modenya tetap lewat satu jalur yang sama
 * (`switchTtdMode`) supaya keadaan di dokumen dan nilai tersimpan tidak pernah
 * berbeda pendapat.
 */
final class CaraTandaTanganTest extends TestCase
{
    private static function halaman(): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_generate.php'),
            escapeshellarg('example')
        ), $baris);

        return implode("\n", $baris);
    }

    private static function skripGenerate(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');
    }

    /** Tidak ada lagi tab pemilih mode yang menempel di dokumen. */
    public function testTidakAdaTabModeDiDokumen(): void
    {
        $halaman = self::halaman();

        self::assertSame(0, substr_count($halaman, '<div class="ttd-mode-toggle">'), 'tab mode masih tertanam di dokumen');
        self::assertSame(0, substr_count($halaman, 'class="ttd-mode-btn'), 'tombol tab mode masih dirender');

        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');
        self::assertStringNotContainsString('ttd-mode-btn \' . ($activeMode', $sumber, 'pembuat tab mode belum dicabut');
    }

    /** Pilihannya ada di modal tanda tangan, dan tertutup sampai dibutuhkan. */
    public function testPilihanCaraAdaDiModal(): void
    {
        $halaman = self::halaman();

        self::assertSame(
            1,
            preg_match('/<div id="signModePilih" class="hidden[^"]*"/', $halaman),
            'langkah pilih cara tidak ada, atau tidak tertutup di awal'
        );
        self::assertStringContainsString("onclick=\"pilihCaraTtd('image')\"", $halaman);
        self::assertStringContainsString("onclick=\"pilihCaraTtd('qr')\"", $halaman);
        self::assertSame(
            1,
            preg_match('/id="btnGantiCaraTtd"[^>]*onclick="gantiCaraTtd\(\)"/', str_replace("\n", ' ', $halaman))
                + preg_match('/onclick="gantiCaraTtd\(\)"[^>]*id="btnGantiCaraTtd"/', str_replace("\n", ' ', $halaman)),
            'tidak ada cara berubah pikiran sebelum menandatangani'
        );
    }

    /**
     * Ditanya hanya kalau memang ada dua cara, dan hanya selagi belum
     * ditandatangani — yang sudah terjadi tidak bisa dipilih ulang.
     */
    public function testDitanyaHanyaSaatAdaDuaCaraDanBelumDitandatangani(): void
    {
        $skrip = self::skripGenerate();

        self::assertStringContainsString("_modes.indexOf('image') !== -1 && _modes.indexOf('qr') !== -1", $skrip);
        self::assertStringContainsString("const _sudahTtd = !!(document.getElementById('ttd_' + id)?.value || '').trim();", $skrip);
        self::assertSame(
            1,
            preg_match('/if \(_duaCara && !_sudahTtd && _caraTtdDipilih !== id\) \{[\s\S]{0,700}?return;\s*\}/', $skrip),
            'cabang pilih cara harus berhenti sebelum menyiapkan kanvas'
        );
    }

    /**
     * Memilih cara memakai jalur perpindahan mode yang sudah ada.
     *
     * Kalau pilihannya menulis sendiri ke input tersembunyi, keadaan di dokumen
     * (area gambar vs area QR) tidak ikut berpindah — dan yang terlihat berbeda
     * dari yang tersimpan tanpa ada yang tahu.
     */
    public function testPilihanMemakaiJalurPerpindahanYangSama(): void
    {
        $skrip = self::skripGenerate();

        self::assertSame(
            1,
            preg_match('/function pilihCaraTtd\(mode\) \{[\s\S]{0,400}?switchTtdMode\(currentTtdId, mode\);[\s\S]{0,400}?openSign\(/', $skrip),
            'pilihan cara harus lewat switchTtdMode lalu membuka ulang modalnya'
        );
    }

    /** Menutup modal mengembalikan pertanyaannya: menandatangani lagi = memilih lagi. */
    public function testMenutupModalMengulangPertanyaan(): void
    {
        $skrip = self::skripGenerate();

        self::assertSame(
            1,
            preg_match('/function closeSign\(\) \{[\s\S]{0,600}?_caraTtdDipilih = null;/', $skrip),
            'pilihan cara harus dilupakan saat modal ditutup'
        );
    }

    /** Kalimat-kalimatnya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $kamus = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            self::assertArrayHasKey('sign_mode', $kamus['modal'], 'kamus ' . $bahasa . ' tidak punya kalimat pilih cara');
            foreach (['title', 'desc', 'image_title', 'image_desc', 'qr_title', 'qr_desc', 'change'] as $kunci) {
                self::assertArrayHasKey($kunci, $kamus['modal']['sign_mode'], $bahasa . ': ' . $kunci);
                self::assertNotSame('', trim((string) $kamus['modal']['sign_mode'][$kunci]));
            }
        }
    }
}
