<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

/**
 * Ezdoc\Signature\Verdict — hasil `SignatureProvider::verify()`.
 *
 * Value object immutable. Constant `STATUS_*` bertindak sebagai enum
 * (PHP 7.4 tidak punya native enum).
 *
 * PHP 7.4 compatible.
 */
final class Verdict
{
    const STATUS_VALID = 'valid';
    const STATUS_TAMPERED = 'tampered';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REVOKED = 'revoked';
    const STATUS_UNTRUSTED = 'untrusted';
    const STATUS_ERROR = 'error';

    /** @var array<string> */
    private static $ALLOWED_STATUSES = [
        self::STATUS_VALID,
        self::STATUS_TAMPERED,
        self::STATUS_EXPIRED,
        self::STATUS_REVOKED,
        self::STATUS_UNTRUSTED,
        self::STATUS_ERROR,
    ];

    /** @var string */
    private $status;

    /** @var string|null */
    private $signerId;

    /** @var string|null */
    private $signedAt;

    /** @var string */
    private $reason;

    /** @var array<string,mixed> Detail cek (mis. cert_chain=ok, tsa=ok). */
    private $checks;

    /**
     * @param string              $status   salah satu STATUS_*
     * @param string|null         $signerId
     * @param string|null         $signedAt ISO 8601 UTC atau null
     * @param string              $reason   human-readable
     * @param array<string,mixed> $checks
     */
    public function __construct(
        string $status,
        ?string $signerId,
        ?string $signedAt,
        string $reason,
        array $checks
    ) {
        $s = strtolower($status);
        if (!in_array($s, self::$ALLOWED_STATUSES, true)) {
            $s = self::STATUS_ERROR;
        }
        $this->status = $s;
        $this->signerId = $signerId;
        $this->signedAt = $signedAt;
        $this->reason = $reason;
        $this->checks = $checks;
    }

    /**
     * Static factory: signature valid.
     */
    public static function valid(?string $signerId, ?string $signedAt, array $checks = []): self
    {
        return new self(self::STATUS_VALID, $signerId, $signedAt, 'signature valid', $checks);
    }

    /**
     * Static factory: content tampered (HMAC/hash mismatch).
     */
    public static function tampered(string $reason, array $checks = []): self
    {
        return new self(self::STATUS_TAMPERED, null, null, $reason, $checks);
    }

    /**
     * Static factory: signature struktur valid tapi trust chain gagal.
     */
    public static function untrusted(string $reason, array $checks = []): self
    {
        return new self(self::STATUS_UNTRUSTED, null, null, $reason, $checks);
    }

    /**
     * Static factory: kegagalan teknis non-forensic (mis. envelope corrupt).
     */
    public static function error(string $reason, array $checks = []): self
    {
        return new self(self::STATUS_ERROR, null, null, $reason, $checks);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /**
     * Denied = valid-by-shape tapi tidak boleh dipercaya.
     * (tampered, expired, revoked, untrusted). ERROR bukan denied.
     */
    public function isDenied(): bool
    {
        return in_array($this->status, [
            self::STATUS_TAMPERED,
            self::STATUS_EXPIRED,
            self::STATUS_REVOKED,
            self::STATUS_UNTRUSTED,
        ], true);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /** @return array<string,mixed> */
    public function getChecks(): array
    {
        return $this->checks;
    }

    public function getSignerId(): ?string
    {
        return $this->signerId;
    }

    public function getSignedAt(): ?string
    {
        return $this->signedAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'signer_id' => $this->signerId,
            'signed_at' => $this->signedAt,
            'reason' => $this->reason,
            'checks' => $this->checks,
        ];
    }
}
