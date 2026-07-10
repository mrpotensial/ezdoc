<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Remote;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Remote\SignSession — DTO state multi-step signing
 * session (initiate → verify OTP → completion) untuk provider PSrE
 * remote (Privy, VIDA, Digisign, BSrE, Peruri, dsb).
 *
 * ## Lifecycle
 *
 *   pending
 *      → otp_required   (dispatch OTP ke channel SMS/email/WA/app)
 *          → processing (OTP submitted, PSrE sedang generate signature)
 *              → completed (envelope siap diambil)
 *              → failed    (OTP salah / cert issue / timeout server)
 *      → processing     (langsung, kalau flow tidak butuh OTP)
 *      → expired        (session timeout)
 *      → failed         (technical error di sisi provider)
 *
 * ## Persistence
 *
 * DTO ini di-persist ke tabel `sign_sessions` (schema di Migrations)
 * dengan external_session_id = getSessionId(). Retry & audit trail
 * dilakukan di layer service, bukan di DTO.
 *
 * PHP 7.4+ compatible. Immutable by convention.
 */
final class SignSession
{
    const STATUS_PENDING = 'pending';
    const STATUS_OTP_REQUIRED = 'otp_required';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_FAILED = 'failed';

    /** @var array<string> */
    private static $ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_OTP_REQUIRED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
        self::STATUS_FAILED,
    ];

    /** @var string opaque session id dari provider (idempotency key). */
    private $sessionId;

    /** @var string salah satu STATUS_*. */
    private $status;

    /** @var int unix timestamp; 0 kalau provider tidak sediakan. */
    private $expiresAt;

    /** @var array<string,mixed> raw provider payload (untuk audit & debug). */
    private $metadata;

    /**
     * @param string              $sessionId external session id (non-empty)
     * @param string              $status    salah satu STATUS_* (normalize lc)
     * @param int                 $expiresAt unix ts; 0 = unknown/no-expiry
     * @param array<string,mixed> $metadata  raw payload extras
     * @throws ValidationException
     */
    public function __construct(string $sessionId, string $status, int $expiresAt, array $metadata = [])
    {
        if ($sessionId === '') {
            throw ValidationException::forField('sessionId', 'required non-empty string');
        }
        if (strlen($sessionId) > 128) {
            throw ValidationException::forField('sessionId', 'must be <= 128 chars');
        }
        $this->sessionId = $sessionId;

        $s = strtolower($status);
        if (!in_array($s, self::$ALLOWED_STATUSES, true)) {
            throw ValidationException::forField(
                'status',
                'must be one of: ' . implode(', ', self::$ALLOWED_STATUSES)
            );
        }
        $this->status = $s;

        $this->expiresAt = $expiresAt > 0 ? $expiresAt : 0;
        $this->metadata = $metadata;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Cek expiry berdasarkan clock lokal. Kalau `expiresAt` 0, return false
     * (unknown → treat as not-expired; caller boleh polling).
     */
    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) return true;
        if ($this->expiresAt <= 0) return false;
        return $this->expiresAt < time();
    }

    public function needsOtp(): bool
    {
        return $this->status === self::STATUS_OTP_REQUIRED;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'status' => $this->status,
            'expires_at' => $this->expiresAt,
            'metadata' => $this->metadata,
        ];
    }
}
