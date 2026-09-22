<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use PHPUnit\Framework\TestCase;

/**
 * Dua CLI penyimpanan objek, dijalankan ujung ke ujung.
 *
 * `mirror-blobs-to-disk.php` — menyalin tanda tangan dari kolom ke berkas,
 * TANPA menulis ke basis data. Itu syarat pemakai: "tanpa merusak tabel, karena
 * v1 masih pakai tabel". Diuji dengan sidik jari seluruh isi basis data sebelum
 * dan sesudah, bukan dengan memeriksa beberapa kolom pilihan.
 *
 * `purge-orphan-blobs.php` — versi sebelumnya hanya mengumpulkan rujukan dari
 * `signature_values`, jadi `apply` akan menghapus setiap PDF beku yang lebih tua
 * dari masa tenggang. Arsip itu bukti dengan masa simpan.
 *
 * Dijalankan lewat tests/fixtures/jalankan_cli_blob.php, karena CLI-nya
 * berakhir dengan `exit`.
 */
final class BlobCliTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }
        $this->dir = sys_get_temp_dir() . '/ezdoc-cli-blob-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/simpan', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->dir);
        }
    }

    /** @return array{keluaran:string,kode:int,hasil:array<string,mixed>} */
    private function jalankan(string $cli, string $mode): array
    {
        $baris = [];
        $kode = 0;
        exec(sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_cli_blob.php'),
            escapeshellarg($cli),
            escapeshellarg($mode),
            escapeshellarg($this->dir . '/db.sqlite'),
            escapeshellarg($this->dir . '/simpan')
        ), $baris, $kode);

        $keluaran = implode("\n", $baris);
        self::assertSame(1, preg_match('/@@HASIL@@(\{.*\})/', $keluaran, $m), "tidak ada hasil:\n" . $keluaran);

        return ['keluaran' => $keluaran, 'kode' => $kode, 'hasil' => (array) json_decode($m[1], true)];
    }

    /** @param array<string,mixed> $hasil @return list<string> */
    private static function isiBerkas(array $hasil): array
    {
        $isi = array_values((array) $hasil['berkas']);
        sort($isi);

        return $isi;
    }

    // ── Penyalin ─────────────────────────────────────────────────────────

    public function testMenyalinGambarDariKolomKeBerkas(): void
    {
        $j = $this->jalankan('mirror', 'apply');

        // D sudah ada sebelumnya; A, B, E disalin; C yang rusak tidak.
        self::assertSame(['%PDF-1.7 beku', 'ttd-a', 'ttd-b', 'yatim'], self::isiBerkas($j['hasil']));
    }

    /** Syarat utamanya: tidak satu byte pun di basis data berubah. */
    public function testBasisDataTidakBerubahSedikitPun(): void
    {
        $j = $this->jalankan('mirror', 'apply');

        self::assertTrue($j['hasil']['db_sama'], "isi basis data berubah:\n" . $j['keluaran']);
        self::assertStringContainsString('The database was not modified.', $j['keluaran']);
    }

    /** Baris yang isinya tidak cocok dengan kuncinya dilaporkan, tidak disalin. */
    public function testIsiRusakDilaporkanDanTidakDisalin(): void
    {
        $j = $this->jalankan('mirror', 'apply');

        self::assertStringContainsString('CORRUPT', $j['keluaran']);
        self::assertNotContains('palsu', self::isiBerkas($j['hasil']));
        self::assertSame(1, $j['kode'], 'ada yang rusak: kode keluar harus menandainya');
    }

    public function testDryRunTidakMenulisApaPun(): void
    {
        $j = $this->jalankan('mirror', 'dry-run');

        self::assertSame(['%PDF-1.7 beku'], self::isiBerkas($j['hasil']));
        self::assertTrue($j['hasil']['db_sama']);
        self::assertStringContainsString('To copy  : 3 objects', $j['keluaran']);
    }

    /** Aman diulang: yang sudah di disk dilewati. */
    public function testJalankanKeduaMelewatiYangSudahAda(): void
    {
        $this->jalankan('mirror', 'apply');
        $j = $this->jalankan('mirror', 'apply');

        self::assertStringContainsString('On disk  : 3 objects already there', $j['keluaran']);
        self::assertTrue($j['hasil']['db_sama']);
    }

    public function testGambarInlineDilaporkanDanDibiarkan(): void
    {
        $j = $this->jalankan('mirror', 'apply');

        self::assertStringContainsString('Inline   : 1 images still inside documents', $j['keluaran']);
    }

    // ── Purge ────────────────────────────────────────────────────────────

    /** PDF beku hanya dirujuk tabel artefak — dan harus tetap ada. */
    public function testPurgeTidakMenghapusArsipBeku(): void
    {
        $j = $this->jalankan('purge', 'apply');
        $pdf = hash('sha256', '%PDF-1.7 beku');

        self::assertContains($pdf, $j['hasil']['baris'], 'baris arsip PDF beku terhapus');
        self::assertContains('%PDF-1.7 beku', self::isiBerkas($j['hasil']));
    }

    public function testPurgeMenghapusYangTakDirujukSaja(): void
    {
        $j = $this->jalankan('purge', 'apply');
        $baris = $j['hasil']['baris'];

        self::assertContains(hash('sha256', 'ttd-a'), $baris);
        self::assertContains(hash('sha256', 'ttd-b'), $baris);
        self::assertNotContains(hash('sha256', 'yatim'), $baris);
    }

    /** Salinan berkas dari objek yang dipurge ikut dihapus. */
    public function testPurgeIkutMenghapusSalinanBerkas(): void
    {
        $this->jalankan('mirror', 'apply');
        $j = $this->jalankan('purge', 'apply');

        self::assertNotContains('yatim', self::isiBerkas($j['hasil']));
        self::assertContains('ttd-a', self::isiBerkas($j['hasil']));
    }
}
