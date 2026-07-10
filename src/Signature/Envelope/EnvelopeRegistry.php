<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Envelope\EnvelopeRegistry — mapper format string → Envelope.
 *
 * Registry sederhana in-memory. Dipakai router/consumer untuk resolve
 * envelope handler berdasarkan `SignResult::envelopeFormat` atau kolom
 * `envelope_format` di storage.
 *
 * ## Default registry
 *
 * `defaultRegistry()` mendaftar built-in envelope:
 *   - 'raw'   → RawEnvelope
 *   - 'hmac'  → HmacEnvelope
 *   - 'pkcs7' → PkcsSevenEnvelope (default detached, no CA bundle)
 *
 * Consumer yang butuh CA bundle / attached mode bisa `register()` ulang
 * dengan instance kustom, meng-override entry default.
 *
 * PHP 7.4+ compatible.
 */
final class EnvelopeRegistry
{
    /** @var array<string,Envelope> */
    private $envelopes;

    public function __construct()
    {
        $this->envelopes = [];
    }

    /**
     * Register / replace envelope handler untuk format tertentu.
     *
     * @throws ValidationException format kosong
     */
    public function register(string $format, Envelope $envelope): void
    {
        if ($format === '') {
            throw ValidationException::forField('format', 'must be non-empty string');
        }
        $fmt = strtolower($format);
        $this->envelopes[$fmt] = $envelope;
    }

    /**
     * Resolve envelope handler by format.
     *
     * @throws NotFoundException format belum di-register
     */
    public function get(string $format): Envelope
    {
        $fmt = strtolower($format);
        if (!isset($this->envelopes[$fmt])) {
            throw NotFoundException::forResource('envelope_format', $format);
        }
        return $this->envelopes[$fmt];
    }

    public function has(string $format): bool
    {
        return isset($this->envelopes[strtolower($format)]);
    }

    /**
     * @return array<string> daftar format yang ter-register
     */
    public function getFormats(): array
    {
        return array_keys($this->envelopes);
    }

    /**
     * Factory: registry dengan built-in envelope pre-registered.
     *
     * PKCS7 didaftar dengan konfigurasi minimal (no CA bundle, detached).
     * Consumer yang butuh chain validation harus `register('pkcs7', new PkcsSevenEnvelope('/path/to/ca.pem'))`
     * untuk override.
     */
    public static function defaultRegistry(): self
    {
        $reg = new self();
        $reg->register(RawEnvelope::FORMAT, new RawEnvelope());
        $reg->register(HmacEnvelope::FORMAT, new HmacEnvelope());
        $reg->register(PkcsSevenEnvelope::FORMAT, new PkcsSevenEnvelope('', true));
        return $reg;
    }
}
