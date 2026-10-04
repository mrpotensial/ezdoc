<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\Otp\OtpChallengeRepository;
use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Otp\ScanOtpService;
use Ezdoc\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Kode yang dikirim ke alamat email — dan ditampilkan juga di aplikasi
 * penanda tangan untuk alamat yang sama.
 *
 * Yang paling dijaga: kode di aplikasi SAMA dengan kode di surat tanpa ada
 * salinan polosnya di basis data, dan aplikasi hanya membukanya untuk alamat
 * tujuannya.
 *
 * SQL-nya sungguhan (SQLite dari migrasi), seperti {@see ScanSignerSideTest}.
 */
final class ScanEmailDeliveryTest extends TestCase
{
    private const START = '2026-10-04 09:00:00';
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const EMAIL = 'budi.santoso.panjang.sekali@gmail.com';

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
        $this->service = new ScanOtpService($this->repo, $this->clock, self::KEY);

        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, name) VALUES (7, 'tpl-7', 'Persetujuan Tindakan')"
        );
    }

    /** @return array<string,mixed> */
    private function row(string $challengeId): array
    {
        $row = $this->repo->findById($challengeId);
        self::assertNotNull($row);

        return $row;
    }

    private function later(int $seconds): void
    {
        $this->clock = new FrozenClock(date('Y-m-d H:i:s', strtotime(self::START) + $seconds));
        $this->repo = new OtpChallengeRepository($this->db, $this->clock);
        $this->service = new ScanOtpService($this->repo, $this->clock, self::KEY);
    }

    // ─── Terbit ───

    public function testTheCodeIsBornAtIssueAndOnlyItsHashIsStored(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        $row = $this->row($issued['challenge_id']);

        self::assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        self::assertTrue(password_verify($issued['code'], (string) $row['otp_hash']));
        self::assertStringNotContainsString($issued['code'], json_encode($row));
        self::assertNotEmpty($row['scanned_at'], 'lahir berstatus dipindai — layar petugas langsung ke kolom kode');
        self::assertSame('2026-10-04 09:30:00', $issued['expires_at']);
    }

    /** Kolom `phone` VARCHAR(30): alamatnya tidak masuk ke sana. */
    public function testTheAddressLivesInTheMetaNotInThePhoneColumn(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, '  Budi.Santoso.Panjang.Sekali@GMAIL.com ');
        $row = $this->row($issued['challenge_id']);
        // findById() sudah mengurai provider_meta.
        self::assertSame(['route' => 'email', 'recipient' => self::EMAIL], $row['provider_meta']);
        self::assertSame(30, strlen((string) $row['phone']));
        self::assertSame(ScanOtpService::recipientKey(self::EMAIL), $row['phone']);
        self::assertStringNotContainsString('budi', (string) $row['phone']);
    }

    public function testANonEmailIsRefused(): void
    {
        $this->expectException(EzdocException::class);
        $this->service->issueToRecipient(7, 'ttd1', 42, '081234567890');
    }

    public function testNoKeyMeansNoDeliveredCode(): void
    {
        // Tanpa kunci di konstruktor, kuncinya dibaca dari EZDOC_HMAC_SECRET.
        if (\Ezdoc\Signature\SigningKey::isConfigured()) {
            self::markTestSkipped('EZDOC_HMAC_SECRET is defined in this process.');
        }

        $this->expectException(EzdocException::class);
        (new ScanOtpService($this->repo, $this->clock))->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
    }

    /** Kode di surat diverifikasi lewat jalur yang sudah ada, tanpa perubahan. */
    public function testTheExistingVerifierAcceptsTheCode(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);

        $otp = new OtpService($this->repo, null, $this->clock);
        $hasil = $otp->verify($issued['challenge_id'], $issued['code']);

        self::assertTrue($hasil->isSuccess());
    }

    // ─── Kode turunan ───

    public function testTheAppShowsTheSameCodeAsTheEmail(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        $dibuka = $this->service->openDelivered($issued['scan_token'], self::EMAIL);

        self::assertSame(ScanOtpService::WAITING, $dibuka['status']);
        self::assertSame($issued['code'], $dibuka['code'] ?? null);
        self::assertSame(1800, $dibuka['seconds_left'] ?? null);
    }

    public function testEachChallengeGetsItsOwnCode(): void
    {
        $kode = [];
        for ($i = 0; $i < 20; $i++) {
            $kode[] = $this->service->issueToRecipient(7, 'ttd' . $i, 42, self::EMAIL)['code'];
        }

        self::assertGreaterThan(15, count(array_unique($kode)));
    }

    /** Kunci yang berganti sejak surat dikirim: angka keliru lebih buruk dari tidak ada angka. */
    public function testARotatedKeyShowsNoCodeRatherThanAWrongOne(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);

        $lain = new ScanOtpService($this->repo, $this->clock, str_repeat('z', 32));
        $dibuka = $lain->openDelivered($issued['scan_token'], self::EMAIL);

        self::assertSame(ScanOtpService::EXPIRED, $dibuka['status']);
        self::assertArrayNotHasKey('code', $dibuka);
        self::assertNull($lain->deliveredCode($this->row($issued['challenge_id'])));
    }

    // ─── Siapa boleh membuka ───

    public function testTheAddressIsComparedAfterNormalising(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);

        $dibuka = $this->service->openDelivered($issued['scan_token'], ' BUDI.Santoso.Panjang.Sekali@Gmail.Com');
        self::assertSame($issued['code'], $dibuka['code'] ?? null);
    }

    public function testAnotherAddressIsTurnedAwayWithAMaskedHint(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);

        foreach (['orang.lain@gmail.com', ''] as $penanya) {
            $dibuka = $this->service->openDelivered($issued['scan_token'], $penanya);

            self::assertSame(ScanOtpService::FORBIDDEN, $dibuka['status']);
            self::assertNull($dibuka['challenge']);
            self::assertArrayNotHasKey('code', $dibuka);
            self::assertSame(OtpService::maskRecipient(self::EMAIL), $dibuka['masked_recipient'] ?? null);
        }
    }

    /** Token QR dan token aplikasi tidak bisa dibuka lewat jalan ini. */
    public function testScanAndAppTokensAreNotDelivered(): void
    {
        $qr = $this->service->issue(7, 'ttd1', 42)['scan_token'];
        $app = $this->service->issue(7, 'ttd2', 42, 86400, ['route' => 'app'])['scan_token'];

        self::assertSame(ScanOtpService::NOT_DELIVERED, $this->service->openDelivered($qr, self::EMAIL)['status']);
        self::assertSame(ScanOtpService::NOT_DELIVERED, $this->service->openDelivered($app, self::EMAIL)['status']);
        self::assertSame(ScanOtpService::NOT_FOUND, $this->service->openDelivered(str_repeat('a', 32), self::EMAIL)['status']);
        self::assertSame(ScanOtpService::NOT_FOUND, $this->service->openDelivered('', self::EMAIL)['status']);
    }

    /** Kode tidak lagi ditampilkan sesudah diterima petugas atau kedaluwarsa. */
    public function testTheCodeDisappearsOnceUsedOrExpired(): void
    {
        $dipakai = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        (new OtpService($this->repo, null, $this->clock))->verify($dipakai['challenge_id'], $dipakai['code']);

        $dibuka = $this->service->openDelivered($dipakai['scan_token'], self::EMAIL);
        self::assertSame(ScanOtpService::VERIFIED, $dibuka['status']);
        self::assertArrayNotHasKey('code', $dibuka);

        $lama = $this->service->issueToRecipient(7, 'ttd2', 42, self::EMAIL);
        $this->later(1801);
        $dibuka = $this->service->openDelivered($lama['scan_token'], self::EMAIL);
        self::assertSame(ScanOtpService::EXPIRED, $dibuka['status']);
        self::assertArrayNotHasKey('code', $dibuka);
    }

    // ─── Daftar tertunda dan riwayat ───

    public function testPendingListsOnlyLiveCodesForThatAddress(): void
    {
        $hidup = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        $this->service->issueToRecipient(7, 'ttd2', 42, 'orang.lain@gmail.com');
        $dipakai = $this->service->issueToRecipient(7, 'ttd3', 42, self::EMAIL);
        (new OtpService($this->repo, null, $this->clock))->verify($dipakai['challenge_id'], $dipakai['code']);
        $dihabiskan = $this->service->issueToRecipient(7, 'ttd4', 42, self::EMAIL);
        $this->db->execute('UPDATE ezdoc_ttd_otp_challenges SET attempts = max_attempts WHERE challenge_id = ?', [$dihabiskan['challenge_id']]);
        // Token aplikasi yang kebetulan menyebut alamat yang sama tetap bukan kiriman email.
        $this->service->issue(7, 'ttd5', 42, 86400, ['route' => 'app', 'recipient' => self::EMAIL]);

        $tokens = array_column($this->repo->findPendingForRecipient(strtoupper(self::EMAIL), 'email'), 'scan_token');

        self::assertSame([$hidup['scan_token']], $tokens);
    }

    public function testPendingDropsExpiredCodes(): void
    {
        $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        $this->later(1800);

        self::assertSame([], $this->repo->findPendingForRecipient(self::EMAIL, 'email'));
    }

    /** "a@b.co" tidak menemukan "xa@b.co", dan kunci bersarang bukan pemiliknya. */
    public function testTheRecipientMatchIsExact(): void
    {
        $this->service->issueToRecipient(7, 'ttd1', 42, 'xa@b.co');
        $this->service->issue(7, 'ttd2', 42, 600, ['route' => 'email', 'x' => ['recipient' => 'a@b.co']]);

        self::assertSame([], $this->repo->findByRecipient('a@b.co'));
        self::assertCount(1, $this->repo->findByRecipient('XA@b.co'));
    }

    // ─── Kalimat persetujuan ───

    public function testConsentIsRecordedOnceAndNeverOverwritten(): void
    {
        $issued = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        self::assertSame('', (string) $this->row($issued['challenge_id'])['consent_message']);

        $this->service->recordConsent($issued['challenge_id'], 'yang dikirim');
        $this->service->recordConsent($issued['challenge_id'], 'tulisan ulang');

        self::assertSame('yang dikirim', $this->row($issued['challenge_id'])['consent_message']);
    }

    // ─── Kirim ulang ───

    /** Kode lewat email sudah "dipindai" sejak lahir, tapi tetap dimatikan saat dikirim ulang. */
    public function testResendingRetiresTheLiveEmailCodeForTheSlot(): void
    {
        $lama = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        $slotLain = $this->service->issueToRecipient(7, 'ttd2', 42, self::EMAIL);
        $qr = $this->service->issue(7, 'ttd1', 42);

        self::assertSame(1, $this->repo->expirePendingForSlot(42, 'ttd1', 'email'));

        self::assertSame(ScanOtpService::EXPIRED, $this->service->openDelivered($lama['scan_token'], self::EMAIL)['status']);
        self::assertSame(ScanOtpService::WAITING, $this->service->openDelivered($slotLain['scan_token'], self::EMAIL)['status']);
        self::assertSame(ScanOtpService::OK, $this->service->resolve($qr['scan_token'])['status']);
    }

    /** Kode baru yang baru terkirim dikecualikan saat yang lama dimatikan. */
    public function testRetiringKeepsTheNewlyDeliveredCode(): void
    {
        $lama = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);
        $baru = $this->service->issueToRecipient(7, 'ttd1', 42, self::EMAIL);

        self::assertSame(1, $this->repo->expirePendingForSlot(42, 'ttd1', 'email', $baru['challenge_id']));
        self::assertSame(ScanOtpService::EXPIRED, $this->service->openDelivered($lama['scan_token'], self::EMAIL)['status']);
        self::assertSame($baru['code'], $this->service->openDelivered($baru['scan_token'], self::EMAIL)['code'] ?? null);
    }

    /** Jalur aplikasi tetap hanya mematikan yang belum dipindai — perilaku lama. */
    public function testTheAppRouteStillSparesScannedRequests(): void
    {
        $dipindai = $this->service->issue(7, 'ttd1', 42, 86400, ['route' => 'app']);
        $this->service->fulfil($dipindai['scan_token'], '6281234567890', 'pesan');
        $this->service->issue(7, 'ttd1', 42, 86400, ['route' => 'app']);

        self::assertSame(1, $this->repo->expirePendingForSlot(42, 'ttd1', 'app'));
        self::assertSame(ScanOtpService::WAITING, $this->service->progress($dipindai['scan_token']));
    }
}
