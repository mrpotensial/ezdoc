<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Envelope\Envelope — kontrak abstraksi wadah signature.
 *
 * Envelope memisahkan **cara memproduksi bytes signature** (tanggung jawab
 * `SignatureProvider`) dari **cara membungkus dan membuka bytes tersebut
 * untuk transport / storage / verify** (tanggung jawab Envelope).
 *
 * ## Kenapa dipisah dari Provider?
 *
 * Satu provider dapat memakai beberapa envelope format (mis. LocalPki
 * boleh output 'raw' atau di-repack ke 'pkcs7'), dan satu envelope format
 * bisa dipakai lintas provider (mis. 'pkcs7' oleh LocalPki, HSM provider,
 * remote PKI, dsb). Envelope abstraction menghindari kombinasi eksplosif
 * di provider dan menyeragamkan cara consumer parse/verify.
 *
 * ## Kontrak
 *
 * - `pack()` idempotent secara struktur: input sama → output ekuivalen
 *   secara semantik (bytes boleh berbeda kalau ada nonce/timestamp).
 * - `unpack()` boleh throw `EzdocException` kalau envelope corrupt
 *   struktural (bukan tampered — itu urusan verify).
 * - `verify()` return array dengan kunci `valid` (bool) dan `reason`
 *   (string). TIDAK throw untuk gagal verify — struktur logic pipeline
 *   sama dengan `SignatureProvider::verify()`.
 *
 * ## Format string
 *
 * `getFormat()` mengembalikan salah satu dari: 'raw', 'hmac', 'pkcs7',
 * 'pades', 'xades'. Konsisten dgn `ProviderCapabilities::getSupportsEnvelopeFormats()`.
 *
 * PHP 7.4+ compatible.
 */
interface Envelope
{
    /**
     * Identifier format envelope (mis. 'raw', 'hmac', 'pkcs7', 'pades', 'xades').
     *
     * @return string
     */
    public function getFormat(): string;

    /**
     * Bungkus signature bytes + content ke wire format envelope.
     *
     * @param string               $signature raw signature bytes hasil provider
     * @param string               $content   content asli (dipakai envelope yang
     *                                        embed payload atau butuh hash ulang;
     *                                        detached envelope boleh ignore)
     * @param X509Certificate|null $cert      signer cert (opsional; envelope
     *                                        yang wajib cert akan throw kalau null)
     * @param array<string,mixed>  $options   flag khusus format (mis. attach mode,
     *                                        untrusted-cert bundle path, dsb)
     * @return string envelope bytes (PEM/DER/format-specific)
     * @throws \Ezdoc\Exceptions\EzdocException technical failure
     */
    public function pack(string $signature, string $content, X509Certificate $cert = null, array $options = []): string;

    /**
     * Parse envelope bytes → komponen: signature, signer cert, timestamp, metadata.
     *
     * Return array shape:
     *   [
     *     'signature'       => string (raw bytes signature),
     *     'signer_cert_pem' => string|null (PEM signer cert kalau ada),
     *     'timestamp'       => int|null    (Unix timestamp signingTime kalau ada),
     *     'metadata'        => array<string,mixed> (info tambahan format-specific),
     *   ]
     *
     * @param string $envelopeBytes
     * @return array<string,mixed>
     * @throws \Ezdoc\Exceptions\EzdocException envelope corrupt / unparseable
     */
    public function unpack(string $envelopeBytes): array;

    /**
     * Verifikasi envelope terhadap content asli.
     *
     * Return array shape:
     *   [
     *     'valid'       => bool,
     *     'reason'      => string (human-readable),
     *     'signer_info' => array<string,mixed> (subject_cn, serial, dst),
     *     'checks'      => array<string,mixed> (per-step result: signature=ok, chain=..., etc),
     *   ]
     *
     * @param string              $envelopeBytes
     * @param string              $originalContent
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function verify(string $envelopeBytes, string $originalContent, array $options = []): array;

    /**
     * Apakah envelope format ini mendukung detached signature
     * (content disimpan terpisah dari envelope).
     *
     * @return bool
     */
    public function canDetached(): bool;
}
