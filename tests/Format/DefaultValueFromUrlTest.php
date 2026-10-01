<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Format;

use PHPUnit\Framework\TestCase;

/**
 * Nilai bawaan field boleh menyebut satu parameter URL sebagai sumbernya.
 *
 * Pemakai mengisi nama penanda tangan di beberapa slot lewat satu parameter
 * URL. Cara lamanya membuat slot-slot itu berbagi satu `nama_field` — dan itu
 * justru melemahkan tanda tangannya: {@see \Ezdoc\Signature\SignaturePayload::coveredKeysFor()}
 * mengeluarkan nama milik slot LAIN dari cakupan sebuah tanda tangan, jadi
 * nama yang dipakai berdua keluar dari cakupan keduanya, dan yang tercetak di
 * bawah tanda tangan tidak lagi dilindungi tanda tangan itu.
 *
 * `get:` memberi jalan keluar yang tidak menukar apa pun: tiap slot punya
 * field sendiri, terisi dari satu sumber.
 *
 * Tidak ada pintu baru yang dibuka. Tiap field memang sudah bisa diisi lewat
 * URL dengan namanya sendiri (lihat `v()` di halaman pembuatan dokumen); yang
 * ditambahkan hanya kemampuan menyebut sumber itu dengan nama lain.
 */
final class DefaultValueFromUrlTest extends TestCase
{
    /** @var array<string,mixed> */
    private $getAsli = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/view_helpers.php';
        require_once dirname(__DIR__, 2) . '/lib/doc_template_helpers.php';
        $this->getAsli = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getAsli;
    }

    /** Nilainya diambil dari parameter yang disebut. */
    public function testMengambilDariParameterYangDisebut(): void
    {
        $_GET = ['nama_petugas' => 'Bidan Ratna'];

        self::assertSame('Bidan Ratna', resolveDefault('get:nama_petugas'));
    }

    /**
     * Dua field boleh menyebut satu parameter yang sama.
     *
     * Inilah gunanya: dua slot tanda tangan, dua field berbeda, satu sumber.
     */
    public function testDuaFieldBolehSatuSumber(): void
    {
        $_GET = ['nama_petugas' => 'dr. Andi'];

        self::assertSame('dr. Andi', resolveDefault('get:nama_petugas'));
        self::assertSame('dr. Andi', resolveDefault('get:nama_petugas'));
    }

    /** Parameter yang tidak ada menghasilkan kosong, bukan galat. */
    public function testParameterYangTidakAdaKosong(): void
    {
        $_GET = [];

        self::assertSame('', resolveDefault('get:nama_petugas'));
    }

    /**
     * Nama parameter yang aneh ditolak.
     *
     * Nilainya masuk ke dokumen dan tersimpan bersamanya. Yang dibaca harus
     * satu parameter bernama jelas.
     *
     * @dataProvider namaAneh
     */
    public function testNamaParameterYangAnehDitolak(string $nama): void
    {
        $_GET = [$nama => 'apa pun'];

        self::assertSame('', resolveDefault('get:' . $nama));
    }

    /** @return array<string,array{0:string}> */
    public static function namaAneh(): array
    {
        return [
            'tanda hubung' => ['nama-petugas'],
            'titik' => ['nama.petugas'],
            'kurung siku' => ['nama[0]'],
            'spasi' => ['nama petugas'],
            'kosong' => [''],
            'mulai angka' => ['1nama'],
        ];
    }

    /**
     * Nilai yang bukan teks ditolak.
     *
     * `?nama[]=a&nama[]=b` membuat PHP menyerahkan larik. Yang masuk dokumen
     * harus teks; larik yang dipaksa jadi teks mencetak "Array".
     */
    public function testNilaiBerupaLarikDitolak(): void
    {
        $_GET = ['nama_petugas' => ['a', 'b']];

        self::assertSame('', resolveDefault('get:nama_petugas'));
    }

    /** Sumber lain dan teks biasa tetap seperti sebelumnya. */
    public function testSumberLamaTidakBerubah(): void
    {
        $_GET = ['x' => 'y'];

        self::assertSame('dr. Andi Pratama', resolveDefault('dr. Andi Pratama'));
        self::assertSame('', resolveDefault(''));
        self::assertNotSame('', resolveDefault('date:Y'), 'sumber tanggal ikut rusak');
    }
}
