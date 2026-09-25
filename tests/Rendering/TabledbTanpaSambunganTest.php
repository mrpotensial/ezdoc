<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Tabel dari kueri saat tidak ada sambungan database.
 *
 * Jawabannya harus "tidak ada baris", bukan halaman mati. Dulu ini bersandar
 * pada `@mysqli_prepare()` yang mengembalikan false untuk sambungan kosong;
 * sejak PHP 8 argumen bertipe salah melempar TypeError, dan tanda `@` tidak
 * menahan lemparan. Akibatnya setiap pemanggil tanpa sambungan — pratinjau,
 * perakitan di luar permintaan HTTP — mati total, dan matinya di tengah
 * perakitan dokumen.
 *
 * Ditemukan saat merender halaman contoh konversi, yang memang sengaja tidak
 * menyentuh database.
 */
final class TabledbTanpaSambunganTest extends TestCase
{
    public function testTanpaSambunganTidakMelempar(): void
    {
        $hasil = executeDynQuery('SELECT a FROM b WHERE c = {norm}', '123', '456', [], null);

        self::assertSame([], $hasil);
    }

    /** Nilai bukan-sambungan lain pun tidak boleh melempar. */
    public function testNilaiBukanSambunganIkutDitolak(): void
    {
        foreach ([null, false, 0, '', 'mysqli', new \stdClass()] as $bukan) {
            self::assertSame([], executeDynQuery('SELECT 1', '', '', [], $bukan));
        }
    }

    /**
     * Baris bertanda tabledb tetap terproses — hasilnya kosong, bukan penanda
     * mentah yang ikut tercetak ke dokumen.
     */
    public function testBarisTabelHabisDiprosesMeskiKosong(): void
    {
        $html = '<table><tbody><tr><td>{{tabledb.x.kolom}}</td></tr></tbody></table>';
        $keluar = processTabledbRows($html, ['x' => ['sql' => 'SELECT kolom FROM t', 'columns' => ['kolom']]], '', '', [], null);

        self::assertStringNotContainsString('{{tabledb', $keluar);
    }
}
