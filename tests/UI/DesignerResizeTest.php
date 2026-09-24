<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Ubah ukuran gambar di designer dengan menarik sudut atau sisinya.
 *
 * Diminta pemakai: "untuk live resize kok hilang ya? harusnya bisa live resize
 * seperti resize window gitu". Pegangannya sebenarnya ada, tapi cuma SATU titik
 * kecil di pojok kanan bawah dan hanya muncul saat mouse menyentuh elemennya —
 * elemen yang sudah dipilih tidak menunjukkan apa pun, jadi wajar dikira hilang.
 *
 * Sekarang: pegangan di sisi kanan, sudut kanan-bawah, dan (untuk yang
 * mengambang) sudut kiri-bawah; muncul juga selama elemennya terpilih; ukuran
 * berjalan ditampilkan; Esc membatalkan.
 *
 * Yang bisa diubah hanya yang punya tempat simpan ukuran. TTD sengaja tidak:
 * ukurannya dikunci supaya sama persis dengan hasil cetak.
 */
final class DesignerResizeTest extends TestCase
{
    private const SKRIP = '/views/_partials/designer_scripts.php';

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

    private static function sumber(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . self::SKRIP);
    }

    /** Penangannya dijalankan di atas DOM tiruan, bukan dibaca. */
    public function testMenarikPeganganMengubahUkuranDanMenyimpannya(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = self::sumber();
        $awal = strpos($skrip, 'function setupDragDelegation() {');
        self::assertNotFalse($awal, 'penangan geser/ubah-ukuran tidak ketemu');
        $tanda = strpos($skrip, "console.log('Drag delegation setup complete');", $awal);
        self::assertNotFalse($tanda);
        $akhir = strpos($skrip, '}', (int) strpos($skrip, "\n", $tanda));
        self::assertNotFalse($akhir);

        // Fungsinya memakai beberapa nilai dari halaman; di sini semuanya
        // dititipkan lewat parameter supaya bisa dijalankan sendirian.
        $js = tempnam(sys_get_temp_dir(), 'ezdoc-resize-') . '.js';
        file_put_contents($js, "window.buatDelegasi = function (editor, dragState, configHeader, scanLogos, "
            . "scanTtdPlaceholders, scanMateraiPlaceholders, _reorderFloatingWrapper, markDirty) {\n"
            . substr($skrip, $awal, $akhir - $awal + 1)
            . "\n  return setupDragDelegation;\n};\n");

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/designer-resize.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }

    /**
     * Pegangan terlihat tanpa harus menyentuh elemennya dulu.
     *
     * Itu inti keluhannya: yang sudah dipilih tampak tidak punya pegangan sama
     * sekali, jadi orang mengira kemampuannya hilang.
     */
    public function testPeganganMunculSaatElemenTerpilih(): void
    {
        $skrip = self::sumber();

        self::assertStringContainsString('.ez-float-selected .ezdoc-resize-handle', $skrip, 'elemen mengambang terpilih tidak menunjukkan pegangan');
        self::assertStringContainsString('[data-mce-selected] .ezdoc-resize-handle', $skrip, 'elemen sebaris terpilih tidak menunjukkan pegangan');
        self::assertStringContainsString('.logo-placeholder:hover .ezdoc-resize-handle', $skrip, 'pegangan saat disentuh mouse ikut hilang');
    }

    /**
     * Menarik pegangan tidak boleh ikut memindahkan tempat.
     *
     * Keduanya dimulai dari tombol mouse yang sama; kalau urutannya terbalik,
     * gambar malah melompat pindah saat orang bermaksud mengubah ukuran.
     */
    public function testMenarikPeganganMendahuluiPemindahan(): void
    {
        $skrip = self::sumber();

        self::assertMatchesRegularExpression(
            "/closest\('\.ezdoc-resize-handle'\)[\s\S]{0,900}?e\.stopPropagation\(\);/",
            $skrip,
            'penangan ubah-ukuran harus menghentikan rambatan ke penangan pindah tempat'
        );
        self::assertMatchesRegularExpression(
            "/closest\('\.ezdoc-resize-handle'\)[\s\S]{0,2000}?\}, true\);/",
            $skrip,
            'penangan ubah-ukuran harus di fase tangkap supaya jalan lebih dulu'
        );
    }

    /**
     * Alat bantu layar tidak boleh ikut tersimpan ke dokumen.
     *
     * Pegangan dan label ukuran adalah elemen sungguhan di dalam isi editor.
     * Tanpa penanda bogus, keduanya ikut ke template yang tersimpan lalu ikut
     * tercetak.
     */
    public function testPeganganDanLabelTidakIkutTersimpan(): void
    {
        $skrip = self::sumber();

        self::assertMatchesRegularExpression(
            "/handle\.className = 'ezdoc-resize-handle';[\s\S]{0,300}?setAttribute\('data-mce-bogus', '1'\)/",
            $skrip
        );
        self::assertMatchesRegularExpression(
            "/badge\.className = 'ezdoc-resize-badge';[\s\S]{0,300}?setAttribute\('data-mce-bogus', '1'\)/",
            $skrip
        );
    }

    /**
     * Hanya yang ukurannya punya tempat simpan yang boleh ditarik.
     *
     * Kalau TTD ikut bisa ditarik, ukurannya berubah di layar tapi tidak ikut
     * tersimpan — dan hasil cetaknya akan berbeda dari yang dilihat di designer.
     */
    public function testHanyaYangUkurannyaTersimpanYangBisaDitarik(): void
    {
        $skrip = self::sumber();

        $awal = strpos($skrip, 'const _resizeJenis = (el) => {');
        self::assertNotFalse($awal, 'penentu jenis yang bisa diubah ukurannya tidak ketemu');
        $blok = substr($skrip, $awal, 500);

        self::assertStringContainsString("classList.contains('logo-placeholder')", $blok);
        self::assertStringContainsString("classList.contains('materai-placeholder')", $blok);
        self::assertStringNotContainsString('ttd-placeholder', $blok, 'ukuran TTD dikunci mengikuti hasil cetak');
        self::assertStringNotContainsString('qr-placeholder', $blok, 'QR di editor belum berupa kotak berukuran');
    }
}
