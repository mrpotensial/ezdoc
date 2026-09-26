<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Contoh dibuka di designer.
 *
 * Diminta pemakai: "apakah buat agar bisa edit di designer juga contohnya?
 * untuk memastikan terhubung dengan benar, tapi gunakan local storage aja".
 *
 * "Terhubung dengan benar" itu intinya: kalau markup contohnya salah, designer
 * yang pertama menunjukkannya — panelnya kosong, atau elemennya tidak
 * terdeteksi. Karena itu yang diuji bukan cuma halamannya terbuka, melainkan
 * bahwa templatenya sampai ke editor lengkap dengan config-nya.
 *
 * Dan tetap satu kalimat yang dijaga: contoh tidak pernah jadi template. Dua
 * lapis — simpan dialihkan ke peramban di sisi halaman, dan penjaga di sisi
 * server menolak nomor template berupa teks `contoh:…` kalau lapisan pertama
 * gagal.
 */
final class ContohDiDesignerTest extends TestCase
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

    private static function halaman(string $id = 'contoh'): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_contoh_designer.php'),
            escapeshellarg($id)
        ), $baris);

        return implode("\n", $baris);
    }

    /** Templatenya sampai ke editor — isi, nama, dan config-nya. */
    public function testContohSampaiKeEditor(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Surat Keterangan Tindakan (contoh)', $halaman);
        // Isi editor: penanda-penandanya utuh, belum diproses apa pun.
        self::assertStringContainsString('{{nama_pasien}}', $halaman);
        self::assertStringContainsString('ttd-placeholder', $halaman);
        self::assertStringContainsString('logo-placeholder', $halaman);
        self::assertStringContainsString('{{tabledb.tindakan.nama_tindakan}}', $halaman);
        // Config-nya ikut, karena panel kanan dibangun dari sana.
        self::assertStringContainsString('tableDbQueries', $halaman);
        self::assertStringContainsString('"paperSize"', str_replace("'", '"', $halaman));
    }

    /** Halamannya menyebut dirinya contoh, dan punya jalan keluar. */
    public function testAdaPenandaDanJalanKeluar(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Mode contoh', $halaman);
        self::assertStringContainsString('peramban ini saja', $halaman);
        self::assertStringContainsString('ezContohDesainerHapus', $halaman, 'tidak ada cara membuang draf percobaan');
        self::assertStringContainsString('ezdoc_page=generate&amp;template_id=contoh', $halaman, 'tidak ada jalan ke halaman Buat dokumen');
        self::assertStringContainsString('ezdoc_page=contoh', $halaman, 'tidak ada jalan ke penjelasannya');
    }

    /**
     * Nomor templatenya teks, bukan angka.
     *
     * Itu yang membuat penjaga di sisi server bisa mengenalinya: nomor template
     * sungguhan tidak pernah berupa teks.
     */
    public function testNomorTemplatenyaMenyebutContoh(): void
    {
        $halaman = self::halaman();

        self::assertSame(
            1,
            preg_match('/<input type="hidden" id="templateId" value="contoh:surat-keterangan-tindakan">/', $halaman),
            'nomor template contoh tidak terpasang'
        );
    }

    /** Penjaga di sisi server menolak menyimpannya sebagai template. */
    public function testServerMenolakMenyimpanContohJadiTemplate(): void
    {
        $aksi = (string) file_get_contents(dirname(__DIR__, 2) . '/actions/template/save_template.php');

        self::assertSame(
            1,
            preg_match("/strncmp\(\\\$__tidMentah, 'contoh', 6\) === 0/", $aksi),
            'tidak ada penjaga untuk nomor template berupa teks contoh'
        );
        self::assertStringContainsString('ezdoc_respond_error(', $aksi);
        // Penjaganya harus SEBELUM nomornya dijadikan angka — sesudah itu
        // `contoh:…` sudah jadi 0, dan 0 artinya "buat template baru".
        $posJaga = strpos($aksi, "strncmp(\$__tidMentah, 'contoh', 6)");
        $posInt = strpos($aksi, "\$template_id = (int)");
        self::assertNotFalse($posJaga);
        self::assertNotFalse($posInt);
        self::assertLessThan($posInt, $posJaga, 'penjaganya kesiangan — nomornya sudah terlanjur jadi 0');
    }

    /** Nama contoh yang tidak dikenal tidak membuka editor. */
    public function testNamaTidakDikenalTidakMembukaEditor(): void
    {
        $halaman = self::halaman('contoh:tidak-ada');

        self::assertStringNotContainsString('Mode contoh', $halaman);
        self::assertStringNotContainsString('{{nama_pasien}}', $halaman);
    }

    /** Skrip mode contohnya dijalankan, bukan dibaca. */
    public function testSimpanMendaratDiPeramban(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $halaman = self::halaman();
        self::assertSame(
            1,
            preg_match('/<script>\s*\(function \(\) \{([\s\S]*?ezdoc_contoh_designer_[\s\S]*?)\}\)\(\);\s*<\/script>/', $halaman, $m),
            'skrip mode contoh designer tidak ada di halaman'
        );

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-contoh-des-') . '.js';
        file_put_contents($js, '(function () {' . $m[1] . '})();');

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/contoh-simpan-designer.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }
}
