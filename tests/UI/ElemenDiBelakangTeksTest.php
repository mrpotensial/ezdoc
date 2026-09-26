<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Elemen "di belakang teks" harus terlihat di layar, bukan cuma di PDF.
 *
 * Dilaporkan pemakai: "kenapa di pdf raw ada stempel tapi di generate tidak
 * ada?". Cap, kop air, dan logo mode *behind* memakai `z-index: -1`. Angka itu
 * berarti "di belakang latar lapisan terdekat" — dan selama kertas `.page`
 * bukan lapisan tersendiri, lapisan terdekatnya adalah halaman, jadi capnya
 * jatuh di belakang latar putih `.page` dan hilang sama sekali.
 *
 * Di jalur PDF `.page` memang tanpa latar, jadi di sana ia tampak. Perbedaan
 * itulah masalahnya: yang dilihat petugas di layar bukan yang tercetak.
 *
 * Yang dijaga: kertas di layar adalah lapisan tersendiri, dan kedua perender
 * tetap memakai angka yang sama untuk "di belakang teks".
 */
final class ElemenDiBelakangTeksTest extends TestCase
{
    private static function baca(string $berkas): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $berkas);
    }

    /** Kertas di layar mengurung lapisannya sendiri. */
    public function testKertasDiLayarAdalahLapisanSendiri(): void
    {
        $gaya = self::baca('views/_partials/generate_styles.php');

        $awal = strpos($gaya, '        .page {');
        self::assertNotFalse($awal, 'aturan .page tidak ketemu');
        $aturan = substr($gaya, $awal, (int) strpos($gaya, '}', $awal) - $awal);

        self::assertStringContainsString('background-color: #fff;', $aturan, 'premis tes: kertasnya memang berlatar putih');
        self::assertStringContainsString('isolation: isolate;', $aturan, 'tanpa ini elemen di belakang teks hilang di layar');
    }

    /**
     * Kertas di designer juga lapisan tersendiri.
     *
     * Dilaporkan pemakai: "di designer kok tidak ada stempel". Sebab yang sama,
     * tempat berbeda: di designer kertasnya adalah `body` editor, dan latar
     * putihnya menempel di sana karena `html` sudah memakai latar abu-abu —
     * jadi latarnya tidak berpindah ke kanvas, dan elemen ber-z-index -1
     * jatuh di belakangnya.
     */
    public function testKertasDiDesignerJugaLapisanSendiri(): void
    {
        $skrip = self::baca('views/_partials/designer_scripts.php');

        $awal = strpos($skrip, '                body {');
        self::assertNotFalse($awal, 'aturan body editor tidak ketemu');
        $aturan = substr($skrip, $awal, (int) strpos($skrip, chr(10) . '                }', $awal) - $awal);

        self::assertStringContainsString('background-color: white;', $aturan, 'premis tes: kertasnya memang berlatar putih');
        self::assertStringContainsString('isolation: isolate;', $aturan, 'tanpa ini cap di belakang teks hilang di designer');
    }

    /** Kedua perender memakai angka yang sama untuk "di belakang teks". */
    public function testLayarDanPdfSepakatSoalAngkanya(): void
    {
        foreach (['views/_partials/generate_styles.php', 'lib/pdf_compose.php'] as $berkas) {
            $isi = self::baca($berkas);
            self::assertStringContainsString('.logo-behind { z-index: -1; }', $isi, $berkas);
        }
    }

    /**
     * Contohnya memang memakai elemen di belakang teks.
     *
     * Kalau tidak, perbaikan ini tidak punya satu pun tempat yang
     * memperlihatkannya, dan yang berikutnya akan mengira aturan itu mubazir.
     */
    public function testContohMemakaiElemenDiBelakangTeks(): void
    {
        $template = self::baca('contoh/surat-keterangan-tindakan/template.html');

        self::assertStringContainsString('logo-placeholder floating behind', $template);
    }
}
