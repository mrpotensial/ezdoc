<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

/**
 * Value Object representing the result of an OTP verification request.
 */
final class OtpVerifyResult
{
    private bool $success;
    private string $message;
    private ?string $signToken;
    private int $attemptsLeft;

    public function __construct(
        bool $success,
        string $message = '',
        ?string $signToken = null,
        int $attemptsLeft = 0
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->signToken = $signToken;
        $this->attemptsLeft = $attemptsLeft;
    }

    public static function success(string $signToken, string $message = 'OTP verified successfully'): self
    {
        return new self(true, $message, $signToken, 0);
    }

    public static function failure(string $message, int $attemptsLeft = 0): self
    {
        return new self(false, $message, null, max(0, $attemptsLeft));
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getSignToken(): ?string
    {
        return $this->signToken;
    }

    public function getAttemptsLeft(): int
    {
        return $this->attemptsLeft;
    }

    public function toArray(): array
    {
        return [
            'success'       => $this->success,
            'message'       => $this->message,
            'sign_token'    => $this->signToken,
            'attempts_left' => $this->attemptsLeft,
        ];
    }
}
