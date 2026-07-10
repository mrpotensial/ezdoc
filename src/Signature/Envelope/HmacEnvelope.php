<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Envelope\HmacEnvelope — envelope untuk HMAC hex digest.
 *
 * Wire format: `hmac:<hex-lowercase>`. Prefix `hmac:` memberi domain
 * separation supaya loader/router bisa sniff format tanpa harus baca
 * metadata di kolom lain (mis. QR verify URL yang carry envelope inline).
 *
 * Consumer boleh strip prefix di transport singkat (mis. URL slug); kelas
 * ini menerima kedua bentuk (prefix ada / tidak) di `unpack()` supaya
 * tetap tolerant.
 *
 * `verify()` di sini TIDAK melakukan verifikasi kriptografis — itu urusan
 * `HmacProvider::verify()` yang punya akses ke shared secret. Envelope
 * ini hanya sanitize + unwrap.
 *
 * PHP 7.4+ compatible.
 */
final class HmacEnvelope implements Envelope
{
    /** @var string */
    const FORMAT = 'hmac';

    /** @var string */
    const PREFIX = 'hmac:';

    /**
     * {@inheritdoc}
     */
    public function getFormat(): string
    {
        return self::FORMAT;
    }

    /**
     * {@inheritdoc}
     *
     * `$signature` diharapkan hex-encoded (output `HmacProvider::sign()`).
     * Kalau input berupa raw binary (mis. dari `hash_hmac($algo, $data, $key, true)`),
     * caller wajib bin2hex() lebih dulu — envelope ini tidak menebak.
     *
     * @throws EzdocException kalau input bukan hex
     */
    public function pack(string $signature, string $content, X509Certificate $cert = null, array $options = []): string
    {
        if ($signature === '') {
            throw new EzdocException('HmacEnvelope::pack(): empty signature');
        }
        $hex = strtolower($signature);
        // Terima input yang sudah ter-prefix (idempotent).
        if (strpos($hex, self::PREFIX) === 0) {
            $hex = substr($hex, strlen(self::PREFIX));
        }
        if (!preg_match('/^[0-9a-f]+$/', $hex)) {
            throw new EzdocException(
                'HmacEnvelope::pack(): signature must be hex-encoded (got non-hex chars)'
            );
        }
        return self::PREFIX . $hex;
    }

    /**
     * {@inheritdoc}
     *
     * Terima input dgn atau tanpa prefix. Non-hex → throw.
     *
     * @throws EzdocException envelope corrupt
     */
    public function unpack(string $envelopeBytes): array
    {
        if ($envelopeBytes === '') {
            throw new EzdocException('HmacEnvelope::unpack(): empty envelope');
        }
        $bytes = strtolower($envelopeBytes);
        if (strpos($bytes, self::PREFIX) === 0) {
            $bytes = substr($bytes, strlen(self::PREFIX));
        }
        if (!preg_match('/^[0-9a-f]+$/', $bytes)) {
            throw new EzdocException(
                'HmacEnvelope::unpack(): envelope body not hex-encoded'
            );
        }
        return [
            'signature' => $bytes,
            'signer_cert_pem' => null,
            'timestamp' => null,
            'metadata' => [
                'format' => self::FORMAT,
                'length' => strlen($bytes),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Non-crypto: hanya sniff struktur. Actual HMAC compare dilakukan
     * `HmacProvider::verify()`.
     */
    public function verify(string $envelopeBytes, string $originalContent, array $options = []): array
    {
        if ($envelopeBytes === '') {
            return [
                'valid' => false,
                'reason' => 'empty envelope',
                'signer_info' => [],
                'checks' => ['non_empty' => false],
            ];
        }
        $bytes = strtolower($envelopeBytes);
        if (strpos($bytes, self::PREFIX) === 0) {
            $bytes = substr($bytes, strlen(self::PREFIX));
        }
        if (!preg_match('/^[0-9a-f]+$/', $bytes)) {
            return [
                'valid' => false,
                'reason' => 'envelope body not hex-encoded',
                'signer_info' => [],
                'checks' => ['hex_shape' => false],
            ];
        }
        return [
            'valid' => true,
            'reason' => 'shape ok; delegated to provider verify for HMAC compare',
            'signer_info' => [],
            'checks' => [
                'hex_shape' => true,
                'delegated' => true,
                'length' => strlen($bytes),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * HMAC selalu detached — content tidak pernah embedded ke envelope.
     */
    public function canDetached(): bool
    {
        return true;
    }
}
