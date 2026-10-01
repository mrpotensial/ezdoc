<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use PHPUnit\Framework\TestCase;

/**
 * Riwayat dokumen menampilkan arsip tampilan dan nomor penerima OTP.
 *
 * Dilaporkan dari pemakaian, pada dokumen yang sudah punya dua siklus arsip:
 * "lihat arsipnya di mana? di log pun tidak ada". Arsipnya ada — tapi tidak ada
 * satu pun jalan untuk melihatnya, dan riwayat tidak menyebutnya.
 *
 * Nomor HP: record tanda tangan tidak menyimpannya, tantangan OTP-nya yang
 * menyimpan. Keduanya dikaitkan lewat slot. Nomor itu data pribadi, jadi ia
 * mengikuti aturan yang sama dengan IP dan role di panel ini — lengkap hanya
 * untuk yang berhak melihat detail audit, sisanya disamarkan sampai empat angka
 * terakhir.
 *
 * Action-nya mengakhiri permintaan dengan `exit`, jadi dijalankan di proses
 * terpisah lewat tests/fixtures/run_history.php dan jawabannya dibaca
 * sebagai JSON — ujung ke ujung, bukan pemeriksaan sumber.
 */
final class HistoryArchiveOtpTest extends TestCase
{
    /** @return array<string,mixed> */
    private function riwayat(string $peran): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        $keluaran = (string) shell_exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_history.php'),
            escapeshellarg($peran)
        ));

        $json = json_decode($keluaran, true);
        self::assertIsArray($json, "jawaban action bukan JSON:\n" . substr($keluaran, 0, 800));
        self::assertTrue($json['success'] ?? false, 'action menolak: ' . substr($keluaran, 0, 400));

        return $json;
    }

    /** @return list<array<string,mixed>> */
    private function kejadian(array $json, string $jenis): array
    {
        return array_values(array_filter(
            $json['entries'],
            static fn (array $e): bool => $e['event'] === $jenis
        ));
    }

    public function testArsipTampilanMunculDiRiwayat(): void
    {
        $arsip = $this->kejadian($this->riwayat('superadmin'), 'doc.frozen');

        self::assertCount(2, $arsip, 'kedua siklus harus tampil, termasuk yang sudah digantikan');
    }

    /**
     * Siklus yang digantikan tetap tampil, dan ditandai.
     *
     * Ia jawaban atas "apa yang dilihat penanda tangan pertama" — pertanyaan
     * yang muncul justru ketika siklusnya sudah tidak berlaku.
     */
    public function testSiklusLamaDitandaiDigantikan(): void
    {
        $perSiklus = [];
        foreach ($this->kejadian($this->riwayat('superadmin'), 'doc.frozen') as $e) {
            $perSiklus[$e['detail']['cycle']] = $e['result'];
        }

        self::assertSame('superseded', $perSiklus[1] ?? null);
        self::assertSame('ok', $perSiklus[2] ?? null);
    }

    public function testSuperadminMelihatNomorLengkap(): void
    {
        $json = $this->riwayat('superadmin');
        $ttd  = $this->ttdPerSlot($json);

        self::assertTrue($json['audit_detail']);
        self::assertSame('081248664458', $ttd['Slot ttd_b']);
    }

    /** Yang tidak berhak melihat detail audit mendapat empat angka terakhir saja. */
    public function testUserBiasaMelihatNomorTersamar(): void
    {
        $json = $this->riwayat('biasa');
        $ttd  = $this->ttdPerSlot($json);

        self::assertFalse($json['audit_detail']);
        self::assertSame('********4458', $ttd['Slot ttd_b']);
        self::assertStringNotContainsString('0812', (string) json_encode($json), 'nomor lengkap bocor ke jawaban');
    }

    /**
     * Yang dikaitkan tantangan yang TERVERIFIKASI, bukan yang gagal.
     *
     * Slot yang sama punya tantangan gagal ke nomor lain sebelumnya. Menampilkan
     * nomor itu berarti riwayat menyebut orang yang salah sebagai penerima kode
     * yang dipakai menandatangani.
     */
    public function testTantanganGagalTidakDipakai(): void
    {
        $json = $this->riwayat('superadmin');

        self::assertStringNotContainsString('081299990000', (string) json_encode($json));
    }

    public function testTandaTanganTanpaOtpTidakBernomor(): void
    {
        $ttd = $this->ttdPerSlot($this->riwayat('superadmin'));

        self::assertNull($ttd['Slot ttd_a'], 'tanda tangan tanpa OTP diberi nomor yang tidak ada');
    }

    // ─── Aksi pembuka arsip ───

    /**
     * Template dikaitkan lewat template_id, bukan template_uuid.
     *
     * Versi-versi sebuah template berbagi uuid; join lewat uuid bisa memilih
     * access_config versi yang salah, dan itu celah izin, bukan salah tampil.
     */
    public function testAksiArsipMemeriksaIzinDenganTemplateYangBenar(): void
    {
        $kode = $this->tanpaKomentar('actions/document/artifact.php');

        self::assertStringContainsString('ON t.id = d.template_id', $kode);
        self::assertStringNotContainsString('t.uuid = d.template_uuid', $kode);
        self::assertMatchesRegularExpression('/ezdoc_require_on_template\([\s\S]*?\'view\'/', $kode);
    }

    public function testAksiArsipTerdaftarDiDispatcher(): void
    {
        self::assertStringContainsString(
            "'artifact'        => 'artifact.php',",
            (string) file_get_contents(dirname(__DIR__, 2) . '/actions/_dispatcher.php')
        );
    }

    /** Kegagalan beku tercatat ke riwayat, bukan cuma ke error_log. */
    public function testKegagalanBekuMasukLogAudit(): void
    {
        self::assertStringContainsString(
            "ezdoc_audit_log('doc.freeze_failed'",
            $this->tanpaKomentar('actions/document/save_document.php')
        );
    }

    /** @return array<string,?string> label slot => nomor OTP */
    private function ttdPerSlot(array $json): array
    {
        $hasil = [];
        foreach ($this->kejadian($json, 'signature.recorded') as $e) {
            $hasil[$e['detail']['ttd_label']] = $e['detail']['otp_phone'] ?? null;
        }

        return $hasil;
    }

    private function tanpaKomentar(string $rel): string
    {
        $kode = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        return $kode;
    }
}
