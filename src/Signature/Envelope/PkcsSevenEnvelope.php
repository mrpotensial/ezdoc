<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\PrivateKey;
use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Envelope\PkcsSevenEnvelope — PKCS#7 / CMS SignedData
 * envelope (RFC 5652).
 *
 * ## Kenapa temp file?
 *
 * PHP `openssl_pkcs7_*` API menerima filesystem paths saja — tidak ada
 * variant string-buffer bahkan di PHP 8.4. `php://memory` juga tidak
 * kompatibel (C-level fopen path). Semua operasi wrap dalam
 * `tempnam() + chmod 0600 + try/finally unlink()` — pattern kanonis
 * yang aman dari race condition symlink.
 *
 * ## Detached vs Attached
 *
 * Default: detached (`PKCS7_DETACHED`). Envelope tidak carry content;
 * verifier wajib supply original bytes. Toggle via constructor kalau
 * ingin embed content.
 *
 * ## Cross-runtime OpenSSL
 *
 * `openssl_pkcs7_sign()` menerima cert+key sebagai resource / string
 * (PEM) / OpenSSLCertificate / OpenSSLAsymmetricKey — kita pass PEM
 * string dari `X509Certificate::getPem()` + resource dari `PrivateKey::getResource()`.
 * Konsisten di PHP 7.x resource dan PHP 8+ object.
 *
 * ## Signature vs Envelope
 *
 * Method `pack()` di kelas ini BUKAN pembungkus raw signature yang
 * sudah dihitung provider — PKCS7 melakukan proses sign dari cert+key
 * secara internal. Untuk memenuhi kontrak `Envelope::pack()`, kelas ini
 * WAJIB menerima cert (via param) + private key (via `options['private_key']`).
 * Kalau caller sudah punya raw signature dan ingin repack, itu use case
 * yang berbeda — tidak didukung karena PKCS7 SignedData butuh signer
 * info (issuer, serial, signedAttrs) yang tidak bisa dibangun ulang
 * dari raw bytes saja.
 *
 * PHP 7.4+ compatible.
 */
final class PkcsSevenEnvelope implements Envelope
{
    /** @var string */
    const FORMAT = 'pkcs7';

    /** @var string prefix tempnam untuk operasi PKCS7 */
    const TMP_PREFIX_IN = 'ezp7in_';

    /** @var string */
    const TMP_PREFIX_OUT = 'ezp7out_';

    /** @var string path ke CA bundle PEM (dipakai verify); kosong = skip chain check */
    private $caBundle;

    /** @var bool default detached mode */
    private $detached;

    /**
     * @param string $caBundle path ke CA bundle PEM (opsional; kosong → PKCS7_NOVERIFY di verify)
     * @param bool   $detached true = detached signature (default), false = attached
     * @throws ValidationException kalau caBundle non-empty tapi file tidak ada
     */
    public function __construct(string $caBundle = '', bool $detached = true)
    {
        if ($caBundle !== '' && !is_file($caBundle)) {
            throw ValidationException::forField(
                'caBundle',
                'CA bundle file not found: ' . $caBundle
            );
        }
        $this->caBundle = $caBundle;
        $this->detached = $detached;
    }

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
     * `$signature` parameter di-IGNORE — PKCS7_sign menghitung signature
     * sendiri dari cert+key. Ini keputusan sadar: PKCS7 SignedData tidak
     * bisa dibangun dari raw signature bytes tanpa signer info lengkap
     * (issuer/serial/signedAttrs).
     *
     * Options:
     *   - 'private_key' => PrivateKey (REQUIRED)
     *   - 'untrusted_bundle' => string (path ke PEM intermediates, opsional)
     *   - 'flags' => int (override default flags)
     *
     * @throws EzdocException technical failure (openssl gagal, IO, dsb)
     * @throws ValidationException kalau cert / private key tidak disediakan
     */
    public function pack(string $signature, string $content, X509Certificate $cert = null, array $options = []): string
    {
        if ($cert === null) {
            throw ValidationException::forField('cert', 'X509Certificate required for PKCS7 envelope');
        }
        if (!isset($options['private_key']) || !($options['private_key'] instanceof PrivateKey)) {
            throw ValidationException::forField(
                'private_key',
                'options["private_key"] must be a PrivateKey instance'
            );
        }
        /** @var PrivateKey $privKey */
        $privKey = $options['private_key'];

        $untrusted = null;
        if (isset($options['untrusted_bundle']) && is_string($options['untrusted_bundle']) && $options['untrusted_bundle'] !== '') {
            if (!is_file($options['untrusted_bundle'])) {
                throw ValidationException::forField(
                    'untrusted_bundle',
                    'intermediates bundle not found: ' . $options['untrusted_bundle']
                );
            }
            $untrusted = $options['untrusted_bundle'];
        }

        $defaultFlags = PKCS7_BINARY | PKCS7_NOATTR;
        if ($this->detached) {
            $defaultFlags |= PKCS7_DETACHED;
        }
        $flags = (isset($options['flags']) && is_int($options['flags']))
            ? $options['flags']
            : $defaultFlags;

        $inPath = self::mkTmp(self::TMP_PREFIX_IN);
        $outPath = self::mkTmp(self::TMP_PREFIX_OUT);
        try {
            if (file_put_contents($inPath, $content) === false) {
                throw new EzdocException('PkcsSevenEnvelope::pack(): failed to write temp input');
            }
            @chmod($inPath, 0600);
            @chmod($outPath, 0600);

            self::drainOpenSslErrors();

            $ok = openssl_pkcs7_sign(
                $inPath,
                $outPath,
                $cert->getPem(),
                $privKey->getResource(),
                [],
                $flags,
                $untrusted
            );
            if ($ok !== true) {
                $errs = self::drainOpenSslErrors();
                throw new EzdocException(
                    'openssl_pkcs7_sign() failed: ' . ($errs !== '' ? $errs : 'unknown')
                );
            }
            $bytes = @file_get_contents($outPath);
            if ($bytes === false || $bytes === '') {
                throw new EzdocException('PkcsSevenEnvelope::pack(): empty output from openssl_pkcs7_sign()');
            }
            return $bytes;
        } finally {
            self::safeUnlink($inPath);
            self::safeUnlink($outPath);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Extract signer cert(s) via `openssl_pkcs7_read()`. Signature bytes
     * TIDAK bisa dipisahkan dari container PKCS7 tanpa parse ASN.1 penuh
     * — kita return envelope bytes as-is di key 'signature' supaya
     * caller yang butuh re-verify punya sumber lengkap.
     *
     * @throws EzdocException envelope tidak parseable
     */
    public function unpack(string $envelopeBytes): array
    {
        if ($envelopeBytes === '') {
            throw new EzdocException('PkcsSevenEnvelope::unpack(): empty envelope');
        }

        $inPath = self::mkTmp(self::TMP_PREFIX_IN);
        try {
            if (file_put_contents($inPath, $envelopeBytes) === false) {
                throw new EzdocException('PkcsSevenEnvelope::unpack(): failed to write temp input');
            }
            @chmod($inPath, 0600);

            self::drainOpenSslErrors();

            $certs = [];
            $ok = openssl_pkcs7_read($inPath, $certs);
            if ($ok !== true) {
                $errs = self::drainOpenSslErrors();
                throw new EzdocException(
                    'openssl_pkcs7_read() failed: ' . ($errs !== '' ? $errs : 'unknown envelope structure')
                );
            }

            // Ambil signer cert pertama (kalau ada). Multi-signer envelope
            // bisa carry banyak — expose semua di metadata['all_certs'].
            $signerPem = null;
            if (!empty($certs) && isset($certs[0]) && is_string($certs[0]) && $certs[0] !== '') {
                $signerPem = $certs[0];
            }

            return [
                'signature' => $envelopeBytes,
                'signer_cert_pem' => $signerPem,
                'timestamp' => null, // signingTime attr tidak di-expose openssl PHP API
                'metadata' => [
                    'format' => self::FORMAT,
                    'is_pem' => self::isPemPkcs7($envelopeBytes),
                    'cert_count' => count($certs),
                    'all_certs' => $certs,
                ],
            ];
        } finally {
            self::safeUnlink($inPath);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Options:
     *   - 'ca_bundle'        => string (override caBundle instance)
     *   - 'untrusted_bundle' => string (path PEM intermediates)
     *   - 'flags'            => int    (override default PKCS7_BINARY)
     *   - 'no_verify_chain'  => bool   (paksa PKCS7_NOVERIFY — untuk test)
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

        $caBundle = isset($options['ca_bundle']) && is_string($options['ca_bundle']) && $options['ca_bundle'] !== ''
            ? $options['ca_bundle']
            : $this->caBundle;
        $untrusted = null;
        if (isset($options['untrusted_bundle']) && is_string($options['untrusted_bundle']) && $options['untrusted_bundle'] !== '') {
            $untrusted = $options['untrusted_bundle'];
        }

        $noVerify = (isset($options['no_verify_chain']) && $options['no_verify_chain'] === true) || $caBundle === '';
        $defaultFlags = PKCS7_BINARY;
        if ($noVerify) {
            $defaultFlags |= PKCS7_NOVERIFY;
        }
        $flags = (isset($options['flags']) && is_int($options['flags']))
            ? $options['flags']
            : $defaultFlags;

        $inPath = self::mkTmp(self::TMP_PREFIX_IN);
        $signerOut = self::mkTmp(self::TMP_PREFIX_OUT);
        $contentOut = self::mkTmp(self::TMP_PREFIX_OUT);

        try {
            if (file_put_contents($inPath, $envelopeBytes) === false) {
                throw new EzdocException('PkcsSevenEnvelope::verify(): failed to write temp envelope');
            }
            @chmod($inPath, 0600);
            @chmod($signerOut, 0600);
            @chmod($contentOut, 0600);

            self::drainOpenSslErrors();

            // openssl_pkcs7_verify signature:
            //   openssl_pkcs7_verify($in, $flags, $signers_out=null, $ca_info=[], $untrusted=null, $content=null, $output=null)
            // NOTE: param ke-6 ($content) adalah OUTPUT path — bukan input. PHP
            // menulis ekstraksi content ke sana. Untuk detached S/MIME multipart
            // (default output openssl_pkcs7_sign), content sudah inline di envelope
            // sehingga verify tidak butuh feed content terpisah. Untuk detached
            // DER-only (bare signature), verify tidak akan berhasil via PHP API —
            // caller wajib re-attach content atau shell out ke `openssl smime -verify -content`.
            // Kami extract content ke $contentOut hanya untuk inspection (bisa
            // di-cross-check dengan $originalContent).
            $caArg = ($caBundle !== '' && !$noVerify) ? [$caBundle] : [];
            $result = openssl_pkcs7_verify(
                $inPath,
                $flags,
                $signerOut,
                $caArg,
                $untrusted,
                $contentOut,
                null
            );

            $errs = self::drainOpenSslErrors();

            // Strict === comparison — PHP kembalikan true / false / -1.
            if ($result === -1) {
                return [
                    'valid' => false,
                    'reason' => 'openssl_pkcs7_verify error: ' . ($errs !== '' ? $errs : 'unknown'),
                    'signer_info' => [],
                    'checks' => [
                        'openssl_result' => -1,
                        'chain_check' => !$noVerify,
                        'detached' => $this->detached,
                    ],
                ];
            }
            if ($result === false) {
                return [
                    'valid' => false,
                    'reason' => 'signature verify failed (tampered or wrong content)'
                        . ($errs !== '' ? '; ' . $errs : ''),
                    'signer_info' => [],
                    'checks' => [
                        'openssl_result' => false,
                        'chain_check' => !$noVerify,
                        'detached' => $this->detached,
                    ],
                ];
            }
            // result === true
            $signerInfo = self::extractSignerInfo($signerOut);

            return [
                'valid' => true,
                'reason' => 'PKCS7 signature valid',
                'signer_info' => $signerInfo,
                'checks' => [
                    'openssl_result' => true,
                    'chain_check' => !$noVerify,
                    'detached' => $this->detached,
                    'ca_bundle' => $caBundle,
                ],
            ];
        } catch (EzdocException $e) {
            return [
                'valid' => false,
                'reason' => $e->getMessage(),
                'signer_info' => [],
                'checks' => ['exception' => true],
            ];
        } finally {
            self::safeUnlink($inPath);
            self::safeUnlink($signerOut);
            self::safeUnlink($contentOut);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canDetached(): bool
    {
        return true;
    }

    /**
     * Sniff apakah bytes berupa PKCS7 (PEM armor 'BEGIN PKCS7' / 'BEGIN CMS'
     * atau DER magic byte SEQUENCE 0x30).
     */
    public static function isPkcs7(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        if (self::isPemPkcs7($bytes)) {
            return true;
        }
        // DER: harus mulai dengan SEQUENCE tag (0x30). Cek juga OID contentInfo
        // signedData (1.2.840.113549.1.7.2) di 20 byte pertama — heuristik.
        if ($bytes[0] === "\x30") {
            $head = substr($bytes, 0, 32);
            // OID signedData bytes: 06 09 2A 86 48 86 F7 0D 01 07 02
            $signedDataOid = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02";
            if (strpos($head, $signedDataOid) !== false) {
                return true;
            }
            // Bytes DER pertama tetap SEQUENCE tapi tanpa OID di head — mungkin PKCS7
            // hanya kalau caller tahu format-nya. Return true tetap konservatif:
            // kalau hanya SEQUENCE tanpa signedData OID, kemungkinan false positive.
            return false;
        }
        return false;
    }

    /**
     * Cek armor PEM PKCS7/CMS.
     */
    private static function isPemPkcs7(string $bytes): bool
    {
        return strpos($bytes, '-----BEGIN PKCS7-----') !== false
            || strpos($bytes, '-----BEGIN CMS-----') !== false;
    }

    /**
     * Buat tempnam file — sys temp dir + prefix.
     *
     * @throws EzdocException kalau tempnam gagal
     */
    private static function mkTmp(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new EzdocException('PkcsSevenEnvelope: tempnam() failed');
        }
        return $path;
    }

    /**
     * Unlink diam-diam — dipakai di finally.
     */
    private static function safeUnlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Baca signer cert PEM + parse subject/issuer/serial.
     *
     * @return array<string,mixed>
     */
    private static function extractSignerInfo(string $signerCertPath): array
    {
        if (!is_file($signerCertPath)) {
            return [];
        }
        $pem = @file_get_contents($signerCertPath);
        if ($pem === false || $pem === '') {
            return [];
        }
        try {
            $cert = X509Certificate::fromPem($pem);
        } catch (ValidationException $e) {
            return ['error' => 'signer cert parse failed: ' . $e->getMessage()];
        }
        return [
            'signer_cert_pem' => $cert->getPem(),
            'subject_cn' => $cert->getSubjectCN(),
            'issuer_cn' => $cert->getIssuerCN(),
            'serial' => $cert->getSerialNumber(),
            'not_before' => $cert->getNotBefore(),
            'not_after' => $cert->getNotAfter(),
        ];
    }

    /**
     * Drain OpenSSL error queue jadi single "; "-joined string.
     */
    private static function drainOpenSslErrors(): string
    {
        $parts = [];
        while (($e = openssl_error_string()) !== false) {
            $parts[] = $e;
        }
        return implode('; ', $parts);
    }
}
