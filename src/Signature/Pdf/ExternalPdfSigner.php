<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Pdf\ExternalPdfSigner — PdfSigner yang delegate ke closure
 * / callable dari consumer.
 *
 * ## Use case
 *
 * Cloud signing services (Peruri PSrE, Privy, VIDA, BSSN BSrE) tidak
 * expose private key ke client — mereka menerima hash/document dan
 * mengembalikan CMS bytes atau full signed PDF via REST API. Endpoint
 * spesifik berbeda per vendor, dan biasanya carry authentication header
 * yang tidak ideal disimpan di library.
 *
 * `ExternalPdfSigner` menjadikan library-agnostic: consumer supply tiga
 * closure (embed, verify, extract) yang dijalankan sesuai request.
 * Cocok juga untuk:
 *
 *   - Testing: mock signer via closure.
 *   - HSM SDK: closure wrap panggilan ke PKCS#11 / KMIP driver.
 *   - Facade untuk vendor-specific implementation (BSrE khusus, Peruri
 *     khusus) yang sudah ditulis di project consumer.
 *
 * ## Kontrak closure
 *
 *   $embedFn(string $pdfBytes, string $pkcs7Bytes, X509Certificate $cert, array $options): string
 *   $verifyFn(string $pdfBytes, array $options): array   (Verdict-shape)
 *   $extractFn(string $pdfBytes): array                   (extract-shape)
 *
 * Closure boleh throw EzdocException; adapter tidak meng-catch — biarkan
 * bubble up.
 *
 * PHP 7.4+ compatible.
 */
final class ExternalPdfSigner implements PdfSigner
{
    /** @var callable */
    private $embedFn;

    /** @var callable */
    private $verifyFn;

    /** @var callable */
    private $extractFn;

    /**
     * @param callable      $embedFn   (pdfBytes, pkcs7Bytes, cert, options) → signedPdfBytes
     * @param callable      $verifyFn  (pdfBytes, options) → verdict array
     * @param callable      $extractFn (pdfBytes) → extract array
     * @throws ValidationException
     */
    public function __construct($embedFn, $verifyFn, $extractFn)
    {
        if (!is_callable($embedFn)) {
            throw ValidationException::forField('embedFn', 'must be callable');
        }
        if (!is_callable($verifyFn)) {
            throw ValidationException::forField('verifyFn', 'must be callable');
        }
        if (!is_callable($extractFn)) {
            throw ValidationException::forField('extractFn', 'must be callable');
        }
        $this->embedFn = $embedFn;
        $this->verifyFn = $verifyFn;
        $this->extractFn = $extractFn;
    }

    /**
     * {@inheritdoc}
     *
     * @throws EzdocException kalau closure return type tidak sesuai
     */
    public function embedSignature(string $pdfBytes, string $pkcs7Bytes, X509Certificate $cert, array $options = []): string
    {
        $fn = $this->embedFn;
        $result = $fn($pdfBytes, $pkcs7Bytes, $cert, $options);
        if (!is_string($result)) {
            throw new EzdocException('ExternalPdfSigner::embedSignature: closure did not return string');
        }
        if ($result === '') {
            throw new EzdocException('ExternalPdfSigner::embedSignature: closure returned empty PDF');
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @throws EzdocException
     */
    public function extractSignature(string $pdfBytes): array
    {
        $fn = $this->extractFn;
        $result = $fn($pdfBytes);
        if (!is_array($result)) {
            throw new EzdocException('ExternalPdfSigner::extractSignature: closure did not return array');
        }
        // Normalize expected keys.
        $result['signature_bytes'] = isset($result['signature_bytes']) && is_string($result['signature_bytes']) ? $result['signature_bytes'] : '';
        $result['byte_range'] = isset($result['byte_range']) && is_array($result['byte_range']) ? array_values($result['byte_range']) : [0, 0, 0, 0];
        $result['cert_pem'] = isset($result['cert_pem']) && is_string($result['cert_pem']) ? $result['cert_pem'] : '';
        $result['sig_info'] = isset($result['sig_info']) && is_array($result['sig_info']) ? $result['sig_info'] : [];
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function verifyPdf(string $pdfBytes, array $options = []): array
    {
        $fn = $this->verifyFn;
        $result = $fn($pdfBytes, $options);
        if (!is_array($result)) {
            throw new EzdocException('ExternalPdfSigner::verifyPdf: closure did not return array');
        }
        $result['valid'] = !empty($result['valid']);
        $result['reason'] = isset($result['reason']) && is_string($result['reason']) ? $result['reason'] : '';
        $result['checks'] = isset($result['checks']) && is_array($result['checks']) ? $result['checks'] : [];
        $result['signer_cert_pem'] = isset($result['signer_cert_pem']) && is_string($result['signer_cert_pem']) ? $result['signer_cert_pem'] : '';
        $result['signed_at'] = isset($result['signed_at']) ? (int) $result['signed_at'] : null;
        return $result;
    }
}
