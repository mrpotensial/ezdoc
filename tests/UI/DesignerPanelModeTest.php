<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Panel designer punya dua cara melihat isi yang sama.
 *
 * Diminta pemakai: "ada mode simple ada mode panel seperti sekarang… di mode
 * simple yang diklik yang muncul opsi/properties kanannya, misal klik label
 * muncul properties label itu aja". Sebelas seksi terbuka sekaligus membuat
 * orang mencari-cari padahal yang sedang dikerjakan cuma satu elemen.
 *
 * Yang berubah hanya apa yang TERLIHAT: seluruh seksi tetap ada di DOM dan
 * tetap tersimpan seperti biasa, jadi tidak ada jalur penyimpanan yang perlu
 * tahu mode panel sedang apa. Itu yang membuat perubahan ini murah — dan yang
 * diikat oleh tes di bawah.
 */
final class DesignerPanelModeTest extends TestCase
{
    private const HALAMAN = '/views/document/designer.php';
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

    private static function sumber(string $berkas): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . $berkas);
    }

    /** Modulnya dijalankan, bukan dibaca. */
    public function testModePanelBekerja(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = self::sumber(self::SKRIP);
        $awal = strpos($skrip, 'window.ezdocPanelMode = (function () {');
        self::assertNotFalse($awal, 'modul mode panel tidak ketemu');
        $akhir = strpos($skrip, '})();', $awal);
        self::assertNotFalse($akhir);

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-panel-') . '.js';
        file_put_contents($js, substr($skrip, $awal, $akhir - $awal + 5));

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/designer-panel-mode.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }

    /**
     * Setiap seksi panel bisa dikenali.
     *
     * Mode "Selected" menyembunyikan seksi yang tidak dipilih lewat penanda ini.
     * Seksi yang lupa ditandai tidak akan pernah bisa ditampilkan sendirian —
     * dan itu kegagalan yang senyap: panel tampak kosong, bukan error.
     */
    public function testSemuaSeksiPanelPunyaPenanda(): void
    {
        $halaman = self::sumber(self::HALAMAN);

        foreach (['page', 'field', 'logo', 'ttd', 'materai', 'tabledb', 'marker', 'qr', 'cond', 'verify', 'access'] as $seksi) {
            self::assertStringContainsString('data-ez-section="' . $seksi . '"', $halaman, 'seksi ' . $seksi . ' belum ditandai');
        }
    }

    /**
     * Klik elemen di editor memilih elemen itu di panel.
     *
     * Satu sumber kebenaran tentang "apa yang sedang dikerjakan": jalur yang
     * sudah dipakai menyorot kartu sekarang juga yang mengatur mode Selected.
     */
    public function testPilihanDariEditorMasukKeModePanel(): void
    {
        $skrip = self::sumber(self::SKRIP);

        self::assertMatchesRegularExpression(
            '/window\.ezdocFocusSidebarCard = function\(type, id\) \{[\s\S]{0,400}?ezdocPanelMode\.pilih\(type, id\)/',
            $skrip,
            'pilihan dari editor tidak diteruskan ke panel'
        );
    }

    /**
     * Klik di tempat kosong melepas pilihan.
     *
     * Menahan pilihan lama akan membuat panel menunjukkan properti elemen yang
     * sudah tidak disentuh siapa pun — dan perubahan yang diketik di sana akan
     * mengenai elemen yang salah.
     */
    public function testKlikTempatKosongMelepasPilihan(): void
    {
        $skrip = self::sumber(self::SKRIP);

        // Cabang "tidak ada elemen yang cocok" pada penangan klik editor.
        $awal = strpos($skrip, 'if (type && id) {');
        self::assertNotFalse($awal, 'penangan klik editor tidak ketemu');
        $cabang = substr($skrip, $awal, 900);

        self::assertStringContainsString('ezdocFocusSidebarCard(type, id);', $cabang);
        self::assertStringContainsString('ezdocPanelMode.kosongkan();', $cabang, 'klik di luar elemen tidak melepas pilihan');
        self::assertStringContainsString('ezdocClearFloatingSelection();', $cabang, 'penanda elemen mengambang ikut dilepas');
    }

    /** Penanda tabledb juga sebuah elemen dokumen — ia ikut memilih seksinya. */
    public function testPenandaTabledbIkutMemilih(): void
    {
        $skrip = self::sumber(self::SKRIP);

        self::assertStringContainsString("ezdocPanelMode.pilih('marker', null)", $skrip);
        self::assertStringContainsString('ezdocPanelMode.kosongkan()', $skrip);
    }

    // ── Elemen mengambang ────────────────────────────────────────────────

    /**
     * Elemen mengambang yang dipilih terlihat dipilih.
     *
     * Dilaporkan dari pemakaian: "harusnya dia warna ungu dan aktif jika
     * terseleksi gambar float nya, ini cuma aktif saat gambar float di drag
     * aja". Memang belum ada gayanya sama sekali — yang terlihat saat menggeser
     * itu efek `:hover`, jadi elemen terpilih tampak sama dengan yang tidak.
     */
    public function testElemenMengambangTerpilihPunyaGayaSendiri(): void
    {
        $skrip = self::sumber(self::SKRIP);

        self::assertStringContainsString('.logo-placeholder.floating.ez-float-selected', $skrip);
        self::assertStringContainsString("target.classList.add('ez-float-selected')", $skrip);
        self::assertMatchesRegularExpression(
            "/_tandaiFloating = \(target\) => \{[\s\S]{0,400}?querySelectorAll\('\.ez-float-selected'\)/",
            $skrip,
            'penanda lama harus dilepas — hanya satu elemen terpilih pada satu waktu'
        );
    }

    /**
     * Memilih elemen mengambang tidak menyeleksi teks.
     *
     * Dilaporkan: menggeser gambar mengambang menyorot paragraf di bawah
     * kursor. Sebabnya wrapper-nya diseleksi utuh, jadi ada seleksi hidup yang
     * diperpanjang gerakan mouse berikutnya — dan toolbar melayang TinyMCE
     * muncul karenanya.
     */
    public function testMemilihElemenMengambangTidakMenyeleksiTeks(): void
    {
        $skrip = self::sumber(self::SKRIP);

        self::assertMatchesRegularExpression(
            '/editor\.selection\.select\(wrapper\);\s*
\s*editor\.selection\.collapse\(true\);/',
            $skrip,
            'seleksi harus dikerutkan jadi caret'
        );
    }

    /** Selama menggeser, dokumen tidak menyeleksi teks — dan dipulihkan sesudahnya. */
    public function testMenggeserMematikanSeleksiTeksLaluMemulihkannya(): void
    {
        $skrip = self::sumber(self::SKRIP);

        self::assertStringContainsString("iframeDoc.body.style.userSelect = 'none';", $skrip);
        self::assertStringContainsString("iframeDoc.body.style.userSelect = '';", $skrip);
    }

    /**
     * Klik yang mengakhiri sebuah geseran tidak melepas pilihan.
     *
     * Melepas tombol mouse di atas teks menghasilkan satu klik susulan yang
     * mendarat di teks itu, bukan di gambar yang baru saja digeser. Tanpa
     * penjagaan ini, pilihan terlepas tepat setelah dibuat — dan kartunya
     * kehilangan tanda aktif, persis seperti yang dilaporkan.
     */
    public function testKlikSusulanGeseranDiabaikan(): void
    {
        $skrip = self::sumber(self::SKRIP);

        self::assertStringContainsString('window.ezdocFloatingDragSelesai = Date.now();', $skrip);
        self::assertStringContainsString(
            "} else if (!((window.ezdocFloatingDragSelesai || 0) > Date.now() - 300)) {",
            $skrip,
            'pelepas pilihan harus mengabaikan klik susulan geseran'
        );
    }

    /**
     * Mode tidak menyentuh isi panel, hanya menyembunyikannya.
     *
     * Kalau mode sampai membongkar DOM, isian yang belum tersimpan akan hilang
     * saat orang berpindah mode — dan hilangnya tidak akan terlihat sampai
     * dokumennya disimpan.
     */
    public function testModeHanyaMenyembunyikanLewatGaya(): void
    {
        $halaman = self::sumber(self::HALAMAN);
        $skrip = self::sumber(self::SKRIP);

        self::assertStringContainsString('#ezSidebar[data-mode="simple"] [data-ez-section]:not(.ez-seksi-aktif) { display: none; }', $halaman);
        foreach (['remove()', 'innerHTML =', 'removeChild'] as $bongkar) {
            $awal = strpos($skrip, 'window.ezdocPanelMode = (function () {');
            $modul = substr($skrip, $awal, strpos($skrip, '})();', $awal) - $awal);
            self::assertStringNotContainsString($bongkar, $modul, 'modul mode tidak boleh mengubah isi panel');
        }
    }
}
