<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Context;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Support\Clock;
use Ezdoc\Support\SystemClock;

/**
 * One-time codes the recipient asks for, instead of ones we push at them.
 *
 * ## The shape difference, and why it needs its own service
 *
 * {@see OtpService} is challenge-first: an operator picks a signature slot, the
 * system creates a row bound to (template, slot, document, phone) and only then
 * sends a code. Everything is known before the code exists.
 *
 * This is the inverse. A patient scans a QR in the waiting room and messages us;
 * a code is minted knowing only who asked for it. Which document it will
 * eventually authorise is decided minutes later, by somebody else, at a desk.
 *
 * So a voucher binds to a phone number and nothing else, until it is spent.
 *
 * ## Where the two routes meet
 *
 * {@see claim()} does not invent a parallel notion of proof. It creates an
 * already-verified challenge row and mints a sign token through the existing
 * {@see OtpChallengeRepository} — the same token, with the same single-use
 * consumption, checked by the same code at save time.
 *
 * Nothing downstream can tell which route was taken, which is exactly the
 * property that keeps `save_document.php` untouched.
 *
 * ## One live code per number
 *
 * Issuing supersedes any unclaimed code the number still holds. Two live codes
 * would mean the one on the operator's screen and the one in the patient's hand
 * can disagree without either being wrong — and the attempt budget, which is
 * meant to be per number, would multiply by however many codes were requested.
 *
 * Twilio Verify and Auth0 behave the same way: a new code retires the old one.
 */
final class OtpVoucherService
{
    /**
     * How long a code lives.
     *
     * Longer than the push route's five minutes, and deliberately so: there the
     * code arrives at the moment it is needed, here the patient scans in the
     * waiting room and then walks to the counter. The wider guessing window is
     * paid for by the attempt cap, not by the clock.
     */
    public const TTL_SECONDS = 600;

    /** Minimum gap between two codes for the same number. */
    public const ISSUE_COOLDOWN_SECONDS = 60;

    /** Wrong guesses allowed before the code is dead. Per number — see class docblock. */
    public const MAX_ATTEMPTS = 3;

    /**
     * How long the challenge minted by a claim stays valid.
     *
     * Covers entering the code, drawing the signature and submitting. The
     * repository adds its own grace period on top when spending the token.
     */
    private const CHALLENGE_WINDOW_SECONDS = 600;

    /** Shortest number we will accept, digits only. */
    private const MIN_PHONE_DIGITS = 8;

    private OtpVoucherRepository $repo;
    private Clock $clock;
    private OtpChallengeRepository $challenges;

    /**
     * Every dependency is optional so `new OtpVoucherService()` works in a plain
     * script, while a container can inject all three. Same shape as
     * {@see OtpService::__construct()}.
     */
    public function __construct(
        ?OtpVoucherRepository $repo = null,
        ?Clock $clock = null,
        ?OtpChallengeRepository $challenges = null
    ) {
        $this->clock = $clock ?? new SystemClock();

        if ($repo === null || $challenges === null) {
            $db = Context::default()->db;
            $repo = $repo ?? new OtpVoucherRepository($db);
            // The clock goes down with it. A claim writes a challenge row and
            // the same request may check the token it just minted; if the two
            // read different clocks, the check can refuse a row written a
            // moment earlier.
            $challenges = $challenges ?? new OtpChallengeRepository($db, $this->clock);
        }

        $this->repo = $repo;
        $this->challenges = $challenges;
    }

    /**
     * Mint a code for a phone number.
     *
     * The plaintext code is returned because the caller has to deliver it. It is
     * the only moment it exists outside the sender's phone — storage keeps only
     * a hash. Do not log the return value.
     *
     * @param string               $phone   Any format; normalised here.
     * @param array<string,mixed>  $meta    Provider details worth keeping (message id, channel).
     * @param string               $source  Which channel asked.
     * @return array{code: string, expires_at: string, ttl_seconds: int, voucher_id: int}
     * @throws EzdocException When the number is unusable or the cooldown has not elapsed.
     */
    public function issue(string $phone, array $meta = [], string $source = 'whatsapp_inbound'): array
    {
        $clean = OtpService::normalizePhone($phone);
        if (strlen($clean) < self::MIN_PHONE_DIGITS) {
            throw new EzdocException('Invalid recipient phone number.');
        }

        $now = $this->clock->now();
        $nowTs = $now->getTimestamp();

        // Without this, anyone who can message the bot can make it answer as
        // fast as they can type — and each answer is a real message.
        $lastIssued = $this->repo->lastIssuedAt($clean);
        if ($lastIssued !== null) {
            $elapsed = $nowTs - $lastIssued;
            if ($elapsed < self::ISSUE_COOLDOWN_SECONDS) {
                throw new EzdocException(sprintf(
                    'Please wait %d more second(s) before requesting another code.',
                    self::ISSUE_COOLDOWN_SECONDS - $elapsed
                ));
            }
        }

        $nowStr = $this->fmt($nowTs);
        $this->repo->supersedeActive($clean, $nowStr, $this->fmt($nowTs - 1));

        $code = (string) random_int(100000, 999999);
        $expiresAt = $this->fmt($nowTs + self::TTL_SECONDS);

        $voucherId = $this->repo->create([
            'phone'         => $clean,
            'code_hash'     => password_hash($code, PASSWORD_DEFAULT),
            'source'        => $source,
            'issued_at'     => $nowStr,
            'expires_at'    => $expiresAt,
            'max_attempts'  => self::MAX_ATTEMPTS,
            'provider_meta' => $meta === [] ? null : $meta,
        ]);

        return [
            'code'        => $code,
            'expires_at'  => $expiresAt,
            'ttl_seconds' => self::TTL_SECONDS,
            'voucher_id'  => $voucherId,
        ];
    }

    /**
     * Whether this number is currently holding a usable code.
     *
     * Drives the hint under the phone field. It exists so a mistyped number
     * shows up as "no code for this number" instead of three rounds of "wrong
     * code" that blame the patient for the operator's typo.
     *
     * Answering this question is also a small oracle — it reveals which numbers
     * have a pending signature. The callers that expose it must apply the same
     * access check as signing, and must record lookups for numbers that did not
     * come from the document. See docs/PLAN-OTP-JALUR-KEDUA.md §6.
     */
    public function status(string $phone): OtpVoucherStatus
    {
        $clean = OtpService::normalizePhone($phone);
        if (strlen($clean) < self::MIN_PHONE_DIGITS) {
            return OtpVoucherStatus::none();
        }

        $row = $this->repo->findLatestUnclaimed($clean);
        if ($row === null) {
            return OtpVoucherStatus::none();
        }

        $secondsLeft = $this->secondsUntil((string) $row['expires_at']);
        if ($secondsLeft <= 0) {
            return OtpVoucherStatus::expired();
        }

        $attemptsLeft = max(0, (int) $row['max_attempts'] - (int) $row['attempts']);
        if ($attemptsLeft === 0) {
            return OtpVoucherStatus::locked($secondsLeft);
        }

        return OtpVoucherStatus::active($secondsLeft, $attemptsLeft);
    }

    /**
     * Spend a code and get a sign token for a specific signature slot.
     *
     * Order matters: the voucher is burned first, then the challenge is written.
     * The reverse would leave a usable challenge behind whenever the second step
     * failed, and the code could be spent again. Failing this way loses the
     * code, which costs the patient one free message; failing the other way
     * loses single-use, which costs the property the whole design rests on.
     *
     * @param array{doc_id?: int|null, ttd_id?: string, template_id?: int, phone_origin?: string} $binding
     *        `ttd_id` and `template_id` are required to mint a token — the token
     *        is bound to a slot, and slot ids are only unique within a template.
     *        `phone_origin` is 'document' or 'manual'; see §9 of the plan.
     */
    public function claim(string $phone, string $code, array $binding = []): OtpVoucherClaimResult
    {
        $clean = OtpService::normalizePhone($phone);
        if (strlen($clean) < self::MIN_PHONE_DIGITS) {
            return OtpVoucherClaimResult::failure(OtpVoucherClaimResult::BAD_PHONE);
        }

        $row = $this->repo->findLatestUnclaimed($clean);
        if ($row === null) {
            return OtpVoucherClaimResult::failure(OtpVoucherClaimResult::NONE);
        }

        $voucherId = (int) $row['id'];

        if ($this->secondsUntil((string) $row['expires_at']) <= 0) {
            return OtpVoucherClaimResult::failure(OtpVoucherClaimResult::EXPIRED, 0, $voucherId);
        }

        $maxAttempts = (int) $row['max_attempts'] ?: self::MAX_ATTEMPTS;

        // Charge before comparing, conditionally. Comparing first and charging
        // after lets parallel requests each read the same remaining budget and
        // spend more guesses than the cap allows.
        $spent = $this->repo->chargeAttempt($voucherId, $maxAttempts);
        if ($spent === null) {
            return OtpVoucherClaimResult::failure(OtpVoucherClaimResult::LOCKED, 0, $voucherId);
        }

        $attemptsLeft = max(0, $maxAttempts - $spent);

        if (!password_verify(trim($code), (string) $row['code_hash'])) {
            return OtpVoucherClaimResult::failure(
                OtpVoucherClaimResult::INVALID,
                $attemptsLeft,
                $voucherId
            );
        }

        $ttdId = (string) ($binding['ttd_id'] ?? '');
        $templateId = (int) ($binding['template_id'] ?? 0);
        $docId = isset($binding['doc_id']) ? (int) $binding['doc_id'] : null;

        if ($ttdId === '' || $templateId <= 0) {
            throw new EzdocException('Claiming a voucher requires both template_id and ttd_id.');
        }

        $now = $this->clock->now();
        $challengeId = 'otp_' . bin2hex(random_bytes(16));

        // Single-use lives here: the first caller to flip claimed_at wins, and
        // a code that lapsed since the check above still fails.
        $won = $this->repo->claim($voucherId, [
            'doc_id'       => $docId,
            'ttd_id'       => $ttdId,
            'template_id'  => $templateId,
            'challenge_id' => $challengeId,
            'phone_origin' => $this->normaliseOrigin($binding['phone_origin'] ?? null),
        ], $this->fmt($now->getTimestamp()));

        if (!$won) {
            return OtpVoucherClaimResult::failure(
                OtpVoucherClaimResult::INVALID,
                $attemptsLeft,
                $voucherId
            );
        }

        $signToken = 'stkn_' . bin2hex(random_bytes(32));
        $this->mintVerifiedChallenge($challengeId, $signToken, $clean, $ttdId, $templateId, $docId, $now->getTimestamp());

        return OtpVoucherClaimResult::ok($signToken, $voucherId);
    }

    /**
     * Delete unclaimed vouchers that lapsed more than `$olderThanSeconds` ago.
     *
     * Claimed rows survive: they connect a signature to the code that let it
     * happen, which is the question an audit actually asks.
     */
    public function purge(int $olderThanSeconds = 86400): int
    {
        return $this->repo->purgeExpired(
            $this->fmt($this->clock->now()->getTimestamp() - max(0, $olderThanSeconds))
        );
    }

    /**
     * Write the challenge row a claim stands in for, already verified.
     *
     * The stored `otp_hash` is a hash of fresh random bytes nobody has ever
     * seen. The column cannot be null, and leaving anything guessable there
     * would create a second way to verify the same challenge — one that skips
     * the voucher entirely.
     */
    private function mintVerifiedChallenge(
        string $challengeId,
        string $signToken,
        string $phone,
        string $ttdId,
        int $templateId,
        ?int $docId,
        int $nowTs
    ): void {
        $this->challenges->create([
            'challenge_id'  => $challengeId,
            'doc_id'        => $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'phone'         => $phone,
            'otp_hash'      => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'max_attempts'  => 0,
            'expires_at'    => $this->fmt($nowTs + self::CHALLENGE_WINDOW_SECONDS),
            'provider_meta' => ['route' => 'voucher'],
        ]);

        $this->challenges->markVerified($challengeId, $signToken);
    }

    /** Only two origins are meaningful; anything else is recorded as manual. */
    private function normaliseOrigin(?string $origin): string
    {
        return $origin === 'document' ? 'document' : 'manual';
    }

    /** Seconds from now until a stored timestamp, negative once it has passed. */
    private function secondsUntil(string $stamp): int
    {
        $ts = strtotime($stamp);
        if ($ts === false) {
            return 0;
        }

        return $ts - $this->clock->now()->getTimestamp();
    }

    /** The storage format every DATETIME column in this library uses. */
    private function fmt(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
