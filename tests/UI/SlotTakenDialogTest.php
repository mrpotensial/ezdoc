<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Form BARU yang menabrak dokumen bertanda tangan: dua jalan keluar yang bekerja.
 *
 * Dilaporkan dari pemakaian: membuat dokumen dengan No RM dan No Pendaftaran
 * yang sudah dipakai memunculkan "Document already signed … Changed: nik, ttl,
 * nama" dengan satu tombol, Create New Version. Tidak ada jalan ke dokumen yang
 * ada. Modal versi barunya tidak menawarkan satu pun versi untuk disalin, dan
 * "Blank" gagal dengan "Incomplete parameters".
 *
 * Sebabnya: modal versi baru mengambil slotnya dari CURRENT_NORM/NOPEN/LABEL —
 * konstanta yang diisi saat halaman dimuat. Di form baru isinya kosong. Slot
 * yang ditabrak ada di jawaban server, dan tidak pernah sampai ke modalnya.
 *
 * Fungsinya dijalankan, bukan dibaca — diekstrak dari halaman lalu dijalankan
 * dengan Node, dialog dan fetch tiruan. Dilewati kalau Node tidak ada.
 */
final class SlotTakenDialogTest extends TestCase
{
    private const SKRIP = '/views/_partials/generate_scripts.php';
    private const DIALOG = '/views/_partials/dialog_helper.php';

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

    /** Satu fungsi utuh, dari kata `function`-nya sampai kurung tutupnya. */
    private static function fungsi(string $sumber, string $awal): string
    {
        $mulai = strpos($sumber, $awal);
        self::assertNotFalse($mulai, "`{$awal}` tidak ketemu — premis tes ini perlu ditinjau");

        $buka = strpos($sumber, '{', $mulai);
        $dalam = 0;
        for ($i = $buka, $n = strlen($sumber); $i < $n; $i++) {
            if ($sumber[$i] === '{') {
                $dalam++;
            } elseif ($sumber[$i] === '}' && --$dalam === 0) {
                return substr($sumber, $mulai, $i - $mulai + 1);
            }
        }

        self::fail("kurung `{$awal}` tidak seimbang");
    }

    /** @return array<string,mixed> */
    private function jalankan(string $skenario): array
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $s = self::sumber(self::SKRIP);
        $mulaiSlot = strpos($s, 'let _nvSlot = null;');
        self::assertNotFalse($mulaiSlot, 'penyimpan slot modal versi baru tidak ada');

        $js = self::fungsi($s, 'async function handleSaveRejection(')
            . "\n" . substr($s, $mulaiSlot, strpos($s, 'async function showNewVersionModal(') - $mulaiSlot)
            . "\n" . self::fungsi($s, 'async function showNewVersionModal(')
            . "\n" . self::fungsi($s, 'async function doCreateNewVersion(');

        $berkas = tempnam(sys_get_temp_dir(), 'ezdoc-js-') . '.js';
        file_put_contents($berkas, $js);

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/slot-taken-new-version.js'),
                escapeshellarg($berkas),
                escapeshellarg($skenario)
            ));
        } finally {
            @unlink($berkas);
        }

        $hasil = json_decode(trim($keluaran), true);
        self::assertIsArray($hasil, "keluaran bukan JSON:\n" . $keluaran);
        self::assertArrayNotHasKey('lempar', $hasil, 'fungsinya melempar: ' . ($hasil['lempar'] ?? ''));

        return $hasil;
    }

    /** @param array<string,mixed> $hasil @return array<string,array<string,string>> */
    private static function kiriman(array $hasil): array
    {
        $per = [];
        foreach ($hasil['kiriman'] as $k) {
            $per[$k['aksi']] = $k;
        }

        return $per;
    }

    public function testDialognyaMenawarkanDokumenYangAdaDanVersiBaru(): void
    {
        $hasil = $this->jalankan('slot-tutup');

        self::assertSame('Open existing document', $hasil['dialog']['konfirmasi']);
        self::assertSame('Create New Version', $hasil['dialog']['kedua']);
    }

    /**
     * Daftar "copy from" diminta untuk slot yang DITABRAK.
     *
     * Sebelumnya diminta untuk slot halaman — kosong di form baru — jadi tidak
     * satu pun versi muncul untuk disalin.
     */
    public function testDaftarVersiDimintaUntukSlotYangDitabrak(): void
    {
        $per = self::kiriman($this->jalankan('slot-versi-baru'));

        self::assertArrayHasKey('list_versions', $per);
        self::assertSame(['1', '11', '-'], [$per['list_versions']['norm'], $per['list_versions']['nopen'], $per['list_versions']['label']]);
    }

    /** "Blank" mengirim slot yang lengkap — bukan norm kosong, "Incomplete parameters". */
    public function testVersiBaruDibuatDiSlotYangDitabrak(): void
    {
        $hasil = $this->jalankan('slot-versi-baru');
        $per = self::kiriman($hasil);

        self::assertArrayHasKey('new_version', $per);
        self::assertSame(['1', '11'], [$per['new_version']['norm'], $per['new_version']['nopen']]);
        self::assertNull($hasil['galat'], 'pembuatan versi baru gagal: ' . (string) $hasil['galat']);

        parse_str(ltrim((string) $hasil['tujuan'], '?'), $q);
        self::assertSame(['1', '11', '3'], [$q['norm'] ?? null, $q['nopen'] ?? null, $q['version'] ?? null]);
    }

    public function testBukaDokumenYangAdaMenujuSlotnya(): void
    {
        $hasil = $this->jalankan('slot-buka');

        parse_str(ltrim((string) $hasil['tujuan'], '?'), $q);
        self::assertSame(['3', '1', '11'], [$q['template_id'] ?? null, $q['norm'] ?? null, $q['nopen'] ?? null]);
        self::assertArrayNotHasKey('label', $q, 'label bawaan "-" tidak ikut di URL');
        self::assertTrue($hasil['dibersihkan'], 'peringatan "belum tersimpan" akan menahan pilihan orangnya sendiri');
        self::assertSame([], $hasil['kiriman']);
    }

    public function testTutupTidakMelakukanApaPun(): void
    {
        $hasil = $this->jalankan('slot-tutup');

        self::assertSame([], $hasil['kiriman']);
        self::assertSame('', $hasil['tujuan']);
    }

    /**
     * Dokumen yang sudah terbuka tetap memakai slot halamannya.
     *
     * Penolakan "field beku berubah" memanggil modal tanpa argumen — dan harus
     * tetap bekerja persis seperti sebelumnya.
     */
    public function testPenolakanBekuBiasaTetapMemakaiSlotHalaman(): void
    {
        $per = self::kiriman($this->jalankan('beku-biasa'));

        self::assertSame(['77', '88', 'A'], [$per['list_versions']['norm'], $per['list_versions']['nopen'], $per['list_versions']['label']]);
        self::assertSame(['77', '88'], [$per['new_version']['norm'], $per['new_version']['nopen']]);
    }

    /**
     * Tombol ketiga dialog: opsional, dan menjawab 'secondary'.
     *
     * Tanpa `secondaryText` dialognya persis seperti sebelumnya — setiap
     * ezdocConfirm lain di pustaka ini tetap menerima true/false.
     */
    public function testDialogPunyaTombolKetigaYangOpsional(): void
    {
        $d = self::sumber(self::DIALOG);

        self::assertMatchesRegularExpression(
            '/\$\{secondaryText \? `<button[^`]*data-ezdoc-dialog-secondary[^`]*`\s*:\s*\'\'\}/',
            $d,
            'tombol ketiga harus hanya dirender kalau teksnya diberikan'
        );
        self::assertStringContainsString("close('secondary')", $d);
        self::assertMatchesRegularExpression(
            '/window\.ezdocConfirm = function[\s\S]*?secondaryText: opts\.secondaryText/',
            $d,
            'ezdocConfirm harus meneruskan secondaryText ke dialog'
        );
    }
}
