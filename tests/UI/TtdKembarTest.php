<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Satu tanda tangan tidak bisa berdiri di dua tempat.
 *
 * Dilaporkan pemakai: template dengan empat kotak tanda tangan hanya terbaca
 * dua. Sebabnya dua kotak terakhir hasil salin-tempel dan membawa `data-ttd`
 * yang sama.
 *
 * Yang runtuh bukan cuma hitungannya. Gambar tanda tangan diambil berdasarkan
 * id, jadi satu orang menandatangani sekali dan gambarnya muncul di dua kotak:
 * di kertas terbaca empat orang menandatangani, di catatan hanya dua tindakan.
 * Dokumen itu menyatakan lebih dari yang terjadi.
 *
 * Di produksi pemakai, template #20 sudah begitu: 4 penanda, 2 id.
 */
final class TtdKembarTest extends TestCase
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

    private static function baca(string $berkas): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $berkas);
    }

    /**
     * Penamaan ulangnya benar-benar berjalan, di atas DOM tiruan.
     *
     * Termasuk sisi yang mudah terlewat: nama penanda tangan yang sudah
     * diganti orang TIDAK ikut diubah. Berbagi satu nama bisa disengaja; id
     * kembar tidak pernah.
     */
    public function testPenamaanUlangnyaBerjalan(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = self::baca('views/_partials/designer_scripts.php');
        $awal = strpos($skrip, '/** Semua id ttd yang sudah terpakai di editor. */');
        self::assertNotFalse($awal, 'penolong id ttd tidak ketemu');
        $akhir = strpos($skrip, 'function scanTtdPlaceholders() {', $awal);
        self::assertNotFalse($akhir, 'ujung bagiannya tidak ketemu');

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-ttd-') . '.js';
        file_put_contents(
            $js,
            substr($skrip, $awal, $akhir - $awal)
            . "\nthis.namaiUlangTtdKembar = namaiUlangTtdKembar;\n"
        );

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/ttd-kembar.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }

    /**
     * Dan ia benar-benar dipanggil — di dua tempat.
     *
     * Penolong yang ada tapi tidak pernah dipanggil sama saja dengan tidak
     * ada. Saat menempel supaya yang baru tidak bentrok, dan saat template
     * dibuka supaya yang terlanjur kembar ikut dibereskan.
     */
    public function testDipanggilSaatMenempelDanSaatDibuka(): void
    {
        $skrip = self::baca('views/_partials/designer_scripts.php');

        self::assertSame(
            1,
            preg_match('/paste_postprocess[\s\S]{0,1200}?namaiUlangTtdKembar\(root, idTtdTerpakai\(\)\)/', $skrip),
            'tempelan tidak diperiksa'
        );
        self::assertSame(
            1,
            preg_match('/namaiUlangTtdKembar\(editor\.getBody\(\), new Set\(\)\)[\s\S]{0,400}?scanTtdPlaceholders\(\)/', $skrip),
            'template yang sudah kembar tidak dibereskan saat dibuka, atau dibereskan sesudah dipindai'
        );
    }

    /** Kalimatnya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/designer.php';
            foreach (['ttd_renamed', 'ttd_duplicate_fixed'] as $kunci) {
                self::assertArrayHasKey($kunci, $lang['toast'], $bahasa . ': ' . $kunci);
            }
        }
    }

    // ── Nama dari satu sumber, tanpa berbagi satu field ──────────────────

    /**
     * Nama bawaan tanda tangan boleh menyebut sumbernya.
     *
     * Pemakai memakai satu parameter URL untuk mengisi nama di dua slot. Cara
     * lamanya berbagi satu `nama_field`, dan itu justru melemahkan tanda
     * tangannya: `SignaturePayload::coveredKeysFor()` mengeluarkan nama milik
     * slot LAIN dari cakupan sebuah tanda tangan, jadi nama yang dipakai
     * berdua keluar dari cakupan keduanya.
     *
     * Dengan `get:`, tiap slot punya nama sendiri yang terisi dari satu
     * sumber — kemudahannya kembali, cakupannya utuh.
     */
    public function testNamaBawaanBisaDariParameterUrl(): void
    {
        $layar = self::baca('views/document/generate.php');
        $pdf = self::baca('lib/pdf_compose.php');

        foreach ([$layar => 'layar', $pdf => 'pdf'] as $isi => $nama) {
            self::assertSame(
                1,
                preg_match('/\$defaultNama !== \'\'\) \$nama\w* = resolveDefault\(\$defaultNama\);/', $isi),
                $nama . ': nama bawaan tanda tangan dipakai mentah, tidak lewat penyelesai sumber'
            );
        }
    }

    /**
     * Sumber `get:` hanya menerima nama parameter yang wajar.
     *
     * Nilainya masuk ke dokumen dan tersimpan bersamanya. Yang dibaca harus
     * satu parameter bernama jelas, bukan apa pun yang kebetulan ada di URL.
     */
    public function testSumberUrlDijagaBentukNamanya(): void
    {
        $helper = self::baca('lib/doc_template_helpers.php');

        self::assertSame(
            1,
            preg_match("/strncmp\(\\\$default, 'get:', 4\) === 0/", $helper),
            'sumber get: tidak ada'
        );
        self::assertSame(
            1,
            preg_match("/preg_match\('\/\^\[A-Za-z_\]\[A-Za-z0-9_\]\*\\\$\/', \\\$param\) !== 1/", $helper),
            'nama parameternya tidak diperiksa bentuknya'
        );
        self::assertSame(
            1,
            preg_match('/return is_string\(\$nilai\) \? \$nilai : \'\';/', $helper),
            'nilai yang bukan teks (mis. larik dari ?x\[\]=1) tidak ditolak'
        );
    }
}
