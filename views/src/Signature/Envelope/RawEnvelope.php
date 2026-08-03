<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Envelope\RawEnvelope — passthrough envelope.
 *
 * Tidak melakukan wrapping — signature bytes ditransportasikan apa adanya.
 * Cocok untuk provider yang produce raw signature (mis. LocalPki dengan
 * openssl_sign OPENSSL_ALGO_SHA256) dan consumer yang menyimpan
 * certificate + metadata di kolom terpisah (bukan di envelope itu sendiri).
 *
 * `verify()` di sini adalah TRIVIAL — envelope ini tidak tahu algoritma
 * atau cert. Consumer wajib jalankan verify via `SignatureProvider::verify()`
 * (yang punya akses ke KeyStore + algo). Method ini hanya memberi shape
 * konsisten (bool `valid` selalu true selama bytes non-empty) supaya
 * caller yang loop-lintas-envelope tidak crash.
 *
 * PHP 7.4+ compatible.
 */
final class RawEnvelope implements Envelope
{
    /** @var string */
    const FORMAT = 'raw';

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
     * Passthrough: signature dikembalikan apa adanya. Content, cert, options
     * di-ignore — envelope 'raw' tidak wrap apapun.
     */
    public function pack(string $signature, string $content, X509Certificate $cert = null, array $options = []): string
    {
        return $signature;
    }

    /**
     * {@inheritdoc}
     *
     * Passthrough: envelope bytes adalah signature itu sendiri.
     */
    public function unpack(string $envelopeBytes): array
    {
        return [
            'signature' => $envelopeBytes,
            'signer_cert_pem' => null,
            'timestamp' => null,
            'metadata' => [
                'format' => self::FORMAT,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Trivial verify — hanya cek non-empty. Verifikasi kriptografis harus
     * ditangani `SignatureProvider::verify()` di layer atas.
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
        return [
            'valid' => true,
            'reason' => 'passthrough envelope; delegated to provider verify',
            'signer_info' => [],
            'checks' => [
                'non_empty' => true,
                'delegated' => true,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function canDetached(): bool
    {
        return true;
    }
}
