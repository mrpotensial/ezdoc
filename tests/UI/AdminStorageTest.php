<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Tugas penyimpanan objek dari halaman admin.
 *
 * Diminta pemakai: "apa tidak bisa ada ui untuk run itu?". Yang dijalankan
 * halaman ini persis yang dijalankan CLI — BlobMaintenance yang sama — jadi
 * yang diuji di sini bagian yang hanya dimiliki halaman: gerbang akses,
 * pemotongan per batch, dan bahwa tak satu pun tugasnya menulis ke basis data.
 *
 * Aksinya menjawab JSON lalu `exit`, jadi dijalankan di proses terpisah lewat
 * tests/fixtures/jalankan_admin_penyimpanan.php.
 */
final class AdminStorageTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }
        $this->dir = sys_get_temp_dir() . '/ezdoc-admin-' . bin2hex(random_bytes(6));
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

    /** @return array{keluaran:string,json:array<string,mixed>,hasil:array<string,mixed>} */
    private function aksi(string $aksi, string $apply = '0', string $peran = 'superadmin'): array
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_admin_penyimpanan.php'),
            escapeshellarg($peran),
            escapeshellarg($aksi),
            escapeshellarg($apply),
            escapeshellarg($this->dir . '/db.sqlite'),
            escapeshellarg($this->dir . '/simpan')
        ), $baris);

        $keluaran = implode("\n", $baris);
        self::assertSame(1, preg_match('/@@HASIL@@(\{.*\})/', $keluaran, $m), "tidak ada hasil:\n" . $keluaran);
        $json = [];
        if (preg_match('/^\{"ok".*\}$/m', $keluaran, $j) === 1) {
            $json = (array) json_decode($j[0], true);
        }

        return ['keluaran' => $keluaran, 'json' => $json, 'hasil' => (array) json_decode($m[1], true)];
    }

    // ── Gerbang ──────────────────────────────────────────────────────────

    /**
     * Bukan superadmin: tidak ada tugas yang jalan.
     *
     * Gerbangnya milik halaman, dan tugas-tugas ini menempel di bawahnya. Kalau
     * urutannya tertukar, siapa pun yang bisa POST bisa menjalankan pemeliharaan.
     */
    public function testBukanSuperadminTidakMendapatApaPun(): void
    {
        $r = $this->aksi('salin', '1', 'biasa');

        self::assertSame([], $r['json'], 'tidak boleh ada jawaban JSON');
        self::assertStringContainsString('Access Denied', $r['keluaran']);
        self::assertSame(['%PDF-1.7 beku'], $r['hasil']['berkas'], 'tidak ada berkas yang ditulis');
    }

    // ── Ringkasan ────────────────────────────────────────────────────────

    public function testRingkasanMenghitungIsiPenyimpanan(): void
    {
        $r = $this->aksi('ringkas');

        self::assertTrue($r['json']['ok']);
        self::assertSame(
            ['objek' => 4, 'di_kolom' => 3, 'di_disk' => 1, 'berkas' => 1, 'inline' => 1, 'dirujuk' => 3],
            array_intersect_key($r['json']['ringkas'], array_flip(['objek', 'di_kolom', 'di_disk', 'berkas', 'inline', 'dirujuk']))
        );
    }

    // ── Menyalin ─────────────────────────────────────────────────────────

    public function testMenyalinMenulisBerkasDanTidakMengubahBasisData(): void
    {
        $r = $this->aksi('salin', '1');

        self::assertSame(3, $r['json']['batch']['disalin']);
        self::assertSame(['%PDF-1.7 beku', 'ttd-a', 'ttd-b', 'yatim'], $r['hasil']['berkas']);
        self::assertTrue($r['hasil']['db_sama'], 'basis data berubah');
    }

    public function testMelihatDuluTidakMenulisBerkas(): void
    {
        $r = $this->aksi('salin', '0');

        self::assertSame(3, $r['json']['batch']['disalin'], 'dihitung, tapi tidak ditulis');
        self::assertSame(['%PDF-1.7 beku'], $r['hasil']['berkas']);
        self::assertTrue($r['hasil']['db_sama']);
    }

    /** Jawaban membawa kunci lanjutan, supaya peramban bisa meminta batch berikutnya. */
    public function testJawabanMembawaKunciLanjutan(): void
    {
        $batch = $this->aksi('salin', '0')['json']['batch'];

        self::assertArrayHasKey('after_id', $batch);
        self::assertArrayHasKey('selesai', $batch);
        self::assertGreaterThan(0, $batch['after_id']);
    }

    // ── Memeriksa ────────────────────────────────────────────────────────

    public function testPemeriksaanMenemukanSalinanYangBelumAda(): void
    {
        $r = $this->aksi('periksa');
        $sebab = array_column($r['json']['batch']['masalah'], 'sebab');

        self::assertSame(['berkas_hilang', 'berkas_hilang', 'berkas_hilang'], $sebab);
        self::assertTrue($r['hasil']['db_sama']);
    }

    /** Arsip PDF beku yang memang hanya di disk bukan masalah. */
    public function testArsipBekuTidakDianggapBermasalah(): void
    {
        $this->aksi('salin', '1');
        $r = $this->aksi('periksa');

        self::assertSame([], $r['json']['batch']['masalah']);
        self::assertSame(4, $r['json']['batch']['utuh']);
    }

    // ── Objek tak dirujuk ────────────────────────────────────────────────

    /** Melihat saja: barisnya masih ada sesudahnya. */
    public function testObjekTakDirujukHanyaDitampilkan(): void
    {
        $r = $this->aksi('yatim');

        self::assertSame(1, $r['json']['jumlah']);
        self::assertSame(hash('sha256', 'yatim'), $r['json']['objek'][0]['key']);
        self::assertTrue($r['hasil']['db_sama'], 'tidak boleh ada yang terhapus');
    }

    /** Tidak ada tombol hapus di halamannya — penghapusan byte lewat CLI. */
    public function testHalamanTidakPunyaTombolHapusObjek(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/migrate.php');

        self::assertStringContainsString("data-ez-tugas=\"yatim\"", $sumber);
        self::assertStringNotContainsString("storage_action' => 'hapus", $sumber);
        self::assertStringNotContainsString("case 'hapus'", $sumber);
        self::assertStringContainsString('purge-orphan-blobs.php', $sumber, 'halaman menyebut jalur yang benar untuk menghapus');
    }
}
