<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Signature\Otp\OtpChallengeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the guarantees the OTP challenge table exists to provide:
 * attempts are capped, a token can be spent exactly once, and it only
 * satisfies the signature slot it was issued for.
 *
 * Runs against real SQLite rather than a hand-written fake, because the
 * guarantees live in SQL — each one is a conditional UPDATE whose affected-row
 * count decides the outcome. A fake that returns whatever the test expects
 * would assert nothing about the statements actually shipped.
 *
 * Requires pdo_sqlite. Laragon ships the DLL but leaves it off; enable it in
 * php.ini, or run this file with:
 *   php -d extension=php_pdo_sqlite.dll vendor/bin/phpunit
 */
final class OtpChallengeRepositoryTest extends TestCase
{
    private PdoConnection $db;
    private OtpChallengeRepository $repo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema comes from the shipped SQLite migration rather than a copy
        // inlined here, so a column added to one and not the other shows up as
        // a failure instead of drifting quietly.
        $pdo->exec(file_get_contents(
            dirname(__DIR__, 3) . '/migrations/sqlite/2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql'
        ));

        $this->db = new PdoConnection($pdo);
        $this->repo = new OtpChallengeRepository($this->db);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function makeChallenge(array $overrides = []): string
    {
        $id = $overrides['challenge_id'] ?? ('otp_' . bin2hex(random_bytes(8)));

        $this->repo->create($overrides + [
            'challenge_id' => $id,
            'doc_id'       => 10,
            'template_id'  => 1,
            'ttd_id'       => 'ttd1',
            'phone'        => '6281234567890',
            'otp_hash'     => password_hash('123456', PASSWORD_DEFAULT),
            'max_attempts' => 3,
            'expires_at'   => date('Y-m-d H:i:s', time() + 300),
        ]);

        return $id;
    }

    /** Verify a challenge and return the token it issued. */
    private function issueToken(string $challengeId): string
    {
        $token = 'stkn_' . bin2hex(random_bytes(16));
        self::assertTrue($this->repo->markVerified($challengeId, $token));
        return $token;
    }

    // ─── Attempt budget ───

    public function testAttemptsAreChargedUntilTheCapIsReached(): void
    {
        $id = $this->makeChallenge();

        self::assertSame(1, $this->repo->chargeAttempt($id, 3));
        self::assertSame(2, $this->repo->chargeAttempt($id, 3));
        self::assertSame(3, $this->repo->chargeAttempt($id, 3));
    }

    /**
     * Past the cap the guard must refuse rather than keep counting — otherwise
     * a caller could grind through the whole 6-digit space.
     */
    public function testChargingBeyondTheCapReturnsNull(): void
    {
        $id = $this->makeChallenge();

        for ($i = 0; $i < 3; $i++) {
            $this->repo->chargeAttempt($id, 3);
        }

        self::assertNull($this->repo->chargeAttempt($id, 3));
        self::assertNull($this->repo->chargeAttempt($id, 3));
    }

    public function testExhaustedChallengeDoesNotKeepIncrementing(): void
    {
        $id = $this->makeChallenge();

        for ($i = 0; $i < 5; $i++) {
            $this->repo->chargeAttempt($id, 3);
        }

        $row = $this->repo->findById($id);
        self::assertSame(3, (int) $row['attempts'], 'attempts must stop at the cap');
    }

    public function testChargingAnUnknownChallengeReturnsNull(): void
    {
        self::assertNull($this->repo->chargeAttempt('otp_does_not_exist', 3));
    }

    // ─── Verification ───

    /**
     * Two correct submissions racing must not each mint a token: the second
     * would overwrite sign_token, silently invalidating one the caller already
     * holds.
     */
    public function testOnlyTheFirstVerificationSucceeds(): void
    {
        $id = $this->makeChallenge();

        self::assertTrue($this->repo->markVerified($id, 'stkn_first'));
        self::assertFalse($this->repo->markVerified($id, 'stkn_second'));

        $row = $this->repo->findById($id);
        self::assertSame('stkn_first', $row['sign_token'], 'the first token must survive');
    }

    // ─── Token consumption ───

    public function testTokenIsAcceptedOnceThenSpent(): void
    {
        $id = $this->makeChallenge();
        $token = $this->issueToken($id);

        self::assertTrue($this->repo->consumeSignToken($token, 'ttd1', 1, 10));
        self::assertFalse($this->repo->consumeSignToken($token, 'ttd1', 1, 10), 'replay must fail');
    }

    /**
     * Slot ids are template-local — `ttd1` exists on nearly every template — so
     * binding on ttd_id alone would let a token minted on a throwaway template
     * satisfy a slot on any other.
     */
    public function testTokenIsRejectedForAnotherTemplate(): void
    {
        $token = $this->issueToken($this->makeChallenge());

        self::assertFalse($this->repo->consumeSignToken($token, 'ttd1', 999, 10));
    }

    public function testTokenIsRejectedForAnotherSlot(): void
    {
        $token = $this->issueToken($this->makeChallenge());

        self::assertFalse($this->repo->consumeSignToken($token, 'ttd2', 1, 10));
    }

    public function testTokenIsRejectedForAnotherDocument(): void
    {
        $token = $this->issueToken($this->makeChallenge());

        self::assertFalse($this->repo->consumeSignToken($token, 'ttd1', 1, 999));
    }

    /** A challenge raised without a document must not satisfy one that has it. */
    public function testDocumentScopedTokenDoesNotMatchNullDocument(): void
    {
        $token = $this->issueToken($this->makeChallenge());

        self::assertFalse($this->repo->consumeSignToken($token, 'ttd1', 1, null));
    }

    public function testUnverifiedChallengeYieldsNoUsableToken(): void
    {
        $id = $this->makeChallenge();
        // Never verified, so sign_token is still null.
        self::assertFalse($this->repo->consumeSignToken('stkn_never_issued', 'ttd1', 1, 10));
        self::assertNull($this->repo->findById($id)['sign_token']);
    }

    public function testExpiredChallengeYieldsNoUsableToken(): void
    {
        // Older than the repository's grace window, so the token is dead on arrival.
        $id = $this->makeChallenge([
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        $token = $this->issueToken($id);

        self::assertFalse($this->repo->consumeSignToken($token, 'ttd1', 1, 10));
    }

    /**
     * The code is entered, then the operator still has to draw and submit the
     * signature — a token that died the instant the challenge expired would
     * make the flow unusable.
     */
    public function testTokenSurvivesBrieflyAfterTheChallengeExpires(): void
    {
        $id = $this->makeChallenge([
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $token = $this->issueToken($id);

        self::assertTrue($this->repo->consumeSignToken($token, 'ttd1', 1, 10));
    }

    // ─── Send cooldown ───

    public function testCooldownSeesTheMostRecentChallengeForASlot(): void
    {
        self::assertNull($this->repo->lastChallengeCreatedAt(1, 'ttd1', 10));

        $this->makeChallenge();
        $seen = $this->repo->lastChallengeCreatedAt(1, 'ttd1', 10);

        self::assertNotNull($seen);
        self::assertEqualsWithDelta(time(), $seen, 5);
    }

    public function testCooldownIsScopedPerSlot(): void
    {
        $this->makeChallenge(['ttd_id' => 'ttd1']);

        self::assertNotNull($this->repo->lastChallengeCreatedAt(1, 'ttd1', 10));
        self::assertNull(
            $this->repo->lastChallengeCreatedAt(1, 'ttd2', 10),
            'a code sent for one slot must not silence another'
        );
    }

    public function testCooldownIsScopedPerDocument(): void
    {
        $this->makeChallenge(['doc_id' => 10]);

        self::assertNotNull($this->repo->lastChallengeCreatedAt(1, 'ttd1', 10));
        self::assertNull($this->repo->lastChallengeCreatedAt(1, 'ttd1', 99));
    }

    public function testCooldownDistinguishesNullDocumentFromAnId(): void
    {
        $this->makeChallenge(['challenge_id' => 'otp_no_doc', 'doc_id' => null]);

        self::assertNotNull($this->repo->lastChallengeCreatedAt(1, 'ttd1', null));
        self::assertNull($this->repo->lastChallengeCreatedAt(1, 'ttd1', 10));
    }

    // ─── Failed delivery ───

    /** A code that never reached anyone must not stay verifiable. */
    public function testExpiringBurnsTheChallenge(): void
    {
        $id = $this->makeChallenge();
        $this->repo->expire($id);

        $row = $this->repo->findById($id);
        self::assertLessThan(time(), strtotime($row['expires_at']));
    }

    // ─── Storage hygiene ───

    public function testTheCodeItselfIsNeverStored(): void
    {
        $id = $this->makeChallenge();
        $row = $this->repo->findById($id);

        self::assertStringNotContainsString('123456', $row['otp_hash']);
        self::assertTrue(password_verify('123456', $row['otp_hash']));
    }

    public function testProviderMetadataRoundTrips(): void
    {
        $id = $this->makeChallenge();
        $this->repo->attachProviderMeta($id, ['channel' => 'whatsapp', 'provider' => 'acme']);

        $row = $this->repo->findById($id);
        self::assertSame('whatsapp', $row['provider_meta']['channel']);
        self::assertSame('acme', $row['provider_meta']['provider']);
    }
}
