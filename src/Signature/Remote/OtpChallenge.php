<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Remote;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Remote\OtpChallenge — DTO state "menunggu OTP" untuk
 * flow signing PSrE. Di-return oleh `BaseRemoteProvider::initiate()`
 * ketika session status = `otp_required`.
 *
 * Field `maskedTarget` sengaja opaque — provider yang mask (`a***@ex.com`
 * / `+62****1234`), library tidak coba reconstruct.
 *
 * PHP 7.4+ compatible.
 */
final class OtpChallenge
{
    const CHANNEL_EMAIL = 'email';
    const CHANNEL_SMS = 'sms';
    const CHANNEL_APP = 'app';
    const CHANNEL_WA = 'wa';

    /** @var array<string> */
    private static $ALLOWED_CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
        self::CHANNEL_APP,
        self::CHANNEL_WA,
    ];

    /** @var string */
    private $sessionId;

    /** @var string */
    private $channel;

    /** @var string */
    private $maskedTarget;

    /** @var int unix ts; 0 = unknown */
    private $expiresAt;

    /** @var int */
    private $attemptsRemaining;

    /**
     * @param string $sessionId          session id yang OTP-nya dikirim
     * @param string $channel            'email' | 'sms' | 'app' | 'wa'
     * @param string $maskedTarget       display-safe target (mis. 'a***@ex.com')
     * @param int    $expiresAt          unix ts OTP expiry; 0 = unknown
     * @param int    $attemptsRemaining  jumlah attempt tersisa; kalau 0 =
     *                                   caller harus resend/abort
     * @throws ValidationException
     */
    public function __construct(
        string $sessionId,
        string $channel,
        string $maskedTarget,
        int $expiresAt,
        int $attemptsRemaining
    ) {
        if ($sessionId === '') {
            throw ValidationException::forField('sessionId', 'required non-empty string');
        }
        $this->sessionId = $sessionId;

        $c = strtolower($channel);
        if (!in_array($c, self::$ALLOWED_CHANNELS, true)) {
            throw ValidationException::forField(
                'channel',
                'must be one of: ' . implode(', ', self::$ALLOWED_CHANNELS)
            );
        }
        $this->channel = $c;

        // maskedTarget boleh kosong kalau provider tidak sediakan
        $this->maskedTarget = $maskedTarget;

        $this->expiresAt = $expiresAt > 0 ? $expiresAt : 0;

        $this->attemptsRemaining = $attemptsRemaining < 0 ? 0 : $attemptsRemaining;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getMaskedTarget(): string
    {
        return $this->maskedTarget;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getAttemptsRemaining(): int
    {
        return $this->attemptsRemaining;
    }

    /**
     * Kalau `expiresAt` 0 (unknown), treat as not-expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt <= 0) return false;
        return $this->expiresAt < time();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'channel' => $this->channel,
            'masked_target' => $this->maskedTarget,
            'expires_at' => $this->expiresAt,
            'attempts_remaining' => $this->attemptsRemaining,
        ];
    }
}
