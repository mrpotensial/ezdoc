<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\SignRequest — DTO input untuk `SignatureProvider::sign()`.
 *
 * PHP 7.4 compatible (no readonly, no promotion, no union types).
 * Fields populate sekali di constructor lewat associative array — pola
 * sama dengan `SaveDocumentRequest`.
 *
 * @example
 *   $req = new SignRequest([
 *       'contentBytes' => $canonicalJson,
 *       'signerId'     => 'user:42',
 *       'envelopeFormat' => 'hmac',
 *   ]);
 */
final class SignRequest
{
    /** @var string */
    private $contentBytes;

    /** @var string SHA-256 hex digest (64 chars). */
    private $contentHash;

    /** @var string */
    private $signerId;

    /** @var array<string,mixed> */
    private $signerContext;

    /** @var string */
    private $envelopeFormat;

    /** @var array<string,mixed> */
    private $metadata;

    /** @var array<string> Envelope formats yang dikenal library. */
    private static $KNOWN_FORMATS = ['raw', 'hmac', 'pkcs7', 'pades'];

    /**
     * @param array<string,mixed> $data
     *   Keys:
     *     - contentBytes   (string, required) raw bytes to sign
     *     - contentHash    (string, optional) pre-computed SHA-256 hex;
     *                      auto-compute dari contentBytes kalau kosong
     *     - signerId       (string, required) identity opaque
     *     - signerContext  (array,  optional) {user_id, role, ip, ua, ...}
     *     - envelopeFormat (string, default 'raw') salah satu KNOWN_FORMATS
     *     - metadata       (array,  optional)
     *
     * @throws ValidationException
     */
    public function __construct(array $data)
    {
        // contentBytes — required (non-empty string; boleh binary)
        if (!array_key_exists('contentBytes', $data) || !is_string($data['contentBytes']) || $data['contentBytes'] === '') {
            throw ValidationException::forField('contentBytes', 'required non-empty string');
        }
        $this->contentBytes = $data['contentBytes'];

        // contentHash — optional; auto-compute kalau kosong
        $rawHash = isset($data['contentHash']) ? $data['contentHash'] : null;
        if ($rawHash === null || $rawHash === '') {
            $this->contentHash = hash('sha256', $this->contentBytes);
        } else {
            if (!is_string($rawHash) || !preg_match('/^[0-9a-f]{64}$/i', $rawHash)) {
                throw ValidationException::forField('contentHash', 'must be 64-char SHA-256 hex when provided');
            }
            $this->contentHash = strtolower($rawHash);
        }

        // signerId — required
        if (!array_key_exists('signerId', $data) || !is_string($data['signerId']) || $data['signerId'] === '') {
            throw ValidationException::forField('signerId', 'required non-empty string');
        }
        $sid = $data['signerId'];
        if (strlen($sid) > 255) $sid = substr($sid, 0, 255);
        $this->signerId = $sid;

        // signerContext — optional array
        $sctx = isset($data['signerContext']) ? $data['signerContext'] : [];
        if (!is_array($sctx)) {
            throw ValidationException::forField('signerContext', 'must be array');
        }
        $this->signerContext = $sctx;

        // envelopeFormat — optional, default 'raw'
        $fmt = 'raw';
        if (isset($data['envelopeFormat']) && is_string($data['envelopeFormat']) && $data['envelopeFormat'] !== '') {
            $fmt = strtolower($data['envelopeFormat']);
        }
        if (!in_array($fmt, self::$KNOWN_FORMATS, true)) {
            throw ValidationException::forField(
                'envelopeFormat',
                'must be one of: ' . implode(', ', self::$KNOWN_FORMATS)
            );
        }
        $this->envelopeFormat = $fmt;

        // metadata — optional array
        $meta = isset($data['metadata']) ? $data['metadata'] : [];
        if (!is_array($meta)) {
            throw ValidationException::forField('metadata', 'must be array');
        }
        $this->metadata = $meta;
    }

    public function getContentBytes(): string
    {
        return $this->contentBytes;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getSignerId(): string
    {
        return $this->signerId;
    }

    /** @return array<string,mixed> */
    public function getSignerContext(): array
    {
        return $this->signerContext;
    }

    public function getEnvelopeFormat(): string
    {
        return $this->envelopeFormat;
    }

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
