<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Timestamp;

/**
 * Ezdoc\Signature\Timestamp\TimestampVerdict — hasil {@see TimestampClient::verifyTimestamp()}.
 *
 * Value object immutable. Bedanya dengan {@see \Ezdoc\Signature\Verdict}:
 * verdict ini scoped ke pemeriksaan timestamp saja (proof-of-existence
 * pada waktu tertentu), bukan verify PKCS7/HMAC secara keseluruhan.
 *
 * ## States
 *
 *   - valid=true                    → timestamp bisa dipercaya
 *   - valid=false, timestampAt=null → gagal (mismatch, malformed, dsb)
 *   - valid=false, timestampAt=int  → token secara struktural OK tapi
 *                                     trust chain gagal (mis. TSA cert
 *                                     tidak ada di CA bundle). "untrusted"
 *
 * PHP 7.4+ compatible.
 */
final class TimestampVerdict
{
    /** @var bool */
    private $valid;

    /** @var string alasan human-readable */
    private $reason;

    /** @var int|null unix timestamp dari TSTInfo.genTime, kalau ada */
    private $timestampAt;

    /** @var array<string,mixed> detail pengecekan */
    private $checks;

    /**
     * @param bool                $valid
     * @param string              $reason
     * @param int|null            $timestampAt
     * @param array<string,mixed> $checks
     */
    public function __construct(bool $valid, string $reason, ?int $timestampAt, array $checks)
    {
        $this->valid = $valid;
        $this->reason = $reason;
        $this->timestampAt = $timestampAt;
        $this->checks = $checks;
    }

    /**
     * Static factory: timestamp valid.
     *
     * @param int                 $timestampAt unix ts dari TSA
     * @param string              $reason      opsional, default 'timestamp valid'
     * @param array<string,mixed> $checks
     */
    public static function valid(int $timestampAt, string $reason = '', array $checks = []): self
    {
        if ($reason === '') $reason = 'timestamp valid';
        return new self(true, $reason, $timestampAt, $checks);
    }

    /**
     * Static factory: token invalid (mismatch / malformed / TSA reject).
     *
     * @param array<string,mixed> $checks
     */
    public static function invalid(string $reason, array $checks = []): self
    {
        return new self(false, $reason, null, $checks);
    }

    /**
     * Static factory: token secara struktural valid tapi trust gagal.
     * Berbeda dari `invalid()`: caller mungkin masih mau menyimpan
     * timestamp sebagai proof-of-existence weak (mis. dev/test).
     *
     * @param array<string,mixed> $checks
     */
    public static function untrusted(string $reason, array $checks = []): self
    {
        return new self(false, $reason, null, $checks);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getTimestampAt(): ?int
    {
        return $this->timestampAt;
    }

    /** @return array<string,mixed> */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'reason' => $this->reason,
            'timestamp_at' => $this->timestampAt,
            'timestamp_at_iso' => $this->timestampAt !== null
                ? gmdate('Y-m-d\TH:i:s\Z', $this->timestampAt)
                : null,
            'checks' => $this->checks,
        ];
    }
}
