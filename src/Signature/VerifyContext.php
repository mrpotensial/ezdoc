<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\VerifyContext — input untuk `SignatureProvider::verify()`.
 *
 * PHP 7.4 compatible. Immutable — hanya getters.
 *
 * @example
 *   $ctx = new VerifyContext([
 *       'contentBytes'     => $canonicalJson,
 *       'expectedSignerId' => 'user:42',
 *       'providerHint'     => 'hmac',
 *   ]);
 */
final class VerifyContext
{
    /** @var string */
    private $contentBytes;

    /** @var string|null */
    private $expectedSignerId;

    /** @var string|null */
    private $providerHint;

    /** @var array<string,mixed> */
    private $metadata;

    /**
     * @param array<string,mixed> $data
     *   Keys:
     *     - contentBytes     (string, required) original bytes untuk re-hash
     *     - expectedSignerId (string, optional) enforce signer match
     *     - providerHint     (string, optional) hint provider yang dipakai
     *     - metadata         (array,  optional)
     *
     * @throws ValidationException
     */
    public function __construct(array $data)
    {
        if (!array_key_exists('contentBytes', $data) || !is_string($data['contentBytes']) || $data['contentBytes'] === '') {
            throw ValidationException::forField('contentBytes', 'required non-empty string');
        }
        $this->contentBytes = $data['contentBytes'];

        $this->expectedSignerId = null;
        if (isset($data['expectedSignerId']) && is_string($data['expectedSignerId']) && $data['expectedSignerId'] !== '') {
            $this->expectedSignerId = $data['expectedSignerId'];
        }

        $this->providerHint = null;
        if (isset($data['providerHint']) && is_string($data['providerHint']) && $data['providerHint'] !== '') {
            $this->providerHint = strtolower($data['providerHint']);
        }

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

    public function getExpectedSignerId(): ?string
    {
        return $this->expectedSignerId;
    }

    public function getProviderHint(): ?string
    {
        return $this->providerHint;
    }

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
