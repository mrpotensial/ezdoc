<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Versi baru dimulai sebagai salinan, bukan sebagai halaman kosong.
 *
 * Sebelum ini pilihan pertama di dialognya adalah "Blank (start from scratch)"
 * dan ia yang tercentang. Akibatnya terukur di basis data pemakai: dari 152
 * versi lanjutan, **51 lahir kosong** — sepertiganya. Tiga di antaranya dibuat
 * dalam 65 detik oleh satu orang yang jelas sedang mengklik, bukan memilih.
 * Satu slot sampai v29.
 *
 * Dilaporkan pemakai sebagai "user suka asal klik buat versi". Yang diklik
 * memang tombolnya, tapi yang membuat hasilnya kosong adalah bawaan dialognya.
 * Bawaan yang merusak bukan bawaan.
 */
final class VersiBaruTidakKosongTest extends TestCase
{
    private static function skrip(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');
    }

    /** Yang tercentang saat dialognya terbuka adalah "salin". */
    public function testYangTercentangAdalahSalin(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/<input type="radio" name="newVerSource" value="copy" checked>/', $skrip),
            'pilihan salin tidak tercentang saat dialognya terbuka'
        );
        self::assertSame(
            0,
            preg_match('/<input type="radio" name="newVerSource" value="blank" checked>/', $skrip),
            'pilihan kosong masih jadi bawaan'
        );
    }

    /**
     * Dan kalau tidak ada yang tercentang sama sekali, jatuhnya tetap "salin".
     *
     * Fungsi pembuatnya bisa dipanggil dari jalur lain. Jalur yang tidak
     * memilih apa pun tidak sedang meminta dokumen kosong.
     */
    public function testJatuhnyaJugaKeSalin(): void
    {
        self::assertSame(
            1,
            preg_match("/newVerSource\"\\]:checked'\\)\\?\\.value \\|\\| 'copy'/", self::skrip()),
            'tanpa pilihan, hasilnya masih dokumen kosong'
        );
    }

    /**
     * Versi yang sedang dibuka yang ditawarkan untuk disalin.
     *
     * Kecuali saat dialognya dipanggil untuk slot LAIN — form baru yang
     * menabrak dokumen orang lain. Di sana nomor versi halaman ini
     * menggambarkan form yang sedang diisi, bukan slot yang ditabrak, dan
     * kebetulan nomornya sama akan menyalin versi yang salah.
     */
    public function testVersiYangSedangDibukaYangDitawarkan(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const verHalaman = slot \? 0 : CURRENT_VERSION;/', $skrip),
            'slot lain masih memakai nomor versi halaman ini'
        );
        self::assertSame(
            1,
            preg_match('/const dasar = versions\.some\(v => v\.version === verHalaman\)/', $skrip),
            'yang ditawarkan bukan versi yang sedang dibuka'
        );
        self::assertSame(
            1,
            preg_match('/: \(versions\.length \? versions\[0\]\.version : 0\);/', $skrip),
            'tanpa kecocokan, yang ditawarkan bukan versi terbaru'
        );
        self::assertStringContainsString(
            "v.version === dasar ? ' selected' : ''",
            $skrip,
            'pilihannya tidak ikut terpilih di dropdown salin'
        );
    }

    /**
     * Judul dan tombolnya menyebut nomor yang akan dibuat.
     *
     * "Create New Version" tidak memberi tahu apa pun tentang hasilnya.
     * "Buat v3" memberi tahu — dan itu satu-satunya kalimat yang sempat dibaca
     * orang yang sedang buru-buru.
     */
    public function testTombolnyaMenyebutNomorYangAkanDibuat(): void
    {
        $skrip = self::skrip();

        self::assertStringContainsString("modal.new_version.confirm_n', {n: berikut}", $skrip, 'tombolnya tidak menyebut nomor versinya');
        self::assertStringContainsString("modal.new_version.title_n', {n: berikut}", $skrip, 'judulnya tidak menyebut nomor versinya');
        self::assertSame(
            1,
            preg_match('/const berikut = versions\.reduce\(/', $skrip),
            'nomor berikutnya tidak dihitung dari versi yang ada'
        );
    }

    /**
     * Memilih "kosong" memberi peringatan, dan peringatan itu tidak selalu ada.
     *
     * Peringatan yang muncul terus-menerus dibaca sebagai hiasan.
     */
    public function testPeringatanKosongMunculSaatDipilihSaja(): void
    {
        $skrip = self::skrip();

        self::assertStringContainsString('id="newVerBlankWarn"', $skrip, 'tidak ada peringatan untuk pilihan kosong');
        self::assertSame(
            1,
            preg_match('/id="newVerBlankWarn" style="display:none"/', $skrip),
            'peringatannya sudah tampil sebelum ada yang memilih kosong'
        );
        self::assertSame(
            1,
            preg_match("/r\\.value === 'blank' && r\\.checked/", $skrip),
            'peringatannya tidak mengikuti pilihan'
        );
    }

    /** Kalimatnya ada di kedua bahasa, dan menyebut akibatnya. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            foreach (['title_n', 'description_n', 'confirm_n', 'blank_warning'] as $kunci) {
                self::assertArrayHasKey($kunci, $lang['modal']['new_version'], $bahasa . ': ' . $kunci);
            }

            // Kalimat pilihan kosongnya tidak lagi terdengar seperti pilihan biasa.
            self::assertStringNotContainsStringIgnoringCase(
                'start from scratch',
                (string) $lang['modal']['new_version']['source_blank'],
                $bahasa . ': pilihan kosong masih terdengar netral'
            );
        }
    }
}
