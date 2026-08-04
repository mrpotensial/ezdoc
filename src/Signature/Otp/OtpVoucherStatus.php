<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

/**
 * What a phone's voucher looks like right now, for the hint under the number
 * field in the signing dialog.
 *
 * ## Why a code and not a sentence
 *
 * `state` is a machine token; the view turns it into words. A sentence built
 * here would be Indonesian forever, including for an operator who switched the
 * interface to English. This mirrors how the document history endpoint sends
 * `event` and lets the client translate.
 *
 * ## What it deliberately does not carry
 *
 * The code. Never the code — not masked, not partially, not "starts with 4".
 * The status answers whether the patient has one and how long it lives, which
 * is what stops an operator from typing a mistyped number and blaming the code.
 */
final class OtpVoucherStatus
{
    /** No unclaimed voucher exists for this number. */
    public const NONE = 'none';

    /** A usable code exists. */
    public const ACTIVE = 'active';

    /** A code was issued but its time ran out. */
    public const EXPIRED = 'expired';

    /** A code exists but its attempt budget is gone; a new one is required. */
    public const LOCKED = 'locked';

    private string $state;
    private int $secondsLeft;
    private int $attemptsLeft;

    public function __construct(string $state, int $secondsLeft = 0, int $attemptsLeft = 0)
    {
        $this->state = $state;
        $this->secondsLeft = max(0, $secondsLeft);
        $this->attemptsLeft = max(0, $attemptsLeft);
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    public static function locked(int $secondsLeft): self
    {
        return new self(self::LOCKED, $secondsLeft, 0);
    }

    public static function active(int $secondsLeft, int $attemptsLeft): self
    {
        return new self(self::ACTIVE, $secondsLeft, $attemptsLeft);
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }

    /** Seconds until the code lapses. Zero unless the state is active or locked. */
    public function getSecondsLeft(): int
    {
        return $this->secondsLeft;
    }

    public function getAttemptsLeft(): int
    {
        return $this->attemptsLeft;
    }

    /**
     * @return array{state: string, seconds_left: int, attempts_left: int}
     */
    public function toArray(): array
    {
        return [
            'state'         => $this->state,
            'seconds_left'  => $this->secondsLeft,
            'attempts_left' => $this->attemptsLeft,
        ];
    }
}
