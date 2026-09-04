<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Menandatangani tetap menyimpan walau tombol Simpan tidak dirender.
 *
 * Dilaporkan dari pemakaian, pada tanda tangan lewat OTP: tanda tangannya tampil
 * di layar, muat ulang memunculkan peringatan "belum tersimpan", dan memaksa
 * muat ulang menghilangkannya.
 *
 * Sebabnya perubahan sebelumnya di proyek ini sendiri. Dokumen yang sudah
 * ditandatangani kini menampilkan New Version di tempat Update, jadi
 * `.btn-success` tidak ada lagi di halaman — sementara `submitForm()` membuka
 * barisnya dengan `const origText = btn.textContent`. saveSign() memanggilnya,
 * TypeError-nya terjadi sebelum permintaan apa pun terkirim, dan tidak ada yang
 * kelihatan salah sampai halamannya dimuat ulang.
 *
 * Umpan balik di tombol itu hiasan; menyimpan adalah pekerjaannya. Yang hiasan
 * tidak boleh menjatuhkan yang pekerjaan — dan itulah yang dijaga di sini.
 *
 * Dijalankan, bukan dibaca. Kegagalannya berupa pengecualian saat dijalankan,
 * dan pemeriksaan atas teks sumbernya akan melewatkan bentuk lain dari kesalahan
 * yang sama.
 *
 * Dilewati kalau Node tidak ada, sama seperti InlineJavaScriptSyntaxTest:
 * pemeriksaan ini berharga di tempat ia bisa jalan, dan mesin tanpa Node tidak
 * boleh terhalang menjalankan sisanya.
 */
final class SubmitFormWithoutSaveButtonTest extends TestCase
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

    /** JS di dalam blok <script>-nya, dengan PHP diganti nilai netral. */
    private static function skrip(): string
    {
        $sumber = (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php'
        );

        $sumber = (string) preg_replace_callback(
            '/<\?php[\s\S]*?\?>/',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $sumber
        );

        preg_match(
            '/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/i',
            $sumber,
            $cocok
        );

        self::assertNotEmpty($cocok, 'blok <script> tidak ketemu — premis tes ini perlu ditinjau');

        return (string) preg_replace('/<\?=[\s\S]*?\?>/', '0', $cocok[1]);
    }

    public function testMenyimpanTetapTerkirimTanpaTombolSimpan(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-js-') . '.js';
        file_put_contents($js, self::skrip());

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/submit-without-save-button.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString(
            'LULUS',
            $keluaran,
            "submitForm() tidak mengirim apa pun tanpa tombol Simpan.\n"
            . "Tanda tangan akan tampil di layar lalu hilang pada muat ulang.\n\n"
            . $keluaran
        );
    }

    /**
     * Tombolnya diambil dengan aman, dan diubah lewat satu pintu.
     *
     * Pelengkap yang murah untuk tes di atas: kalau Node tidak ada, setidaknya
     * bentuk yang persis pernah gagal itu tidak bisa kembali diam-diam.
     */
    public function testTombolnyaTidakPernahDisentuhLangsung(): void
    {
        $js = self::skrip();

        self::assertStringContainsString(
            "const origText = btn ? btn.textContent : '';",
            $js,
            'tombolnya kembali dibaca tanpa penjagaan'
        );

        // Dibatasi ke badan submitForm(). Fungsi lain di berkas ini punya
        // variabel `btn` sendiri yang memang selalu ada, dan ikut menghitungnya
        // membuat penjaga ini merah karena kode yang tidak ada hubungannya.
        // Dicari MULAI dari submitForm(). Fungsi penanda-perubahan di atasnya
        // mengambil `.btn-success` juga — dan sudah menjaga null-nya sendiri —
        // jadi jangkar yang polos akan mengukur badan fungsi yang salah.
        $mulai = strpos($js, 'async function submitForm()');
        self::assertNotFalse($mulai, 'submitForm() hilang — premis tes ini perlu ditinjau');

        $awal = strpos($js, "const btn = document.querySelector('.btn-success');", $mulai);
        self::assertNotFalse($awal, 'pengambilan tombolnya hilang — premis tes ini perlu ditinjau');

        $akhir = preg_match(
            '/\R {8}(?:async )?function /',
            substr($js, $awal),
            $m,
            PREG_OFFSET_CAPTURE
        ) === 1 ? $awal + $m[0][1] : strlen($js);

        $badan = substr($js, $awal, $akhir - $awal);

        // Dua-duanya di dalam setTombol(), yang sudah menjaga null-nya.
        self::assertSame(
            2,
            preg_match_all('/btn\.(textContent|disabled)\s*=/', $badan),
            'ada yang mengubah tombolnya langsung, di luar setTombol()'
        );
    }
}
