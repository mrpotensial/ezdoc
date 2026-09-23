<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Admin;

use Ezdoc\Admin\Diagnostics;
use Ezdoc\Db\Pdo\PdoConnection;
use PHPUnit\Framework\TestCase;

/**
 * Angka kesehatan pemasangan, dan daftar yang menjelaskannya.
 *
 * Diminta pemakai untuk halaman pengaturan. Tiga pertanyaan yang sebelumnya
 * hanya bisa dijawab dengan membuka basis data: tanda tangan yang tidak lagi
 * cocok, OTP yang gagal, dan dokumen bertanda tangan tanpa arsip tampilan.
 */
final class DiagnosticsTest extends TestCase
{
    private \PDO $pdo;
    private PdoConnection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ([
            '2026_01_01_000001_create_ezdoc_templates.sql',
            '2026_01_01_000002_create_ezdoc_documents.sql',
            '2026_01_01_000004_create_ezdoc_audit_log.sql',
            '2026_01_01_000005_create_ezdoc_signatures.sql',
            '2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql',
            '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
        ] as $f) {
            $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/sqlite/' . $f));
        }
        $this->db = new PdoConnection($this->pdo);
    }

    private function diag(): Diagnostics
    {
        return new Diagnostics($this->db);
    }

    private function template(int $id, string $nama, ?string $akses = null): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active, access_config)
             VALUES (?, ?, ?, 1, 1, ?, 'umum', 'patient', '<p>x</p>', 1, ?)",
            [$id, 'tpl-' . $id, 'uji-' . $id, $nama, $akses]
        );
    }

    private function dokumen(int $id, bool $terkunci = false, bool $tersembunyi = false): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                          norm, nopen, subject_id, context_id, label, version, is_locked, deleted_at)
             VALUES (?, ?, 1, 'tpl-1', 1, 'Uji', ?, '11', ?, '11', '-', 1, ?, ?)",
            [$id, 'dok-' . $id, (string) $id, (string) $id, $terkunci ? 1 : 0, $tersembunyi ? '2026-01-01 00:00:00' : null]
        );
    }

    private function tandaTangan(int $docId, string $status): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_signatures (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                                           envelope_format, envelope, content_hash, signed_at, verify_status, metadata)
             VALUES (?, ?, 1, '7', 'hmac', 1, 'hmac', 'e', 'h', '2026-09-01 10:00:00', ?, '{}')",
            ['sig-' . $docId . '-' . $status, $docId, $status]
        );
    }

    private function audit(string $jenis, string $hasil, string $pelaku = '7'): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_audit_log (event_type, event_uuid, actor_id, result, occurred_at)
             VALUES (?, ?, ?, ?, datetime('now', '-1 day'))",
            [$jenis, 'ev-' . bin2hex(random_bytes(4)), $pelaku, $hasil]
        );
    }

    // ── Kesehatan ────────────────────────────────────────────────────────

    public function testTandaTanganBermasalahDihitungTerpisah(): void
    {
        $this->template(1, 'Uji');
        $this->dokumen(1);
        $this->tandaTangan(1, 'valid');
        $this->tandaTangan(1, 'tampered');

        $h = $this->diag()->health()['tanda_tangan'];

        self::assertSame(2, $h['total']);
        self::assertSame(1, $h['bermasalah'], 'apa pun selain valid harus terlihat');
        self::assertEqualsCanonicalizing(['valid' => 1, 'tampered' => 1], $h['per_status']);
    }

    /**
     * Dokumen bertanda tangan tanpa arsip tampilan.
     *
     * Di pemasangan pemakai angkanya 1.069 — dokumen yang dibuat sebelum
     * pembekuan aktif. Angka ini untuk dijelaskan, bukan untuk "diperbaiki":
     * membekukannya sekarang akan menyimpan tampilan hari ini.
     */
    public function testDokumenBertandaTanganTanpaArsipDihitung(): void
    {
        $this->template(1, 'Uji');
        $this->dokumen(1);
        $this->dokumen(2);
        $this->tandaTangan(1, 'valid');
        $this->tandaTangan(2, 'valid');
        $this->db->execute(
            "INSERT INTO ezdoc_document_artifacts (uuid, document_id, cycle, blob_key, frozen_at)
             VALUES ('art-1', 1, 1, ?, '2026-09-21 10:00:00')",
            [str_repeat('a', 64)]
        );

        $d = $this->diag()->health()['dokumen'];

        self::assertSame(2, $d['bertanda_tangan']);
        self::assertSame(1, $d['tanpa_arsip']);
    }

    public function testDokumenTerkunciDanTersembunyiDihitung(): void
    {
        $this->template(1, 'Uji');
        $this->dokumen(1, true);
        $this->dokumen(2, false, true);
        $this->dokumen(3);

        $d = $this->diag()->health()['dokumen'];

        self::assertSame(3, $d['total']);
        self::assertSame(1, $d['terkunci']);
        self::assertSame(1, $d['tersembunyi']);
    }

    public function testOtpDihitungDariTantanganDanLogGagal(): void
    {
        foreach ([['2026-09-22 10:00:00', null], [null, null]] as $i => [$verified, $x]) {
            $this->db->execute(
                "INSERT INTO ezdoc_ttd_otp_challenges (challenge_id, doc_id, template_id, ttd_id, phone, otp_hash, expires_at, verified_at, created_at)
                 VALUES (?, 1, 1, 'ttd_a', '0811', 'h', datetime('now', '-1 hour'), ?, datetime('now', '-2 hours'))",
                ['otp-' . $i, $verified]
            );
        }
        $this->audit('otp.verify_failed', 'success');
        $this->audit('otp.verify_failed', 'success');

        $o = $this->diag()->health()['otp'];

        self::assertSame(2, $o['total']);
        self::assertSame(1, $o['terverifikasi']);
        self::assertSame(1, $o['kedaluwarsa'], 'belum diverifikasi dan waktunya lewat');
        self::assertSame(2, $o['gagal']);
    }

    /** Hanya `success` yang dianggap baik; `denied` dan `error` ikut terhitung. */
    public function testKejadianTidakBerhasilDihitung(): void
    {
        $this->audit('doc.updated', 'success');
        $this->audit('authz.denied', 'denied');
        $this->audit('doc.freeze_failed', 'error');

        $a = $this->diag()->health()['audit'];

        self::assertSame(2, $a['gagal']);
        self::assertArrayHasKey('authz.denied · denied', $a['per_jenis']);
        self::assertArrayNotHasKey('doc.updated · success', $a['per_jenis']);
    }

    // ── Riwayat kejadian ─────────────────────────────────────────────────

    public function testKejadianTerbaruLebihDuluDanBisaDisaring(): void
    {
        $this->audit('doc.created', 'success', '7');
        $this->audit('authz.denied', 'denied', '9');

        $semua = $this->diag()->recentEvents();
        self::assertSame('authz.denied', $semua[0]['event_type'], 'yang terbaru di depan');

        $saring = $this->diag()->recentEvents(['result' => 'denied']);
        self::assertCount(1, $saring);
        self::assertSame('9', (string) $saring[0]['actor_id']);

        self::assertCount(1, $this->diag()->recentEvents(['actor' => '7']));
        self::assertSame([], $this->diag()->recentEvents(['event_type' => 'tidak.ada']));
    }

    public function testJumlahBarisDibatasiDanBisaDigeser(): void
    {
        foreach (range(1, 5) as $i) {
            $this->audit('doc.updated', 'success');
        }

        self::assertCount(2, $this->diag()->recentEvents([], 2));
        self::assertCount(3, $this->diag()->recentEvents([], 3, 2));
        self::assertCount(5, $this->diag()->recentEvents([], 999), 'batas atas tidak menolak permintaan wajar');
    }

    public function testJenisKejadianDiambilDariData(): void
    {
        $this->audit('doc.created', 'success');
        $this->audit('otp.verified', 'success');
        $this->audit('doc.created', 'success');

        self::assertSame(['doc.created', 'otp.verified'], $this->diag()->eventTypes());
    }

    // ── Hak akses template ───────────────────────────────────────────────

    /**
     * Template tanpa access_config ditandai memakai aturan bawaan.
     *
     * Di pemasangan pemakai 18 dari 19 template seperti itu — keadaan yang
     * paling sering disalahpahami sebagai "sudah dibatasi".
     */
    public function testTemplateTanpaPengaturanDitandaiBawaan(): void
    {
        $this->template(1, 'Tanpa aturan');
        $this->template(2, 'Dengan aturan', json_encode([
            'mode' => 'strict',
            'edit' => ['roles' => ['perawat', 'dokter'], 'users' => [12]],
            'view' => ['roles' => [], 'users' => []],
        ]));

        $daftar = $this->diag()->templateAccess();

        self::assertTrue($daftar[1]['bawaan'], 'template tanpa aturan');
        self::assertFalse($daftar[0]['bawaan']);
        self::assertSame('perawat, dokter, #12', $daftar[0]['akses']['edit']);
        self::assertArrayNotHasKey('view', $daftar[0]['akses'], 'aturan kosong bukan aturan');
        self::assertSame('strict', $daftar[0]['mode']);
    }

    /** Hanya versi yang berlaku — daftar template bukan daftar versi. */
    public function testHanyaVersiBerlakuYangDitampilkan(): void
    {
        $this->template(1, 'Uji');
        $this->db->execute("UPDATE ezdoc_templates SET is_current = 0 WHERE id = 1");
        $this->template(2, 'Uji v2');

        $daftar = $this->diag()->templateAccess();

        self::assertCount(1, $daftar);
        self::assertSame(2, $daftar[0]['id']);
    }
}
