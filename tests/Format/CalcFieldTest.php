<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Format;

use Ezdoc\Format\CalcField;
use PHPUnit\Framework\TestCase;

/**
 * Field yang nilainya dihitung dari field lain.
 *
 * Diminta pemakai untuk skor Bishop dan Flamm & Geiger: "aku lebih suka [fitur
 * hitung], sekaligus agar ada fitur baru yang bisa direuse, pastikan
 * kompatibel berbagai bentuk, dan bisa digunakan lebih dari 1".
 *
 * Yang diuji di sini keputusannya, bukan aritmetikanya — aritmetika sudah
 * punya ujinya sendiri di FormulaEvaluator. Keputusan yang mahal kalau salah:
 * apa yang terjadi pada isian yang belum diisi.
 */
final class CalcFieldTest extends TestCase
{
    public function testMenjumlahkanBeberapaField(): void
    {
        self::assertSame('7', CalcField::hitung('a + b + c', ['a' => '3', 'b' => '2', 'c' => '2']));
    }

    /** Bahasanya sama dengan rumus tabel: kurung, kali, bagi, kurang. */
    public function testBentukRumusLain(): void
    {
        self::assertSame('10', CalcField::hitung('(a + b) * 2', ['a' => '3', 'b' => '2']));
        self::assertSame('1.5', CalcField::hitung('a / b', ['a' => '3', 'b' => '2'], 1));
        self::assertSame('1', CalcField::hitung('a - b', ['a' => '3', 'b' => '2']));
        // Nama boleh ditulis berkurung seperti di rumus tabel.
        self::assertSame('5', CalcField::hitung('{a} + {b}', ['a' => '3', 'b' => '2']));
    }

    /**
     * Yang belum diisi dihitung nol.
     *
     * Formulir skor diisi bertahap. Kalau satu butir kosong mengosongkan
     * totalnya, angkanya baru muncul di isian terakhir — dan sampai saat itu
     * orang tidak tahu rumusnya bekerja atau tidak.
     */
    public function testYangKosongDihitungNol(): void
    {
        self::assertSame('5', CalcField::hitung('a + b + c', ['a' => '3', 'b' => '2']));
        self::assertSame('3', CalcField::hitung('a + b', ['a' => '3', 'b' => 'bukan angka']));
    }

    /**
     * Tapi kalau SEMUA kosong, hasilnya kosong.
     *
     * Formulir yang belum disentuh siapa pun tidak boleh tercetak dengan "0"
     * di kolom skor: nol itu keterangan medis, dan tidak ada yang menulisnya.
     */
    public function testSemuaKosongBerartiKosong(): void
    {
        self::assertNull(CalcField::hitung('a + b + c', []));
        self::assertNull(CalcField::hitung('a + b', ['a' => '', 'b' => '   ']));
    }

    /** Kotak centang bernilai satu, baik tersimpan sebagai '1' maupun 'Ya'. */
    public function testKotakCentangBernilaiSatu(): void
    {
        self::assertSame('2', CalcField::hitung('x + y', ['x' => '1', 'y' => 'Ya']));
        self::assertSame('1', CalcField::hitung('x + y', ['x' => 'true', 'y' => '']));
        self::assertSame('1', CalcField::hitung('x + y', ['x' => true, 'y' => false]));
    }

    /** Desimal bisa diatur, dan dibatasi supaya tidak jadi angka tak terbaca. */
    public function testDesimalDiatur(): void
    {
        self::assertSame('0.33', CalcField::hitung('a / b', ['a' => '1', 'b' => '3'], 2));
        self::assertSame('0', CalcField::hitung('a / b', ['a' => '1', 'b' => '3']));
        self::assertSame('0.333333', CalcField::hitung('a / b', ['a' => '1', 'b' => '3'], 99));
    }

    /**
     * Rumus yang salah tulis tidak menumpahkan galat ke dokumen.
     *
     * Dokumen yang dicetak tidak boleh memuat keluhan program. Yang menulis
     * rumusnya melihat kesalahannya di designer, bukan pasien di kertasnya.
     */
    public function testRumusSalahTulisTidakMelempar(): void
    {
        self::assertNull(CalcField::hitung('a + + *', ['a' => '3']));
        self::assertNull(CalcField::hitung('', ['a' => '3']));
        self::assertNull(CalcField::hitung('123 + 4', ['a' => '3']), 'tanpa rujukan field, tidak ada yang dihitung');
    }

    /** Pembagian nol tidak menghasilkan angka, dan tidak melempar. */
    public function testPembagianNol(): void
    {
        self::assertNull(CalcField::hitung('a / b', ['a' => '3', 'b' => '0']));
    }

    /** Daftar rujukan dipakai sisi peramban untuk tahu isian mana yang diawasi. */
    public function testDaftarRujukan(): void
    {
        self::assertSame(['a', 'b', 'c'], CalcField::rujukan('a + b + (c * 2)'));
        self::assertSame(['a', 'b'], CalcField::rujukan('{a} + {b} + {a}'));
        self::assertSame([], CalcField::rujukan('1 + 2'));
    }
}
