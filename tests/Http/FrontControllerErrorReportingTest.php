<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Pintu depan `public/index.php` tidak boleh mati tanpa suara.
 *
 * Ini lahir dari kejadian nyata: halaman menjadi HTTP 500 kosong, dan
 * mendiagnosisnya berubah jadi menebak dari jauh — memeriksa peta autoload,
 * menjalankan migrasi di basis data lain, membaca ulang berkas bahasa — semua
 * karena satu kalimat kegagalan tidak sampai ke siapa pun.
 *
 * Dua sifat yang dijaga di sini, dan keduanya harus benar bersamaan:
 *
 *   1. tanpa penanda `public/.debug`, layar TIDAK memuat pesan kegagalan
 *   2. dengan penanda itu, pesannya muncul lengkap dengan berkas dan barisnya
 *
 * Yang pertama menjaga pengunjung tidak melihat path server dan isi query;
 * yang kedua menjaga operatornya tidak buta. Menghapus salah satunya membuat
 * yang lain berbahaya atau tidak berguna.
 *
 * Diuji dengan menjalankan potongan berkasnya sungguhan di proses terpisah,
 * bukan dengan mencocokkan teksnya. Penangkap error tidak bisa dibuktikan
 * bekerja tanpa ada yang benar-benar dilempar.
 */
final class FrontControllerErrorReportingTest extends TestCase
{
    private string $publicDir = '';
    private string $probe = '';
    private string $marker = '';

    protected function setUp(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() dimatikan di lingkungan ini.');
        }

        $this->publicDir = dirname(__DIR__, 2) . '/public';
        $this->probe = $this->publicDir . '/_test_error_probe.php';
        $this->marker = $this->publicDir . '/.debug';

        $src = (string) file_get_contents($this->publicDir . '/index.php');
        $cut = strpos($src, 'Autoload resolution');
        self::assertNotFalse($cut, 'penanda blok autoload hilang dari index.php');

        // Hanya bagian penangkapnya yang dijalankan — sisanya butuh basis data.
        file_put_contents(
            $this->probe,
            substr($src, 0, $cut - 8) . "\nthrow new \\RuntimeException('ledakan-uji');\n"
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->probe, $this->marker] as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function jalankan(): string
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->probe) . ' 2>&1', $out);

        return implode("\n", $out);
    }

    public function testWithoutTheMarkerNothingLeaksToTheScreen(): void
    {
        $output = $this->jalankan();

        self::assertStringNotContainsString('ledakan-uji', $output);
        self::assertStringNotContainsString($this->publicDir, $output);
    }

    public function testWithTheMarkerTheRealCauseIsShown(): void
    {
        file_put_contents($this->marker, '');

        $output = $this->jalankan();

        self::assertStringContainsString('ledakan-uji', $output);
        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('_test_error_probe.php', $output, 'berkas asalnya harus disebut');
    }

    /**
     * Pesannya harus mengingatkan agar penandanya dihapus lagi.
     *
     * Penanda diagnosa yang tertinggal menyala adalah cara biasa sebuah sistem
     * mulai membocorkan bagian dalamnya — bukan karena ada yang memutuskan
     * begitu, tapi karena tidak ada yang mengingatkan.
     */
    public function testTheOutputRemindsToRemoveTheMarker(): void
    {
        file_put_contents($this->marker, '');

        self::assertStringContainsString('.debug', $this->jalankan());
    }
}
