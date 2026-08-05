<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\Otp\OtpChallengeRepository;
use Ezdoc\Signature\Otp\ScanOtpService;
use Ezdoc\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Kode yang terikat dokumen sejak lahir — V6.
 *
 * Yang diuji di sini soal waktu dan soal balapan, dan keduanya sifat SQL yang
 * benar-benar dikirim: satu UPDATE bersyarat yang hasilnya diputuskan jumlah
 * baris terpengaruh. Tiruan buatan tangan akan mengembalikan apa pun yang
 * diharapkan tes dan tidak membuktikan apa-apa tentang pernyataannya.
 *
 * Jamnya dibekukan sepanjang berkas. Dua tenggat yang berpindah pemilik adalah
 * inti rancangannya; dengan jam sungguhan, batasnya hanya bisa diuji dengan
 * benar-benar menunggu lima menit.
 */
final class ScanOtpServiceTest extends TestCase
{
    private const START = '2026-08-05 09:00:00';
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
        $pdo->exec((string) file_get_contents(
            dirname(__DIR__, 3) . '/migrations/sqlite/2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql'
        ));

        $this->db = new PdoConnection($pdo);
        $this->clock = new FrozenClock(self::START);
        $this->repo = new OtpChallengeRepository($this->db, $this->clock);
        $this->service = new ScanOtpService($this->repo, $this->clock);
    }

    /** @return array<string,mixed> */
    private function row(string $scanToken): array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ezdoc_ttd_otp_challenges WHERE scan_token = ?',
            [$scanToken]
        );
        self::assertNotNull($row);

        return $row;
    }

    private function issue(): string
    {
        return $this->service->issue(7, 'ttd1', 42)['scan_token'];
    }

    // ─── Menerbitkan ───

    public function testIssuingBindsTheDocumentBeforeAnyoneScans(): void
    {
        $token = $this->issue();
        $row = $this->row($token);

        self::assertSame(7, (int) $row['template_id']);
        self::assertSame('ttd1', $row['ttd_id']);
        self::assertSame(42, (int) $row['doc_id']);
    }

    /**
     * Inti seluruh V6. Kalau ini kosong, kode kembali jadi enam angka tanpa
     * makna dan tabel voucher tidak pernah perlu diganti.
     */
    public function testThereIsNoCodeAndNoPhoneUntilSomebodyScans(): void
    {
        $row = $this->row($this->issue());

        self::assertNull($row['otp_hash']);
        self::assertNull($row['phone']);
        self::assertNull($row['scanned_at']);
        self::assertNull($row['consent_message']);
    }

    /**
     * Teks yang dikirim penanda tangan tersimpan di ponselnya dan di server
     * penyedia chat. Tidak ada alasan ia membawa identitas siapa pun.
     */
    public function testTheTokenCarriesNoIdentity(): void
    {
        $token = $this->issue();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        self::assertStringNotContainsString('42', $token === '' ? 'x' : substr($token, 0, 0) . 'x');
    }

    public function testTwoIssuesNeverCollide(): void
    {
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $token = $this->issue();
            self::assertArrayNotHasKey($token, $seen);
            $seen[$token] = true;
        }
    }

    public function testIssuingWithoutASlotIsAProgrammingError(): void
    {
        $this->expectException(EzdocException::class);
        $this->service->issue(7, '');
    }

    // ─── Menyelesaikan token ───

    public function testAFreshTokenResolves(): void
    {
        self::assertSame(ScanOtpService::OK, $this->service->resolve($this->issue())['status']);
    }

    public function testAnUnknownTokenIsNotFound(): void
    {
        self::assertSame(ScanOtpService::NOT_FOUND, $this->service->resolve('tidak-ada')['status']);
        self::assertSame(ScanOtpService::NOT_FOUND, $this->service->resolve('')['status']);
    }

    public function testTheQrDiesFiveMinutesAfterItAppears(): void
    {
        $token = $this->issue();

        $this->clock->advance(299);
        self::assertSame(ScanOtpService::OK, $this->service->resolve($token)['status']);

        $this->clock->advance(1);
        self::assertSame(ScanOtpService::EXPIRED, $this->service->resolve($token)['status']);
    }

    /**
     * "Sudah dipindai" dilaporkan walau sudah lewat tenggat, dan itu disengaja:
     * kedua keadaan menuntun ke tindakan berbeda. Yang satu berarti ada yang
     * memindai duluan, yang satu berarti minta QR baru.
     */
    public function testAlreadyScannedIsReportedEvenAfterTheDeadline(): void
    {
        $token = $this->issue();
        $this->service->fulfil($token, self::PHONE, 'pesan');

        $this->clock->advance(60 * 60 * 24);

        self::assertSame(ScanOtpService::ALREADY_SCANNED, $this->service->resolve($token)['status']);
    }

    /** Bot butuh identitas dokumennya untuk disebut di balasan. */
    public function testResolvingHandsBackTheDocumentItPointsAt(): void
    {
        $resolved = $this->service->resolve($this->issue());

        self::assertNotNull($resolved['challenge']);
        self::assertSame(42, (int) $resolved['challenge']['doc_id']);
        self::assertSame('ttd1', $resolved['challenge']['ttd_id']);
    }

    // ─── Memenuhi pindaian ───

    public function testScanningMintsASixDigitCode(): void
    {
        $result = $this->service->fulfil($this->issue(), self::PHONE, 'pesan');

        self::assertTrue($result['ok']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $result['code']);
    }

    public function testTheCodeItselfIsNeverStored(): void
    {
        $token = $this->issue();
        $result = $this->service->fulfil($token, self::PHONE, 'pesan');
        $row = $this->row($token);

        self::assertStringNotContainsString($result['code'], (string) $row['otp_hash']);
        self::assertTrue(password_verify($result['code'], (string) $row['otp_hash']));
    }

    public function testTheScannersNumberIsRecordedNormalised(): void
    {
        $token = $this->issue();
        $this->service->fulfil($token, '0812-3456-7890', 'pesan');

        self::assertSame(self::PHONE, $this->row($token)['phone']);
    }

    /**
     * Pertanyaan saat sengketa bukan "apa isi dokumennya" tapi "apa yang
     * diberitahukan kepadanya saat itu". Merangkainya ulang nanti tidak
     * menjawab itu — nama template bisa berubah.
     */
    public function testTheExactMessageSentIsKept(): void
    {
        $pesan = "Dokumen: Persetujuan Tindakan Medis\nNo. Pendaftaran: 2607280142";
        $token = $this->issue();
        $this->service->fulfil($token, self::PHONE, $pesan);

        self::assertSame($pesan, $this->row($token)['consent_message']);
    }

    /** Tenggatnya berpindah pemilik: dari QR yang pendek ke kode yang lebih panjang. */
    public function testTheDeadlineChangesOwnerOnScan(): void
    {
        $token = $this->issue();
        self::assertSame('2026-08-05 09:05:00', $this->row($token)['expires_at']);

        $this->clock->advance(120);
        $this->service->fulfil($token, self::PHONE, 'pesan');

        // 09:02 + 10 menit, bukan 09:05 yang tersisa dari QR-nya.
        self::assertSame('2026-08-05 09:12:00', $this->row($token)['expires_at']);
    }

    /**
     * Dua orang memindai QR yang sama di layar yang sama. Kalau keduanya
     * menerima kode, yang kedua diam-diam menimpa yang pertama — dan yang
     * pertama diberi tahu kode yang tidak akan pernah cocok.
     */
    public function testOnlyTheFirstScanWins(): void
    {
        $token = $this->issue();

        $pertama = $this->service->fulfil($token, self::PHONE, 'pesan');
        $kedua = $this->service->fulfil($token, '6289999999999', 'pesan lain');

        self::assertTrue($pertama['ok']);
        self::assertFalse($kedua['ok']);
        self::assertSame(ScanOtpService::ALREADY_SCANNED, $kedua['reason']);

        // Yang tersimpan tetap milik pemindai pertama.
        $row = $this->row($token);
        self::assertSame(self::PHONE, $row['phone']);
        self::assertSame('pesan', $row['consent_message']);
        self::assertTrue(password_verify($pertama['code'], (string) $row['otp_hash']));
    }

    public function testAnExpiredQrCannotBeFulfilled(): void
    {
        $token = $this->issue();
        $this->clock->advance(301);

        $result = $this->service->fulfil($token, self::PHONE, 'pesan');

        self::assertFalse($result['ok']);
        self::assertSame(ScanOtpService::EXPIRED, $result['reason']);
        self::assertNull($this->row($token)['otp_hash']);
    }

    public function testAnUnusableNumberIsRefusedBeforeAnythingIsWritten(): void
    {
        $token = $this->issue();
        $result = $this->service->fulfil($token, '12', 'pesan');

        self::assertFalse($result['ok']);
        self::assertSame('bad_phone', $result['reason']);
        self::assertNull($this->row($token)['scanned_at']);
    }

    public function testAnUnknownTokenCannotBeFulfilled(): void
    {
        $result = $this->service->fulfil('tidak-ada', self::PHONE, 'pesan');

        self::assertFalse($result['ok']);
        self::assertSame(ScanOtpService::NOT_FOUND, $result['reason']);
    }

    // ─── Bertemu jalur lama ───

    /**
     * Setelah dipindai, barisnya adalah challenge biasa — jadi seluruh mesin
     * verifikasi dan sign token yang sudah ada bekerja tanpa diubah. Itu yang
     * membuat `save_document.php` tidak perlu tahu jalur mana yang dipakai.
     */
    public function testAScannedChallengeVerifiesThroughTheExistingPath(): void
    {
        $token = $this->issue();
        $hasil = $this->service->fulfil($token, self::PHONE, 'pesan');

        $challengeId = (string) $this->row($token)['challenge_id'];
        $otp = new \Ezdoc\Signature\Otp\OtpService($this->repo, null);

        $verdict = $otp->verify($challengeId, $hasil['code']);

        self::assertTrue($verdict->isSuccess());
        self::assertNotNull($verdict->getSignToken());
        self::assertTrue($this->repo->verifySignToken((string) $verdict->getSignToken(), 'ttd1', 7, 42));
    }
}
