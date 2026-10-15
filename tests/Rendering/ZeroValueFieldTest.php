<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Angka 0 adalah isi, bukan kosong.
 *
 * Dilaporkan 15 Okt 2026: field angka berisi 0 tersimpan, tapi sesudah halaman
 * dimuat ulang kotaknya kosong — dan penyimpanan berikutnya menyimpan kosong itu,
 * jadi nilainya hilang betulan. Penyebabnya `$nilai ?: ''`: di PHP teks "0"
 * dianggap salah. Di PDF hal yang sama mencetak "........" alih-alih 0.
 */
final class ZeroValueFieldTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function tipe(): array
    {
        return ['number' => ['number'], 'text' => ['text'], 'select' => ['select'], 'radio' => ['radio']];
    }

    /** @dataProvider tipe */
    public function testNolTercetakDiPdf(string $tipe): void
    {
        $keluar = renderFieldForPdf('jumlah', $tipe, '0', '');

        self::assertSame('<span class="f-pdf">0</span>', $keluar);
    }

    public function testNolSebagaiBilanganBulatJuga(): void
    {
        self::assertSame('<span class="f-pdf">0</span>', renderFieldForPdf('jumlah', 'number', 0, ''));
    }

    public function testKosongTetapTitikTitik(): void
    {
        self::assertSame('<span class="f-pdf">........</span>', renderFieldForPdf('jumlah', 'number', '', ''));
        self::assertSame('<span class="f-pdf">.....</span>', renderFieldForPdf('jam', 'time', '', ''));
    }

    /** Pola yang menyebabkannya tidak boleh kembali di jalur layar. */
    public function testPolaElvisTidakAdaDiIsianLayar(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertStringNotContainsString('($hVal ?: \'\')', $sumber);
        self::assertStringNotContainsString('($namaValue ?: \'\')', $sumber);
    }
}
