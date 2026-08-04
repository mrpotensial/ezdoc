<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\Otp\OtpChallengeRepository;
use Ezdoc\Signature\Otp\OtpVoucherClaimResult;
use Ezdoc\Signature\Otp\OtpVoucherRepository;
use Ezdoc\Signature\Otp\OtpVoucherService;
use Ezdoc\Signature\Otp\OtpVoucherStatus;
use Ezdoc\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The patient-initiated OTP route, end to end against real SQLite.
 *
 * Every guarantee here is about time or about a race, and both are properties
 * of the SQL that ships — a conditional UPDATE and its affected-row count. A
 * hand-written fake would return whatever the test expected and assert nothing
 * about the statements actually executed.
 *
 * The clock is frozen throughout. Expiry is the feature, so the tests have to
 * be able to stand one second either side of a deadline; with a real clock that
 * means sleeping ten minutes or not testing the boundary at all.
 *
 * Requires pdo_sqlite. Laragon ships the DLL but leaves it off; enable it in
 * php.ini, or run with:
 *   php -d extension=php_pdo_sqlite.dll vendor/bin/phpunit
 */
final class OtpVoucherServiceTest extends TestCase
{
    private const PHONE = '6281234567890';
    private const START = '2026-08-03 10:00:00';

    private PdoConnection $db;
    private FrozenClock $clock;
    private OtpVoucherRepository $vouchers;
    private OtpChallengeRepository $challenges;
    private OtpVoucherService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema from the shipped migrations, not a copy inlined here — a column
        // added to one and not the other has to surface as a failure.
        $migrations = dirname(__DIR__, 3) . '/migrations/sqlite/';
        $pdo->exec((string) file_get_contents($migrations . '2026_08_03_000001_create_ezdoc_otp_vouchers.sql'));
        $pdo->exec((string) file_get_contents($migrations . '2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql'));

        $this->db = new PdoConnection($pdo);
        $this->clock = new FrozenClock(self::START);
        $this->vouchers = new OtpVoucherRepository($this->db);
        // Same clock as the service. Handing the challenge repository the real
        // one instead is not a test detail — it is the bug this pairing exists
        // to prevent, and it showed up as a freshly minted token being refused.
        $this->challenges = new OtpChallengeRepository($this->db, $this->clock);
        $this->service = new OtpVoucherService($this->vouchers, $this->clock, $this->challenges);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM ezdoc_otp_vouchers WHERE id = ?', [$id]);
        self::assertNotNull($row, "voucher #{$id} vanished");

        return $row;
    }

    /** @param array<string,mixed> $binding */
    private function claim(string $code, array $binding = []): OtpVoucherClaimResult
    {
        return $this->service->claim(self::PHONE, $code, $binding + [
            'template_id' => 7,
            'ttd_id'      => 'ttd1',
            'doc_id'      => 42,
        ]);
    }

    // ─── Issuing ───

    public function testIssueReturnsASixDigitCodeAndAnExpiry(): void
    {
        $issued = $this->service->issue(self::PHONE);

        self::assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        self::assertSame('2026-08-03 10:10:00', $issued['expires_at']);
        self::assertSame(600, $issued['ttl_seconds']);
    }

    /** The plaintext exists only in transit. Storage keeps a hash. */
    public function testTheCodeItselfIsNeverStored(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $row = $this->row($issued['voucher_id']);

        self::assertStringNotContainsString($issued['code'], (string) $row['code_hash']);
        self::assertTrue(password_verify($issued['code'], (string) $row['code_hash']));
    }

    public function testTheNumberIsStoredNormalised(): void
    {
        $issued = $this->service->issue('0812-3456-7890');

        self::assertSame(self::PHONE, $this->row($issued['voucher_id'])['phone']);
    }

    public function testAnUnusableNumberIsRefused(): void
    {
        $this->expectException(EzdocException::class);

        $this->service->issue('12');
    }

    public function testASecondCodeInsideTheCooldownIsRefused(): void
    {
        $this->service->issue(self::PHONE);
        $this->clock->advance(59);

        $this->expectException(EzdocException::class);

        $this->service->issue(self::PHONE);
    }

    public function testAfterTheCooldownANewCodeIsAllowed(): void
    {
        $this->service->issue(self::PHONE);
        $this->clock->advance(60);

        self::assertMatchesRegularExpression('/^\d{6}$/', $this->service->issue(self::PHONE)['code']);
    }

    /**
     * A new code retires the old one. Two live codes would mean the one on the
     * operator's screen and the one in the patient's hand can disagree without
     * either being wrong — and the attempt budget, meant to be per number,
     * would multiply.
     */
    public function testANewCodeRetiresTheOldOne(): void
    {
        $first = $this->service->issue(self::PHONE);
        $this->clock->advance(60);
        $second = $this->service->issue(self::PHONE);

        self::assertFalse($this->claim($first['code'])->isSuccess(), 'the superseded code still worked');
        self::assertTrue($this->claim($second['code'])->isSuccess());
    }

    /** Superseding must not look like "no code was ever issued" to the cooldown. */
    public function testSupersedingDoesNotResetTheCooldown(): void
    {
        $this->service->issue(self::PHONE);
        $this->clock->advance(60);
        $this->service->issue(self::PHONE);
        $this->clock->advance(30);

        $this->expectException(EzdocException::class);

        $this->service->issue(self::PHONE);
    }

    // ─── Status hint ───

    public function testStatusIsNoneBeforeAnyoneAsks(): void
    {
        self::assertSame(OtpVoucherStatus::NONE, $this->service->status(self::PHONE)->getState());
    }

    public function testStatusReportsTimeAndAttemptsRemaining(): void
    {
        $this->service->issue(self::PHONE);
        $this->clock->advance(180);

        $status = $this->service->status(self::PHONE);

        self::assertTrue($status->isActive());
        self::assertSame(420, $status->getSecondsLeft());
        self::assertSame(3, $status->getAttemptsLeft());
    }

    public function testStatusFlipsToExpiredOneSecondPastTheDeadline(): void
    {
        $this->service->issue(self::PHONE);

        $this->clock->advance(600);
        self::assertSame(OtpVoucherStatus::EXPIRED, $this->service->status(self::PHONE)->getState());
    }

    public function testStatusIsStillActiveOneSecondBeforeTheDeadline(): void
    {
        $this->service->issue(self::PHONE);

        $this->clock->advance(599);
        self::assertTrue($this->service->status(self::PHONE)->isActive());
    }

    public function testStatusReportsLockedOnceTheAttemptsAreGone(): void
    {
        $this->service->issue(self::PHONE);
        for ($i = 0; $i < 3; $i++) {
            $this->claim('000000');
        }

        self::assertSame(OtpVoucherStatus::LOCKED, $this->service->status(self::PHONE)->getState());
    }

    /**
     * The whole reason the hint exists. WhatsApp reports 62812…, the document
     * writes 0812…, and if those do not resolve to one number the hint reads
     * "no code" for a patient holding one — a failure nobody can diagnose from
     * the screen.
     */
    public function testStatusFindsTheSameNumberWrittenEitherWay(): void
    {
        $this->service->issue('62812-3456-7890');

        foreach (['081234567890', '+6281234567890', '0812 3456 7890', '6281234567890'] as $written) {
            self::assertTrue(
                $this->service->status($written)->isActive(),
                "'{$written}' did not resolve to the same number"
            );
        }
    }

    public function testStatusOfAnUnusableNumberIsNone(): void
    {
        self::assertSame(OtpVoucherStatus::NONE, $this->service->status('12')->getState());
    }

    // ─── Claiming ───

    public function testTheRightCodeYieldsASignToken(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $result = $this->claim($issued['code']);

        self::assertTrue($result->isSuccess());
        self::assertNotNull($result->getSignToken());
        self::assertStringStartsWith('stkn_', (string) $result->getSignToken());
    }

    /**
     * The point of the whole design: the token from this route is the same
     * token the push route mints, accepted by the same check at save time.
     */
    public function testTheTokenIsAcceptedByTheExistingSignTokenCheck(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $token = (string) $this->claim($issued['code'])->getSignToken();

        self::assertTrue($this->challenges->verifySignToken($token, 'ttd1', 7, 42));
    }

    /** Slot ids are template-local, so a token must not travel between slots. */
    public function testTheTokenIsRefusedForAnotherSlot(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $token = (string) $this->claim($issued['code'])->getSignToken();

        self::assertFalse($this->challenges->verifySignToken($token, 'ttd2', 7, 42));
        self::assertFalse($this->challenges->verifySignToken($token, 'ttd1', 8, 42));
        self::assertFalse($this->challenges->verifySignToken($token, 'ttd1', 7, 99));
    }

    public function testTheTokenCanOnlyBeSpentOnce(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $token = (string) $this->claim($issued['code'])->getSignToken();

        self::assertTrue($this->challenges->consumeSignToken($token, 'ttd1', 7, 42));
        self::assertFalse($this->challenges->consumeSignToken($token, 'ttd1', 7, 42));
    }

    public function testACodeCannotBeClaimedTwice(): void
    {
        $issued = $this->service->issue(self::PHONE);

        self::assertTrue($this->claim($issued['code'])->isSuccess());
        self::assertFalse($this->claim($issued['code'])->isSuccess());
    }

    public function testAWrongCodeSpendsAnAttempt(): void
    {
        $this->service->issue(self::PHONE);

        $first = $this->claim('000000');
        self::assertSame(OtpVoucherClaimResult::INVALID, $first->getReason());
        self::assertSame(2, $first->getAttemptsLeft());

        self::assertSame(1, $this->claim('000000')->getAttemptsLeft());
        self::assertSame(0, $this->claim('000000')->getAttemptsLeft());
    }

    /** Past the cap the code is dead, even when the next guess is right. */
    public function testTheRightCodeIsRefusedOnceTheBudgetIsGone(): void
    {
        $issued = $this->service->issue(self::PHONE);
        for ($i = 0; $i < 3; $i++) {
            $this->claim('000000');
        }

        $result = $this->claim($issued['code']);

        self::assertFalse($result->isSuccess());
        self::assertSame(OtpVoucherClaimResult::LOCKED, $result->getReason());
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $this->clock->advance(601);

        $result = $this->claim($issued['code']);

        self::assertFalse($result->isSuccess());
        self::assertSame(OtpVoucherClaimResult::EXPIRED, $result->getReason());
    }

    /** Expiring must not silently eat the attempt budget along the way. */
    public function testAnExpiredCodeDoesNotSpendAnAttempt(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $this->clock->advance(601);
        $this->claim($issued['code']);

        self::assertSame(0, (int) $this->row($issued['voucher_id'])['attempts']);
    }

    public function testClaimingWithNoCodeIssuedSaysSo(): void
    {
        self::assertSame(OtpVoucherClaimResult::NONE, $this->claim('123456')->getReason());
    }

    public function testAnUnusableNumberIsReportedAsSuch(): void
    {
        $result = $this->service->claim('12', '123456', ['template_id' => 7, 'ttd_id' => 'ttd1']);

        self::assertSame(OtpVoucherClaimResult::BAD_PHONE, $result->getReason());
    }

    /**
     * A token with no slot to belong to could satisfy any slot. Refusing loudly
     * beats minting one and discovering later what it unlocked.
     */
    public function testClaimingWithoutASlotIsAProgrammingError(): void
    {
        $issued = $this->service->issue(self::PHONE);

        $this->expectException(EzdocException::class);

        $this->service->claim(self::PHONE, $issued['code'], ['doc_id' => 42]);
    }

    // ─── What the claim records ───

    public function testAClaimRecordsWhatItWasSpentOn(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $this->clock->advance(120);
        $this->claim($issued['code']);

        $row = $this->row($issued['voucher_id']);

        self::assertSame('2026-08-03 10:02:00', $row['claimed_at']);
        self::assertSame(42, (int) $row['claimed_doc_id']);
        self::assertSame('ttd1', $row['claimed_ttd_id']);
        self::assertSame(7, (int) $row['claimed_template_id']);
        self::assertNotEmpty($row['claimed_challenge_id']);
    }

    /**
     * A number the operator typed is recorded as such, so a later dispute can
     * ask where it came from. See docs/PLAN-OTP-JALUR-KEDUA.md §9.
     */
    public function testTheOriginOfTheNumberIsRecorded(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $this->claim($issued['code'], ['phone_origin' => 'document']);

        self::assertSame('document', $this->row($issued['voucher_id'])['claimed_phone_origin']);
    }

    /** Anything not explicitly from the document counts as typed. */
    public function testAnUnstatedOriginIsRecordedAsManual(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $this->claim($issued['code']);

        self::assertSame('manual', $this->row($issued['voucher_id'])['claimed_phone_origin']);
    }

    /**
     * The challenge a claim leaves behind must not be verifiable on its own —
     * otherwise it is a second door into the same signature, one that skips the
     * voucher entirely.
     */
    public function testTheChallengeLeftBehindCannotBeVerifiedAgain(): void
    {
        $issued = $this->service->issue(self::PHONE);
        $this->claim($issued['code']);

        $challengeId = (string) $this->row($issued['voucher_id'])['claimed_challenge_id'];
        $challenge = $this->challenges->findById($challengeId);

        self::assertNotNull($challenge);
        self::assertNotEmpty($challenge['verified_at']);
        self::assertFalse(password_verify($issued['code'], (string) $challenge['otp_hash']));
    }

    // ─── Housekeeping ───

    public function testPurgeRemovesLapsedCodesButKeepsSpentOnes(): void
    {
        $spent = $this->service->issue(self::PHONE);
        $this->claim($spent['code']);

        $this->clock->advance(60);
        $lapsed = $this->service->issue(self::PHONE);

        $this->clock->advance(600 + 86400 + 1);
        self::assertSame(1, $this->service->purge());

        self::assertNull($this->db->fetchOne(
            'SELECT id FROM ezdoc_otp_vouchers WHERE id = ?',
            [$lapsed['voucher_id']]
        ));
        self::assertNotNull($this->db->fetchOne(
            'SELECT id FROM ezdoc_otp_vouchers WHERE id = ?',
            [$spent['voucher_id']]
        ));
    }

    public function testPurgeLeavesLiveCodesAlone(): void
    {
        $this->service->issue(self::PHONE);

        self::assertSame(0, $this->service->purge());
    }

    /**
     * The preview the cleanup command shows before deleting anything. A count
     * that disagrees with what the delete then does would make the dry run
     * worse than useless.
     */
    public function testTheCountMatchesWhatThePurgeRemoves(): void
    {
        $spent = $this->service->issue(self::PHONE);
        $this->claim($spent['code']);

        $this->clock->advance(60);
        $this->service->issue(self::PHONE);

        $this->clock->advance(600 + 86400 + 1);
        $cutoff = date('Y-m-d H:i:s', $this->clock->now()->getTimestamp() - 86400);

        self::assertSame(1, $this->vouchers->countExpired($cutoff));
        self::assertSame(1, $this->vouchers->countClaimed());
        self::assertSame(1, $this->vouchers->purgeExpired($cutoff));

        // And once removed, the count agrees there is nothing left.
        self::assertSame(0, $this->vouchers->countExpired($cutoff));
        self::assertSame(1, $this->vouchers->countClaimed());
    }
}
