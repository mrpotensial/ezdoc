<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\SignResult — DTO output dari `SignatureProvider::sign()`.
 *
 * PHP 7.4 compatible. Immutable by convention.
 *
 * @example
 *   $res = new SignResult([
 *       'envelope'       => $binaryEnvelope,
 *       'envelopeFormat' => 'hmac',
 *       'signerId'       => 'user:42',
 *       'providerName'   => 'hmac',
 *       'level'          => 1,
 *       'signedAt'       => '2026-07-10T03:14:15.123Z',
 *   ]);
 */
final class SignResult
{
    /** @var string */
    private $envelope;

    /** @var string */
    private $envelopeFormat;

    /** @var string */
    private $signerId;

    /** @var string|null */
    private $certificatePem;

    /** @var string */
    private $providerName;

    /** @var int 1 | 2 | 3 */
    private $level;

    /** @var string ISO 8601 UTC (milliseconds precision). */
    private $signedAt;

    /** @var array<string,mixed> */
    private $metadata;

    /**
     * @param array<string,mixed> $data
     * @throws ValidationException
     */
    public function __construct(array $data)
    {
        // envelope — required (may be binary, non-empty)
        if (!array_key_exists('envelope', $data) || !is_string($data['envelope']) || $data['envelope'] === '') {
            throw ValidationException::forField('envelope', 'required non-empty string');
        }
        $this->envelope = $data['envelope'];

        // envelopeFormat — required
        if (!array_key_exists('envelopeFormat', $data) || !is_string($data['envelopeFormat']) || $data['envelopeFormat'] === '') {
            throw ValidationException::forField('envelopeFormat', 'required non-empty string');
        }
        $this->envelopeFormat = strtolower($data['envelopeFormat']);

        // signerId — required
        if (!array_key_exists('signerId', $data) || !is_string($data['signerId']) || $data['signerId'] === '') {
            throw ValidationException::forField('signerId', 'required non-empty string');
        }
        $this->signerId = $data['signerId'];

        // certificatePem — optional
        $this->certificatePem = null;
        if (isset($data['certificatePem']) && is_string($data['certificatePem']) && $data['certificatePem'] !== '') {
            $this->certificatePem = $data['certificatePem'];
        }

        // providerName — required
        if (!array_key_exists('providerName', $data) || !is_string($data['providerName']) || $data['providerName'] === '') {
            throw ValidationException::forField('providerName', 'required non-empty string');
        }
        $this->providerName = $data['providerName'];

        // level — required, 1|2|3
        if (!array_key_exists('level', $data) || !is_numeric($data['level'])) {
            throw ValidationException::forField('level', 'required integer 1|2|3');
        }
        $lvl = (int) $data['level'];
        if ($lvl < 1 || $lvl > 3) {
            throw ValidationException::forField('level', 'must be 1, 2, or 3');
        }
        $this->level = $lvl;

        // signedAt — required (ISO 8601)
        if (!array_key_exists('signedAt', $data) || !is_string($data['signedAt']) || $data['signedAt'] === '') {
            throw ValidationException::forField('signedAt', 'required ISO 8601 UTC string');
        }
        $this->signedAt = $data['signedAt'];

        // metadata — optional array
        $meta = isset($data['metadata']) ? $data['metadata'] : [];
        if (!is_array($meta)) {
            throw ValidationException::forField('metadata', 'must be array');
        }
        $this->metadata = $meta;
    }

    public function getEnvelope(): string
    {
        return $this->envelope;
    }

    public function getEnvelopeFormat(): string
    {
        return $this->envelopeFormat;
    }

    public function getSignerId(): string
    {
        return $this->signerId;
    }

    public function getCertificatePem(): ?string
    {
        return $this->certificatePem;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getSignedAt(): string
    {
        return $this->signedAt;
    }

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Envelope may contain binary; encode base64 untuk transport JSON.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'envelope_b64' => base64_encode($this->envelope),
            'envelope_format' => $this->envelopeFormat,
            'signer_id' => $this->signerId,
            'certificate_pem' => $this->certificatePem,
            'provider_name' => $this->providerName,
            'level' => $this->level,
            'signed_at' => $this->signedAt,
            'metadata' => $this->metadata,
        ];
    }

    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }
}
