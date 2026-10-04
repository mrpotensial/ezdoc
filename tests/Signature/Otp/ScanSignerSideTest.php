<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Signature\Otp\OtpChallengeRepository;
use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Otp\ScanOtpService;
use Ezdoc\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Sisi penanda tangan: kanal kedua, permintaan lewat aplikasi, dan riwayat.
 *
 * Jalur pindai yang lama diuji di {@see ScanOtpServiceTest}. Yang di sini
 * adalah tambahan untuk portal penanda tangan — dan yang paling dijaga justru
 * yang TIDAK boleh berubah: pemanggil lama dengan argumen lama harus
 * berperilaku persis seperti sebelumnya, karena penangan WhatsApp produksi
 * memakai pustaka yang sama.
 *
 * SQL-nya sungguhan (SQLite dari migrasi), bukan tiruan — riwayat dan
 * permintaan tertunda bergantung pada LIKE dan JOIN yang benar-benar dikirim.
 */
final class ScanSignerSideTest extends TestCase
{
    private const START = '2026-10-04 09:00:00';
    private const PHONE = '6281234567890';

    private PdoConnection $db;
    private FrozenClock $clock;
    private OtpChallengeRepository $repo;
    private ScanOtpService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrasi = dirname(__DIR__, 3) . '/migrations/sqlite/';
        foreach ([
            '2026_01_01_000001_create_ezdoc_templates.sql',
            '2026_01_01_000002_create_ezdoc_documents.sql',
            '2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql',
        ] as $berkas) {
            $pdo->exec((string) file_get_contents($migrasi . $berkas));
        }

        $this->db = new PdoConnection($pdo);
        $this->clock = new FrozenClock(self::START);
        $this->repo = new OtpChallengeRepository($this->db, $this->clock);
        $this->service = new ScanOtpService($this->repo, $this->clock);

        // Dokumen menunjuk templatenya lewat kunci asing.
        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, name) VALUES (7, 'tpl-7', 'Persetujuan Tindakan')"
        );
    }

    /** Satu dokumen milik satu subjek. */
    private function document(int $id, string $subjectId, string $subjectType = 'patient'): void
    {
        $this->db->execute(
            'INSERT INTO ezdoc_documents (id, uuid, template_id, subject_type, subject_id, norm, created_at)
             VALUES (?, ?, 7, ?, ?, ?, ?)',
            [$id, 'uuid-' . $id, $subjectType, $subjectId, $subjectId, self::START]
        );
    }

    /** @return array<string,mixed> */
    private function meta(string $scanToken): array
    {
        $row = $this->db->fetchOne(
            'SELECT provider_meta FROM ezdoc_ttd_otp_challenges WHERE scan_token = ?',
            [$scanToken]
        );
        self::assertNotNull($row);
        $meta = json_decode((string) $row['provider_meta'], true);
        self::assertIsArray($meta);

        return $meta;
    }

    // ─── Pemanggil lama tidak berubah ───

    /** Argumen lama → masa berlaku QR lama dan `route` lama. */
    public function testTheOldCallStillMakesAFiveMinuteScanToken(): void
    {
        $issued = $this->service->issue(7, 'ttd1', 42);

        self::assertSame('2026-10-04 09:05:00', $issued['expires_at']);
        self::assertSame(['route' => 'scan'], $this->meta($issued['scan_token']));
    }

    /** Pindaian tanpa metadata tidak menulis apa pun ke `provider_meta`. */
    public function testFulfillingWithoutMetaLeavesTheMetaAlone(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan');

        self::assertSame(['route' => 'scan'], $this->meta($token));
    }

    // ─── Metadata saat pindaian ───

    public function testTheScannersChannelIsRecorded(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan', ['channel' => 'web', 'signer_ref' => 'akun:1']);

        self::assertSame(
            ['route' => 'scan', 'channel' => 'web', 'signer_ref' => 'akun:1'],
            $this->meta($token)
        );
    }

    /**
     * `route` ditulis saat terbit dan tidak bisa ditimpa pemindai.
     *
     * Kalau bisa, permintaan yang lahir dari QR petugas bisa mengaku dikirim ke
     * aplikasi — dan riwayatnya tidak lagi menjawab "lewat jalan apa".
     */
    public function testTheRouteCannotBeRewrittenByTheScanner(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan', ['route' => 'app']);

        self::assertSame('scan', $this->meta($token)['route']);
    }

    /** Yang kalah balapan tidak meninggalkan metadatanya di baris pemenang. */
    public function testTheLoserOfTheRaceLeavesNoMeta(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan', ['channel' => 'wa']);
        $kalah = $this->service->fulfil($token, '6289999999999', 'pesan', ['channel' => 'web']);

        self::assertFalse($kalah['ok']);
        self::assertSame('wa', $this->meta($token)['channel']);
    }

    // ─── Permintaan lewat aplikasi ───

    public function testAnAppRequestLivesADay(): void
    {
        $issued = $this->service->issue(7, 'ttd1', 42, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);

        self::assertSame('2026-10-05 09:00:00', $issued['expires_at']);
        self::assertSame('app', $this->meta($issued['scan_token'])['route']);
    }

    /** Masa berlaku yang diminta dijepit — token tidak boleh hidup selamanya. */
    public function testTheRequestedLifetimeIsClamped(): void
    {
        $lama = $this->service->issue(7, 'ttd1', 42, 999999999);
        $singkat = $this->service->issue(7, 'ttd2', 42, 1);

        self::assertSame('2026-10-11 09:00:00', $lama['expires_at']);
        self::assertSame('2026-10-04 09:01:00', $singkat['expires_at']);
    }

    public function testPendingAppRequestsAreFoundBySubject(): void
    {
        $this->document(42, 'RM-001');
        $issued = $this->service->issue(7, 'ttd1', 42, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);

        $pending = $this->repo->findPendingForSubject('patient', 'RM-001', 'app');

        self::assertCount(1, $pending);
        self::assertSame($issued['scan_token'], $pending[0]['scan_token']);
    }

    /**
     * QR di layar petugas TIDAK muncul di aplikasi.
     *
     * Token itu untuk orang yang sedang berdiri di depan layar. Kalau ia ikut
     * tampil, siapa pun yang tertaut ke pasien itu bisa memakainya dari rumah.
     */
    public function testAnOnScreenQrIsNotOfferedInTheApp(): void
    {
        $this->document(42, 'RM-001');
        $this->service->issue(7, 'ttd1', 42);

        self::assertSame([], $this->repo->findPendingForSubject('patient', 'RM-001', 'app'));
    }

    public function testAnotherSubjectsRequestsStayHidden(): void
    {
        $this->document(42, 'RM-001');
        $this->document(43, 'RM-002');
        $this->service->issue(7, 'ttd1', 43, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);

        self::assertSame([], $this->repo->findPendingForSubject('patient', 'RM-001', 'app'));
    }

    public function testAnOpenedOrExpiredRequestIsNoLongerPending(): void
    {
        $this->document(42, 'RM-001');
        $dibuka = $this->service->issue(7, 'ttd1', 42, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);
        $this->service->issue(7, 'ttd2', 42, 3600, ['route' => 'app']);

        $this->service->fulfil($dibuka['scan_token'], self::PHONE, 'pesan');
        $this->clock->advance(3601);

        self::assertSame([], $this->repo->findPendingForSubject('patient', 'RM-001', 'app'));
    }

    public function testADeletedDocumentsRequestIsHidden(): void
    {
        $this->document(42, 'RM-001');
        $this->service->issue(7, 'ttd1', 42, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);
        $this->db->execute('UPDATE ezdoc_documents SET deleted_at = ? WHERE id = 42', [self::START]);

        self::assertSame([], $this->repo->findPendingForSubject('patient', 'RM-001', 'app'));
    }

    /** Menekan "kirim" dua kali tidak meninggalkan dua permintaan hidup. */
    public function testSendingAgainRetiresTheEarlierRequest(): void
    {
        $this->document(42, 'RM-001');
        $lama = $this->service->issue(7, 'ttd1', 42, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);

        $mati = $this->repo->expirePendingForSlot(42, 'ttd1', 'app');
        $baru = $this->service->issue(7, 'ttd1', 42, ScanOtpService::APP_TTL_SECONDS, ['route' => 'app']);

        self::assertSame(1, $mati);
        $pending = $this->repo->findPendingForSubject('patient', 'RM-001', 'app');
        self::assertCount(1, $pending);
        self::assertSame($baru['scan_token'], $pending[0]['scan_token']);
        self::assertSame(ScanOtpService::EXPIRED, $this->service->resolve($lama['scan_token'])['status']);
    }

    /** Pematian itu tidak menyentuh QR di layar untuk slot yang sama. */
    public function testRetiringAppRequestsLeavesTheOnScreenQrAlone(): void
    {
        $qr = $this->service->issue(7, 'ttd1', 42);

        self::assertSame(0, $this->repo->expirePendingForSlot(42, 'ttd1', 'app'));
        self::assertSame(ScanOtpService::OK, $this->service->resolve($qr['scan_token'])['status']);
    }

    // ─── Riwayat ───

    public function testHistoryFindsOnlyThatSigner(): void
    {
        $milikku = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $milikLain = $this->service->issue(7, 'ttd2', 42)['scan_token'];
        $this->service->fulfil($milikku, self::PHONE, 'pesan', ['signer_ref' => 'akun:12']);
        $this->service->fulfil($milikLain, self::PHONE, 'pesan', ['signer_ref' => 'akun:123']);

        $riwayat = $this->repo->findBySignerRef('akun:12');

        self::assertCount(1, $riwayat, '"akun:12" ikut menemukan "akun:123"');
        self::assertSame($milikku, $riwayat[0]['scan_token']);
        self::assertSame('akun:12', $riwayat[0]['provider_meta']['signer_ref']);
    }

    /** Karakter LIKE di pengenal tidak menjadi pola. */
    public function testLikeCharactersInTheReferenceAreLiteral(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan', ['signer_ref' => 'akun_1']);

        self::assertSame([], $this->repo->findBySignerRef('akun%'));
        self::assertSame([], $this->repo->findBySignerRef('akunx1'));
        self::assertCount(1, $this->repo->findBySignerRef('akun_1'));
    }

    public function testHistoryIsNewestFirstAndLimited(): void
    {
        foreach (['ttd1', 'ttd2', 'ttd3'] as $slot) {
            $token = $this->service->issue(7, $slot, 42)['scan_token'];
            $this->service->fulfil($token, self::PHONE, 'pesan', ['signer_ref' => 'akun:1']);
            $this->clock->advance(60);
        }

        $riwayat = $this->repo->findBySignerRef('akun:1', 2);

        self::assertCount(2, $riwayat);
        self::assertSame('ttd3', $riwayat[0]['ttd_id']);
        self::assertSame('ttd2', $riwayat[1]['ttd_id']);
    }

    // ─── Layar tunggu ───

    public function testProgressFollowsTheSignature(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        self::assertSame(ScanOtpService::OPEN, $this->service->progress($token));

        $hasil = $this->service->fulfil($token, self::PHONE, 'pesan');
        self::assertSame(ScanOtpService::WAITING, $this->service->progress($token));

        $row = $this->db->fetchOne('SELECT challenge_id FROM ezdoc_ttd_otp_challenges WHERE scan_token = ?', [$token]);
        $verdict = (new OtpService($this->repo, null, $this->clock))->verify((string) $row['challenge_id'], $hasil['code']);
        self::assertSame(ScanOtpService::VERIFIED, $this->service->progress($token));

        self::assertTrue($this->repo->consumeSignToken((string) $verdict->getSignToken(), 'ttd1', 7, 42));
        self::assertSame(ScanOtpService::SIGNED, $this->service->progress($token));
    }

    /** Yang sudah tersimpan tetap tersimpan, walau tenggat kodenya lewat sesudahnya. */
    public function testASignedTokenStaysSignedPastItsDeadline(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $hasil = $this->service->fulfil($token, self::PHONE, 'pesan');
        $row = $this->db->fetchOne('SELECT challenge_id FROM ezdoc_ttd_otp_challenges WHERE scan_token = ?', [$token]);
        $verdict = (new OtpService($this->repo, null, $this->clock))->verify((string) $row['challenge_id'], $hasil['code']);
        $this->repo->consumeSignToken((string) $verdict->getSignToken(), 'ttd1', 7, 42);

        $this->clock->advance(86400);

        self::assertSame(ScanOtpService::SIGNED, $this->service->progress($token));
    }

    public function testProgressReportsExpiryAndUnknownTokens(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan');
        $this->clock->advance(ScanOtpService::CODE_TTL_SECONDS + 1);

        self::assertSame(ScanOtpService::EXPIRED, $this->service->progress($token));
        self::assertSame(ScanOtpService::NOT_FOUND, $this->service->progress('tidak-ada'));
    }

    /** Tebakan salah yang habis berarti kodenya mati, walau tenggatnya belum lewat. */
    public function testExhaustedAttemptsReadAsExpired(): void
    {
        $token = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $this->service->fulfil($token, self::PHONE, 'pesan');
        $this->db->execute('UPDATE ezdoc_ttd_otp_challenges SET attempts = max_attempts WHERE scan_token = ?', [$token]);

        self::assertSame(ScanOtpService::EXPIRED, $this->service->progress($token));
    }

    public function testSecondsLeftNeverGoesNegative(): void
    {
        $issued = $this->service->issue(7, 'ttd1', 42);
        $row = $this->service->resolve($issued['scan_token'])['challenge'];
        self::assertIsArray($row);

        self::assertSame(300, $this->service->secondsLeft($row));
        $this->clock->advance(500);
        self::assertSame(0, $this->service->secondsLeft($row));
    }
}
