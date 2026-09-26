<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Template;

use Ezdoc\Template\Contoh;
use PHPUnit\Framework\TestCase;

/**
 * Pemuat contoh: satu template yang hidup sebagai berkas.
 *
 * Nama foldernya datang dari query string (`template_id=contoh:<nama>`), jadi
 * ia masukan tidak tepercaya. Yang diuji di sini bukan cuma "bisa memuat",
 * melainkan bahwa nama yang aneh-aneh ditolak SEBELUM menyentuh berkas — dan
 * bahwa bentuk yang dikembalikan sama dengan baris template, karena itulah
 * yang membuat halaman pembuatan dokumen tidak perlu tahu contohnya dari mana.
 */
final class ContohLoaderTest extends TestCase
{
    public function testMuatContohBawaan(): void
    {
        $contoh = Contoh::muat();

        self::assertIsArray($contoh);
        self::assertSame(Contoh::BAWAAN, $contoh['slug']);
        self::assertNotSame('', (string) $contoh['nama_template']);
        self::assertStringContainsString('ttd-placeholder', (string) $contoh['template_html']);
        self::assertNotEmpty($contoh['nilai']);
        self::assertNotEmpty($contoh['peta']);
    }

    /**
     * Bentuknya sama dengan baris `ezdoc_templates`.
     *
     * Termasuk config yang masih berupa TEKS JSON, bukan array: pemakainya
     * memanggil json_decode sendiri, sama seperti pada baris database.
     */
    public function testBentuknyaSamaDenganBarisTemplate(): void
    {
        $contoh = Contoh::muat();

        foreach (['nama_template', 'doc_scope', 'template_html', 'config_ttd', 'config_header', 'is_locked'] as $kolom) {
            self::assertArrayHasKey($kolom, $contoh, 'kolom ' . $kolom . ' tidak ada');
        }
        self::assertIsString($contoh['config_header']);
        self::assertIsArray(json_decode((string) $contoh['config_header'], true));
        self::assertSame(1, $contoh['is_locked'], 'contoh harus terkunci — tidak ada yang boleh ditulis');
    }

    /** Nama folder yang tidak sah ditolak, bukan dicoba. */
    public function testNamaTidakSahDitolak(): void
    {
        foreach ([
            '../../etc/passwd',
            '..',
            'surat-keterangan-tindakan/../..',
            'Surat-Kapital',
            'ada spasi',
            'tanda%persen',
            'yang-tidak-ada',
        ] as $nama) {
            self::assertNull(Contoh::muat($nama), 'nama "' . $nama . '" seharusnya ditolak');
        }
    }

    /** Daftarnya berisi contoh yang benar-benar bisa dimuat. */
    public function testDaftarBerisiYangBisaDimuat(): void
    {
        $daftar = Contoh::daftar();

        self::assertArrayHasKey(Contoh::BAWAAN, $daftar);
        foreach ($daftar as $slug => $nama) {
            self::assertNotNull(Contoh::muat($slug), 'contoh ' . $slug . ' terdaftar tapi tidak bisa dimuat');
            self::assertNotSame('', $nama);
        }
    }

    /** Gambarnya ikut ditunjuk, supaya halaman tidak menebak nama berkas. */
    public function testGambarAsalIkutDitunjuk(): void
    {
        $contoh = Contoh::muat();

        self::assertNotSame('', (string) $contoh['gambar']);
        self::assertFileExists((string) $contoh['gambar']);
    }
}
