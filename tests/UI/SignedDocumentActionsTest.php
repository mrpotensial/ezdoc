<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Tombol utama berganti begitu ada SATU tanda tangan.
 *
 * Dilaporkan dari pemakaian: sesudah menandatangani, orang menekan Simpan
 * berulang kali. Tiap tekanan menulis baris audit untuk perubahan yang tidak
 * ada, dan riwayat yang penuh simpanan kosong membuat yang benar-benar terjadi
 * jadi sulit dilihat.
 *
 * Sebelumnya tombolnya baru berganti saat SELURUH slot terisi, jadi dokumen
 * dengan dua atau tiga penanda tangan menghabiskan sebagian besar hidupnya
 * menawarkan Simpan yang tidak lagi menyimpan apa pun.
 *
 * Yang berubah hanya tampilan tombolnya. Menyimpan tetap boleh sampai seluruh
 * slot terisi: `saveSign()` memanggil `submitForm()` sendiri, dan penjaga di
 * `save_document.php` tidak ikut diubah. Itu yang membuat penggantian ini aman,
 * dan itu juga yang dijaga di sini — kalau `saveSign()` berhenti menyimpan,
 * penanda tangan kedua kehilangan satu-satunya jalannya.
 */
final class SignedDocumentActionsTest extends TestCase
{
    private function view(): string
    {
        $kode = '';
        foreach (token_get_all((string) file_get_contents(
            dirname(__DIR__, 2) . '/views/document/generate.php'
        )) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        return $kode;
    }

    public function testPemicunyaAdaTtdBukanLengkap(): void
    {
        $kode = $this->view();

        self::assertStringContainsString(
            '$__adaTtd = ($__signedCount > 0);',
            $kode,
            'pemicunya harus jumlah tanda tangan, bukan keadaan lengkap'
        );

        self::assertStringContainsString(
            'onclick="showNewVersionModal()"',
            $kode,
            'tombol New Version hilang — premis tes ini perlu ditinjau'
        );

        // Kondisi cabangnya sendiri, bukan sekadar ada-tidaknya nama variabelnya
        // di sekitar tombol. Baris penugasan $__adaTtd duduk tepat di atas
        // tombolnya, jadi memeriksa "sekitar" akan tetap hijau walau cabangnya
        // dikembalikan ke pemicu lama — penjaga yang tidak menjaga apa pun.
        self::assertStringContainsString(
            'if ($__adaTtd && $isEditMode && !$param_is_locked)',
            $kode,
            'cabangnya tidak dipicu ada-tidaknya tanda tangan'
        );

        self::assertStringNotContainsString(
            'if ($docSigningComplete && $isEditMode && !$param_is_locked)',
            $kode,
            'cabangnya kembali menunggu semua slot terisi'
        );
    }

    /**
     * Menyimpan tetap jalan — inilah yang membuat penggantian tombol aman.
     *
     * Kalau ini merah, tombol Update sudah hilang DAN tanda tangan berikutnya
     * tidak tersimpan: dokumen dua penanda tangan tidak akan pernah lengkap.
     */
    public function testMenandatanganiTetapMenyimpanSendiri(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php'
        );

        $awal = strpos($js, 'async function saveSign()');
        self::assertNotFalse($awal, 'saveSign() hilang — premis tes ini perlu ditinjau');

        // Dua mode, dua jalan keluar: QR dan gambar. Keduanya harus menyimpan.
        $badan = substr($js, $awal, 3500);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($badan, 'submitForm()'),
            'saveSign() tidak lagi menyimpan di kedua modenya'
        );
    }

    /** Penjaga server tidak ikut diubah: yang beku tetap sebatas cakupannya. */
    public function testPenjagaServerTidakIkutMengeras(): void
    {
        $save = (string) file_get_contents(
            dirname(__DIR__, 2) . '/actions/document/save_document.php'
        );

        self::assertStringContainsString(
            '$frozenFields',
            $save,
            'pembekuan per-cakupan hilang — dokumen banyak penanda tangan akan tertolak'
        );
        self::assertStringContainsString('covered_fields', $save);
    }

    /** Tooltipnya harus jujur saat baru sebagian ditandatangani. */
    public function testTooltipMembedakanSebagianDanLengkap(): void
    {
        $kode = $this->view();

        self::assertStringContainsString("'title.update_off_all_signed'", $kode);
        self::assertStringContainsString("'title.update_off_signed'", $kode);
        self::assertStringContainsString('$docSigningComplete', $kode);

        foreach (['en', 'id'] as $lang) {
            $pesan = require dirname(__DIR__, 2) . "/lang/{$lang}/generate.php";
            self::assertArrayHasKey(
                'update_off_signed',
                $pesan['title'],
                "kunci tooltip belum ada di lang/{$lang}"
            );
        }
    }
}
