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
 * tests/fixtures/run_storage_admin.php.
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
    private function aksi(string $aksi, string $apply = '0', string $peran = 'superadmin', string $konfirmasi = ''): array
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_storage_admin.php'),
            escapeshellarg($peran),
            escapeshellarg($aksi),
            escapeshellarg($apply),
            escapeshellarg($this->dir . '/db.sqlite'),
            escapeshellarg($this->dir . '/simpan'),
            escapeshellarg($konfirmasi)
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

    // ── Overview, audit, akses ───────────────────────────────────────────

    public function testRingkasanKesehatanMenjawabTigaPertanyaan(): void
    {
        $j = $this->aksi('kesehatan')['json'];

        self::assertSame(1, $j['kesehatan']['tanda_tangan']['total']);
        self::assertSame(0, $j['kesehatan']['tanda_tangan']['bermasalah']);
        self::assertSame(1, $j['kesehatan']['dokumen']['bertanda_tangan']);
        self::assertSame(1, $j['kesehatan']['audit']['gagal'], 'authz.denied bukan keberhasilan');
        self::assertSame(4, $j['penyimpanan']['objek'], 'ringkasan penyimpanan ikut, satu permintaan saja');
    }

    public function testRiwayatKejadianTerbaruLebihDulu(): void
    {
        $j = $this->aksi('audit')['json'];

        self::assertSame('authz.denied', $j['baris'][0]['event_type']);
        self::assertSame(['authz.denied', 'doc.created'], $j['jenis'], 'saringan diisi dari data');
    }

    /**
     * Alamat IP tidak ikut terkirim tanpa hak melihat detail audit.
     *
     * Aturannya sama dengan panel riwayat dokumen: IP adalah data pribadi, dan
     * halaman ini bukan pengecualian hanya karena yang membukanya superadmin.
     */
    public function testAlamatIpDisembunyikanTanpaHakDetail(): void
    {
        $r = $this->aksi('audit');

        self::assertFalse($r['json']['detail']);
        self::assertArrayNotHasKey('ip_address', $r['json']['baris'][0]);
        self::assertStringNotContainsString('10.0.0.7', $r['keluaran']);
    }

    public function testDaftarAksesMenandaiTemplateTanpaAturan(): void
    {
        $daftar = $this->aksi('akses')['json']['daftar'];
        $per = [];
        foreach ($daftar as $t) {
            $per[$t['nama']] = $t;
        }

        self::assertTrue($per['Uji']['bawaan']);
        self::assertFalse($per['Dengan aturan']['bawaan']);
        self::assertSame('perawat', $per['Dengan aturan']['akses']['edit']);
    }

    // ── Tugas yang menulis ───────────────────────────────────────────────

    public function testGambarInlineDipindahkanKeObjek(): void
    {
        $r = $this->aksi('inline', '1');

        self::assertSame(1, $r['json']['batch']['dipindah']);
        self::assertSame(1, $r['json']['batch']['dokumen']);
        self::assertFalse($r['hasil']['db_sama'], 'dokumennya memang ditulis ulang');
    }

    public function testMelihatDuluPemindahanInlineTidakMengubahApaPun(): void
    {
        $r = $this->aksi('inline', '0');

        self::assertSame(1, $r['json']['batch']['dipindah'], 'dihitung saja');
        self::assertTrue($r['hasil']['db_sama']);
    }

    /** Menghapus byte butuh persetujuan yang DIKETIK, bukan sekadar tombol. */
    public function testPenghapusanDitolakTanpaKataKonfirmasi(): void
    {
        $r = $this->aksi('hapus_yatim', '0', 'superadmin', 'ya');

        self::assertFalse($r['json']['ok']);
        self::assertStringContainsString('DELETE', (string) $r['json']['pesan']);
        self::assertTrue($r['hasil']['db_sama'], 'tidak ada yang terhapus');
    }

    public function testPenghapusanHanyaMengenaiYangTakDirujuk(): void
    {
        $this->aksi('salin', '1');
        $r = $this->aksi('hapus_yatim', '0', 'superadmin', 'DELETE');

        self::assertSame(1, $r['json']['terhapus']);
        self::assertNotContains('yatim', $r['hasil']['berkas'], 'salinan berkasnya ikut hilang');
        self::assertContains('ttd-a', $r['hasil']['berkas']);
        self::assertContains('%PDF-1.7 beku', $r['hasil']['berkas'], 'arsip beku tidak boleh tersentuh');
    }

    /**
     * Uji adapter menjawab dengan kalimat, bukan diam.
     *
     * "Kelasnya ada" bukan "ia bekerja": perender yang kehilangan fontnya baru
     * ketahuan saat dokumen sungguhan dicetak. Di lingkungan uji ini memang
     * tidak ada perender, dan yang dijaga adalah jawabannya menyebut sebabnya.
     */
    public function testUjiAdapterMenyebutkanSebabKegagalan(): void
    {
        $j = $this->aksi('uji_adapter', '0', 'superadmin', 'pdf')['json'];

        self::assertFalse($j['ok']);
        self::assertStringContainsString('PDF renderer', (string) $j['pesan']);
    }

    /**
     * Formulir pembuat config dimulai dari yang sedang berlaku.
     *
     * Berkas config yang dibuat dari halaman ini hampir selalu perubahan kecil
     * atas yang sudah ada; formulir kosong memaksa orang mengisi ulang hal yang
     * sudah benar, dan di situlah setelan hilang tanpa ada yang sadar.
     */
    public function testFormulirConfigMengikutiNilaiAktif(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/migrate.php');

        $petik = static function (string $k): string { return "'" . $k . "'"; };
        foreach ([
            ['locAktif', 'id'],
            ['docAktif', 'id'],
            ['backendAktif', 'mirror'],
        ] as [$nilai, $opsi]) {
            $potongan = sprintf(
                'value="%s" <?= $%s === %s ? %s : %s ?>',
                $opsi, $nilai, $petik($opsi), $petik('selected'), "''"
            );
            self::assertStringContainsString($potongan, $sumber, 'pilihan tidak mengikuti nilai aktif');
        }
    }

    /**
     * Penghapusan dipisah dari tugas lain, dan dijaga dua lapis.
     *
     * Awalnya penghapusan sengaja hanya ada di CLI. Ternyata CLI tidak bisa
     * dijalankan di server pemakai — berkas bootstrap aplikasinya ditulis untuk
     * permintaan web — jadi pilihannya tinggal: lewat halaman ini, atau tidak
     * sama sekali. Yang dijaga: gerbangnya ada di server, bukan cuma di tampilan.
     */
    public function testPenghapusanDijagaDiSisiServer(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/migrate.php');

        self::assertStringContainsString("if ((\$_POST['konfirmasi'] ?? '') !== 'DELETE') {", $sumber,
            'penjaganya harus di server, bukan hanya di formulirnya');
        self::assertStringContainsString('id="ezHapusKonfirmasi"', $sumber);
        self::assertStringContainsString("ezdoc_audit_log('blob.purged'", $sumber,
            'menghapus byte harus tercatat');
    }

}
