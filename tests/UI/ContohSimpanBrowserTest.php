<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Mode contoh boleh dicoba — simpannya ke peramban, bukan ke server.
 *
 * Diminta pemakai: "tombolnya kok read only semua, aku ingin bisa aksi… save
 * sementara aja atau di localstorage browser jika takut db terganggu".
 *
 * Yang dijaga di sini dua hal yang saling menopang: simpannya BENAR-BENAR
 * dibatalkan sebelum menyentuh server, dan yang dibatalkan itu tidak hilang —
 * ia mendarat di peramban dan kembali saat halaman dimuat ulang. Kalau hanya
 * yang pertama yang dijaga, contohnya jadi halaman yang mengundang diisi lalu
 * membuang isiannya diam-diam.
 *
 * Skripnya diambil dari halaman yang SUDAH dirender, lalu dijalankan di atas
 * DOM tiruan — bukan dibaca sebagai teks.
 */
final class ContohSimpanBrowserTest extends TestCase
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

    private static function halaman(): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_contoh_generate.php'),
            escapeshellarg('contoh')
        ), $baris);

        return implode("\n", $baris);
    }

    /** Skrip mode contoh, sebagaimana ia sampai ke peramban. */
    private static function skrip(): string
    {
        $halaman = self::halaman();
        self::assertSame(
            1,
            preg_match('/<script>\s*\(function \(\) \{([\s\S]*?ezdoc_contoh_[\s\S]*?)\}\)\(\);\s*<\/script>/', $halaman, $m),
            'skrip mode contoh tidak ada di halaman'
        );

        return '(function () {' . $m[1] . '})();';
    }

    /** Dijalankan, bukan dibaca: simpan dibatalkan, isinya pulih saat dimuat ulang. */
    public function testSimpanMendaratDiPeramban(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-contoh-') . '.js';
        file_put_contents($js, self::skrip());

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/contoh-simpan-browser.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }

    /**
     * Pembatalannya lewat kait resmi halaman ini.
     *
     * `ezcetak:before-save` memang disediakan untuk dibatalkan konsumen, jadi
     * tombol, validasi, dan umpan baliknya tetap yang asli. Jalur simpan
     * tiruan akan menyimpang dari yang asli tanpa ada yang tahu kapan.
     */
    public function testMemakaiKaitResmiBukanJalurTiruan(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertStringContainsString("window.addEventListener('ezcetak:before-save'", $sumber);
        self::assertStringContainsString('e.preventDefault();', $sumber);
        self::assertStringNotContainsString("window.submitForm =", $sumber, 'jangan menimpa fungsi simpan halaman');
    }

    /** OTP dimatikan di contoh: kodenya menuntut dokumen sungguhan. */
    public function testOtpDimatikanDiContoh(): void
    {
        $halaman = self::halaman();

        self::assertStringNotContainsString('data-require-otp', $halaman, 'OTP tidak bisa berjalan tanpa dokumen');
        self::assertStringContainsString('OTP tidak dikirim', $halaman, 'orangnya harus diberi tahu');

        // Atributnya tetap ada di berkas contohnya — yang dimatikan hanya di
        // halaman ini, supaya aturannya tetap bisa dibaca dan disalin.
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/contoh/surat-keterangan-tindakan/template.html');
        self::assertStringContainsString('data-require-otp="1"', $template);
    }
}
