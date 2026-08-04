<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

/**
 * Outcome of spending a voucher.
 *
 * A successful claim carries a sign token — the same single-use token
 * {@see OtpService::verify()} issues, minted the same way, spent by the same
 * code path. That is the whole point of the design: the second OTP route ends
 * exactly where the first one does, so nothing downstream needs to know which
 * route the operator took.
 *
 * `reason` is a machine token rather than a sentence, for the same reason as in
 * {@see OtpVoucherStatus}: the view owns the wording.
 */
final class OtpVoucherClaimResult
{
    /** Code matched; the token is valid. */
    public const OK = 'ok';

    /** Wrong code. `attemptsLeft` says how many tries remain. */
    public const INVALID = 'invalid';

    /** A code existed but its time ran out. */
    public const EXPIRED = 'expired';

    /** Attempt budget exhausted. Only a fresh code helps. */
    public const LOCKED = 'locked';

    /** This number never asked for a code. */
    public const NONE = 'none';

    /** The number itself was unusable — too short, or not a number at all. */
    public const BAD_PHONE = 'bad_phone';

    private bool $success;
    private string $reason;
    private ?string $signToken;
    private int $attemptsLeft;
    private ?int $voucherId;

    public function __construct(
        bool $success,
        string $reason,
        ?string $signToken = null,
        int $attemptsLeft = 0,
        ?int $voucherId = null
    ) {
        $this->success = $success;
        $this->reason = $reason;
        $this->signToken = $signToken;
        $this->attemptsLeft = max(0, $attemptsLeft);
        $this->voucherId = $voucherId;
    }

    public static function ok(string $signToken, int $voucherId): self
    {
        return new self(true, self::OK, $signToken, 0, $voucherId);
    }

    public static function failure(string $reason, int $attemptsLeft = 0, ?int $voucherId = null): self
    {
        return new self(false, $reason, null, $attemptsLeft, $voucherId);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getSignToken(): ?string
    {
        return $this->signToken;
    }

    public function getAttemptsLeft(): int
    {
        return $this->attemptsLeft;
    }

    public function getVoucherId(): ?int
    {
        return $this->voucherId;
    }

    /**
     * @return array{success: bool, reason: string, sign_token: string|null, attempts_left: int}
     */
    public function toArray(): array
    {
        return [
            'success'       => $this->success,
            'reason'        => $this->reason,
            'sign_token'    => $this->signToken,
            'attempts_left' => $this->attemptsLeft,
        ];
    }
}
