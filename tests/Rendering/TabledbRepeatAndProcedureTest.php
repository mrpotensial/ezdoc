<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Dua perluasan tabel kueri: prosedur tersimpan berdaftar izin, dan blok berulang.
 *
 * Prosedur diuji lewat validatornya — yang menentukan apa yang boleh sampai ke
 * basis data. Jalur mysqli-nya (menguras result set tambahan) diuji ujung ke
 * ujung terhadap MySQL sungguhan, di luar suite ini.
 *
 * Blok berulang diuji dengan baris siap-pakai, jalur yang sama dengan halaman
 * contoh: tidak ada kueri yang berjalan.
 */
final class TabledbRepeatAndProcedureTest extends TestCase
{
    private const IZIN = ['layanan.cetakhasilrad', 'bpjs.CetakSEP', 'tanpaskema'];

    // ─── Prosedur ───

    public function testProsedurBerizinDiterima(): void
    {
        self::assertTrue(ezdoc_tabledb_procedure_allowed('CALL layanan.CetakHasilRad({tindakan__ID})', self::IZIN));
        self::assertTrue(ezdoc_tabledb_procedure_allowed('call `bpjs`.`CetakSEP`({nopen});', self::IZIN));
        self::assertTrue(ezdoc_tabledb_procedure_allowed("CALL layanan.CetakHasilRad({nopen}, 'ALL', 3, -1.5, NULL)", self::IZIN));
        self::assertTrue(ezdoc_tabledb_procedure_allowed('CALL tanpaskema()', self::IZIN));
    }

    public function testProsedurDiLuarDaftarDitolak(): void
    {
        self::assertFalse(ezdoc_tabledb_procedure_allowed('CALL layanan.HapusSemua({nopen})', self::IZIN));
        self::assertFalse(ezdoc_tabledb_procedure_allowed('CALL lain.CetakHasilRad({nopen})', self::IZIN), 'skema ikut dicocokkan');
        self::assertFalse(ezdoc_tabledb_procedure_allowed('CALL layanan.CetakHasilRad({nopen})', []));
    }

    /** Tanpa konstanta, tidak ada satu pun prosedur — perilaku sebelum fitur ini. */
    public function testTanpaDaftarIzinTidakAdaProsedur(): void
    {
        if (defined('EZDOC_TABLEDB_PROCEDURES')) {
            self::markTestSkipped('EZDOC_TABLEDB_PROCEDURES didefinisikan di proses ini.');
        }
        self::assertSame([], ezdoc_tabledb_procedures());
        self::assertFalse(ezdoc_tabledb_procedure_allowed('CALL layanan.CetakHasilRad({nopen})'));
    }

    /** Argumen hanya nilai sederhana: tidak ada subkueri, fungsi, atau pernyataan kedua. */
    public function testArgumenSelundupanDitolak(): void
    {
        foreach ([
            'CALL layanan.CetakHasilRad((SELECT password FROM users LIMIT 1))',
            'CALL layanan.CetakHasilRad(NOW())',
            'CALL layanan.CetakHasilRad({nopen}); DROP TABLE x',
            "CALL layanan.CetakHasilRad('a\\'b')",
            "CALL layanan.CetakHasilRad('a' 'b')",
            'CALL layanan.CetakHasilRad({nopen}) UNION SELECT 1',
            'CALL layanan.CetakHasilRad',
            'SELECT 1; CALL layanan.CetakHasilRad(1)',
        ] as $kueri) {
            self::assertFalse(ezdoc_tabledb_procedure_allowed($kueri, self::IZIN), $kueri);
        }
    }

    /** CALL yang tidak berizin berhenti sebelum sambungan disentuh. */
    public function testCallTakBerizinTidakDijalankan(): void
    {
        self::assertSame([], executeDynQuery('CALL layanan.HapusSemua({nopen})', '', '1', [], null));
    }

    // ─── Blok berulang ───

    /** @param array<string,list<array<string,mixed>>> $baris */
    private static function render(string $html, array $baris, array $kueri = []): string
    {
        $GLOBALS['ezdocTableRowsReady'] = $baris;
        try {
            foreach (array_keys($baris) as $ns) {
                $kueri[$ns] = $kueri[$ns] ?? ['sql' => 'SELECT 1'];
            }

            return processTabledbRows($html, $kueri, '', '', [], null);
        } finally {
            unset($GLOBALS['ezdocTableRowsReady']);
        }
    }

    public function testBlokDiulangPerBarisDenganPenandaDiManaSaja(): void
    {
        $html = '<div data-repeat="bayi" class="blok"><p>Bayi {{tabledb.bayi.urut}}: {{tabledb.bayi.nama}}</p>'
            . '<table><tr><td>Berat</td><td>{{tabledb.bayi.berat|number}}</td></tr></table></div>';
        $keluar = self::render($html, ['bayi' => [
            ['urut' => '1', 'nama' => 'Kembar A', 'berat' => '2500'],
            ['urut' => '2', 'nama' => 'Kembar B', 'berat' => '2350'],
        ]]);

        self::assertSame(2, substr_count($keluar, 'class="blok"'));
        self::assertStringContainsString('Bayi 1: Kembar A', $keluar);
        self::assertStringContainsString('Bayi 2: Kembar B', $keluar);
        // Pemisah ribuan ikut bahasa dokumen ("2.500" / "2,500") — yang diuji: format diterapkan.
        self::assertMatchesRegularExpression('/2[.,]500/', $keluar);
        // Baris tabel di dalam blok diisi baris INDUK, bukan digandakan lagi.
        self::assertSame(2, substr_count($keluar, '<td>Berat</td>'));
        self::assertStringNotContainsString('{{tabledb', $keluar);
        self::assertStringNotContainsString('data-repeat', $keluar, 'atribut tidak ikut ke keluaran');
    }

    public function testAgregatDiDalamBlokAtasSeluruhBaris(): void
    {
        $keluar = self::render(
            '<div data-repeat="x"><span>{{tabledb.x.n}} dari {{tabledb.x.count:*}}</span></div>',
            ['x' => [['n' => '1'], ['n' => '2'], ['n' => '3']]]
        );

        self::assertStringContainsString('1 dari 3', $keluar);
        self::assertStringContainsString('3 dari 3', $keluar);
    }

    public function testPemisahHalamanHanyaDiAntaraBlok(): void
    {
        $keluar = self::render(
            '<div data-repeat="x" data-repeat-break="page">{{tabledb.x.n}}</div>',
            ['x' => [['n' => 'A'], ['n' => 'B'], ['n' => 'C']]]
        );

        self::assertSame(2, substr_count($keluar, 'page-break-before:always'));
        self::assertStringStartsWith('<div>A</div>', $keluar);
    }

    public function testTanpaBarisBloknyaHilang(): void
    {
        $keluar = self::render('<p>sebelum</p><div data-repeat="x"><b>{{tabledb.x.n}}</b></div><p>sesudah</p>', ['x' => []]);

        self::assertSame('<p>sebelum</p><p>sesudah</p>', $keluar);
    }

    /** Div di dalam blok tidak memotong blok terlalu cepat. */
    public function testDivBersarangDipasangkanBenar(): void
    {
        $keluar = self::render(
            '<div data-repeat="x"><div><div>{{tabledb.x.n}}</div></div><i>ekor {{tabledb.x.n}}</i></div><p>luar</p>',
            ['x' => [['n' => 'A'], ['n' => 'B']]]
        );

        self::assertSame('<div><div><div>A</div></div><i>ekor A</i></div><div><div><div>B</div></div><i>ekor B</i></div><p>luar</p>', $keluar);
    }

    /** Tabel namespace lain di dalam blok tetap digandakan per barisnya sendiri. */
    public function testNamespaceLainDiDalamBlok(): void
    {
        $html = '<div data-repeat="kunj"><h4>{{tabledb.kunj.tgl}}</h4>'
            . '<table><tr><td>{{tabledb.hasil.item}}</td></tr></table></div>';
        $keluar = self::render($html, [
            'kunj'  => [['tgl' => '01/10'], ['tgl' => '02/10']],
            'hasil' => [['item' => 'Hb'], ['item' => 'Leukosit']],
        ]);

        self::assertSame(2, substr_count($keluar, '<h4>'));
        self::assertSame(2, substr_count($keluar, 'Leukosit'), 'tabel anak dirender di tiap blok');
        self::assertStringNotContainsString('{{tabledb', $keluar);
    }

    public function testBlokBersarang(): void
    {
        $html = '<section data-repeat="luar">[{{tabledb.luar.k}}<div data-repeat="dalam">({{tabledb.dalam.v}})</div>]</section>';
        $keluar = self::render($html, [
            'luar'  => [['k' => 'A'], ['k' => 'B']],
            'dalam' => [['v' => '1'], ['v' => '2']],
        ]);

        self::assertSame('<section>[A<div>(1)</div><div>(2)</div>]</section><section>[B<div>(1)</div><div>(2)</div>]</section>', $keluar);
    }

    public function testTagTakTertutupDibiarkan(): void
    {
        $html = '<div data-repeat="x">{{tabledb.x.n}}';
        $keluar = self::render($html, ['x' => [['n' => 'A']]]);

        self::assertStringContainsString('data-repeat="x"', $keluar);
    }

    // ─── Format jam & |default ───

    /** "H:i" dulu terpotong di ':' — jamnya tercetak tanpa menit. */
    public function testFormatJamTidakTerpotong(): void
    {
        $keluar = self::render(
            '<div data-repeat="x">{{tabledb.x.t|datetime:d/m/y H:i}} | {{tabledb.x.t|datetime:H:i:s}}</div>',
            ['x' => [['t' => '2026-10-12 07:10:05']]]
        );

        self::assertSame('<div>12/10/26 07:10 | 07:10:05</div>', $keluar);
    }

    public function testDefaultMengisiYangKosong(): void
    {
        $keluar = self::render(
            '<div data-repeat="x">[{{tabledb.x.a|default:}}][{{tabledb.x.b|default:-}}][{{tabledb.x.c|default:-}}][{{tabledb.x.d|default:a:b}}]</div>',
            ['x' => [['a' => '', 'b' => null, 'c' => 'ada <b>', 'd' => '']]]
        );

        self::assertSame('<div>[][-][ada &lt;b&gt;][a:b]</div>', $keluar);
    }

    /** Tanpa |default, kosong tetap "—" seperti sebelumnya. */
    public function testKosongTanpaDefaultTetapTanda(): void
    {
        $keluar = self::render('<div data-repeat="x">{{tabledb.x.a}}</div>', ['x' => [['a' => '']]]);

        self::assertStringContainsString('—', $keluar);
    }

    // ─── Format |qr ───

    /** Tanpa pembuat QR terpasang: nilainya tetap tercetak sebagai teks yang di-escape. */
    public function testQrTanpaPembuatJatuhKeTeks(): void
    {
        if (function_exists('generateQrForDompdf')) {
            self::markTestSkipped('Pembuat QR host terpasang di proses ini.');
        }
        $keluar = _tabledbFormatValue('dr. <b>A</b>', ['type' => 'qr', 'args' => ['60']]);

        self::assertStringNotContainsString('<b>', $keluar);
        self::assertStringContainsString('dr. &lt;b&gt;A&lt;/b&gt;', $keluar);
    }

    /** Tanpa data-repeat, perilaku lama tidak berubah sehuruf pun. */
    public function testTanpaBlokPerilakuLama(): void
    {
        $html = '<table><tr><td>{{tabledb.x.n}}</td></tr><tr><td>{{tabledb.x.sum:n}}</td></tr></table>';
        $keluar = self::render($html, ['x' => [['n' => '2'], ['n' => '3']]]);

        self::assertSame('<table><tr><td>2</td></tr><tr><td>3</td></tr><tr><td>5</td></tr></table>', $keluar);
    }
}
