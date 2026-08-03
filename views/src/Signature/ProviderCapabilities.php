<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

/**
 * Ezdoc\Signature\ProviderCapabilities — declared capabilities of a
 * `SignatureProvider`. Consumer (registry / router) inspect this untuk
 * memilih provider yang cocok dengan level & envelope format yang diminta.
 *
 * PHP 7.4 compatible. Immutable value object.
 */
final class ProviderCapabilities
{
    /** @var string */
    private $providerName;

    /** @var int 1 | 2 | 3 */
    private $level;

    /** @var array<string> */
    private $supportsEnvelopeFormats;

    /** @var bool */
    private $supportsTimestamping;

    /** @var int|null Max content bytes; null = tak ada limit hard. */
    private $maxContentBytes;

    /** @var string */
    private $notes;

    /**
     * @param string        $providerName
     * @param int           $level 1|2|3
     * @param array<string> $supportsEnvelopeFormats mis. ['hmac','raw']
     * @param bool          $supportsTimestamping
     * @param int|null      $maxContentBytes
     * @param string        $notes deskripsi teknis singkat
     */
    public function __construct(
        string $providerName,
        int $level,
        array $supportsEnvelopeFormats,
        bool $supportsTimestamping,
        ?int $maxContentBytes,
        string $notes
    ) {
        $this->providerName = $providerName;
        // Clamp level ke 1..3
        if ($level < 1) $level = 1;
        if ($level > 3) $level = 3;
        $this->level = $level;

        // Normalize formats (lowercase, string only, deduped)
        $fmts = [];
        foreach ($supportsEnvelopeFormats as $f) {
            if (is_string($f) && $f !== '') {
                $fmts[strtolower($f)] = true;
            }
        }
        $this->supportsEnvelopeFormats = array_keys($fmts);

        $this->supportsTimestamping = $supportsTimestamping;
        $this->maxContentBytes = ($maxContentBytes !== null && $maxContentBytes > 0) ? $maxContentBytes : null;
        $this->notes = $notes;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    /** @return array<string> */
    public function getSupportsEnvelopeFormats(): array
    {
        return $this->supportsEnvelopeFormats;
    }

    public function supportsFormat(string $format): bool
    {
        return in_array(strtolower($format), $this->supportsEnvelopeFormats, true);
    }

    public function getSupportsTimestamping(): bool
    {
        return $this->supportsTimestamping;
    }

    public function getMaxContentBytes(): ?int
    {
        return $this->maxContentBytes;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider_name' => $this->providerName,
            'level' => $this->level,
            'supports_envelope_formats' => $this->supportsEnvelopeFormats,
            'supports_timestamping' => $this->supportsTimestamping,
            'max_content_bytes' => $this->maxContentBytes,
            'notes' => $this->notes,
        ];
    }
}
