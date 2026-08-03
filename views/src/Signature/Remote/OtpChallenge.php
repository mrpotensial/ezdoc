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

    /**
     * Factory dari assoc array — PSrE response variants normalized here:
     *   - Field aliases: `channel` OR `otp_channel`; `masked_target` OR
     *     `otp_masked_target`; `attempts_remaining` OR
     *     `otp_attempts_remaining`.
     *   - `expires_at` OR `resend_available_at` accepts ISO 8601 string
     *     OR unix ts int.
     *   - Channel enum: `SMS`/`EMAIL`/`WA`/`APP`/`PRIVY_APP` case-normalized;
     *     unknown → default 'sms'.
     *
     * @param array<string,mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $sessionId = isset($data['session_id']) ? (string) $data['session_id'] : '';

        // Field aliases (Peruri uses `otp_channel`, Privy uses `otp_channel`
        // atau `push_channel`, some use bare `channel`).
        $channelRaw = '';
        foreach (['channel', 'otp_channel', 'push_channel'] as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '') {
                $channelRaw = $data[$k];
                break;
            }
        }
        $channel = self::normalizeChannel($channelRaw);

        $maskedTarget = '';
        foreach (['masked_target', 'otp_masked_target', 'target_masked'] as $k) {
            if (isset($data[$k]) && is_string($data[$k])) {
                $maskedTarget = $data[$k];
                break;
            }
        }

        $expiresAt = 0;
        foreach (['expires_at', 'resend_available_at', 'otp_expires_at'] as $k) {
            if (!isset($data[$k])) continue;
            $raw = $data[$k];
            if (is_int($raw)) { $expiresAt = $raw; break; }
            if (is_string($raw) && $raw !== '') {
                if (ctype_digit($raw)) { $expiresAt = (int) $raw; break; }
                $t = strtotime($raw);
                if ($t !== false) { $expiresAt = $t; break; }
            }
        }

        $attempts = 3; // sensible default
        foreach (['attempts_remaining', 'otp_attempts_remaining'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                $attempts = (int) $data[$k];
                break;
            }
        }

        return new self($sessionId, $channel, $maskedTarget, $expiresAt, $attempts);
    }

    /**
     * Normalize channel string to canonical CHANNEL_* constant.
     * Unknown values default to 'sms' (safest fallback — SMS is most
     * universally supported PSrE OTP channel).
     */
    private static function normalizeChannel(string $raw): string
    {
        $lc = strtolower(str_replace(['-', ' ', '_'], '', $raw));
        $mapping = [
            'email'    => self::CHANNEL_EMAIL,
            'mail'     => self::CHANNEL_EMAIL,
            'sms'      => self::CHANNEL_SMS,
            'text'     => self::CHANNEL_SMS,
            'wa'       => self::CHANNEL_WA,
            'whatsapp' => self::CHANNEL_WA,
            'app'      => self::CHANNEL_APP,
            'privyapp' => self::CHANNEL_APP,
            'push'     => self::CHANNEL_APP,
            'mobile'   => self::CHANNEL_APP,
        ];
        return isset($mapping[$lc]) ? $mapping[$lc] : self::CHANNEL_SMS;
    }
}
