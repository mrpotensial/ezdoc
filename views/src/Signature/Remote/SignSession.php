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

    /**
     * Factory dari assoc array — PSrE providers biasanya return raw JSON
     * dgn field names + status enums provider-specific. Factory ini
     * normalize:
     *   - Field name: `session_id`, `status`, `expires_at` mandatory;
     *     `expires_at` accepts ISO 8601 string OR unix ts int.
     *   - Status enum: provider variants (e.g. Peruri `AWAITING_OTP`,
     *     `SIGNED`; Privy `AWAITING_APPROVAL`) mapped ke canonical
     *     STATUS_* constants.
     *   - Sisa field disimpan sebagai `metadata` untuk audit trail.
     *
     * @param array<string,mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $sessionId = isset($data['session_id']) ? (string) $data['session_id'] : '';
        $status = isset($data['status']) ? (string) $data['status'] : self::STATUS_PENDING;
        $status = self::normalizeStatus($status);

        $expiresAt = 0;
        if (isset($data['expires_at'])) {
            $raw = $data['expires_at'];
            if (is_int($raw)) {
                $expiresAt = $raw;
            } elseif (is_string($raw) && $raw !== '') {
                if (ctype_digit($raw)) {
                    $expiresAt = (int) $raw;
                } else {
                    $t = strtotime($raw);
                    if ($t !== false) $expiresAt = $t;
                }
            }
        }

        // Metadata = semua key selain 3 core fields, untuk audit trail.
        $metadata = $data;
        unset($metadata['session_id'], $metadata['status'], $metadata['expires_at']);

        return new self($sessionId, $status, $expiresAt, $metadata);
    }

    /**
     * Normalize provider-specific status enum ke canonical STATUS_* constants.
     * Case-insensitive, handles hyphens/spaces/underscores.
     */
    private static function normalizeStatus(string $raw): string
    {
        $lc = strtolower(str_replace(['-', ' '], '_', $raw));
        $mapping = [
            'signed'             => self::STATUS_COMPLETED,
            'success'            => self::STATUS_COMPLETED,
            'success_signed'     => self::STATUS_COMPLETED,
            'awaiting_otp'       => self::STATUS_OTP_REQUIRED,
            'awaiting_approval'  => self::STATUS_OTP_REQUIRED,
            'awaiting_signer'    => self::STATUS_OTP_REQUIRED,
            'in_progress'        => self::STATUS_PROCESSING,
            'processing_signing' => self::STATUS_PROCESSING,
            'cancelled'          => self::STATUS_FAILED,
            'canceled'           => self::STATUS_FAILED,
            'rejected'           => self::STATUS_FAILED,
            'timed_out'          => self::STATUS_EXPIRED,
            'timeout'            => self::STATUS_EXPIRED,
        ];
        if (isset($mapping[$lc])) return $mapping[$lc];
        if (in_array($lc, self::$ALLOWED_STATUSES, true)) return $lc;
        return self::STATUS_PENDING; // safe default untuk unknown enum
    }
}
