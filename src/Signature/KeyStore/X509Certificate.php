<?php

declare(strict_types=1);

namespace Ezdoc\Signature\KeyStore;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\KeyStore\X509Certificate — wrapper untuk certificate handle
 * plus parsed metadata dan original PEM.
 *
 * ## Portable typing
 *
 * PHP 7.x: `openssl_x509_read()` return `resource` ("OpenSSL X.509").
 * PHP 8+:  return `OpenSSLCertificate` object.
 *
 * Sama dengan `PrivateKey`, handle disimpan sebagai `mixed`. `openssl_verify()`
 * dan `openssl_x509_*` menerima keduanya transparan.
 *
 * ## Parsed metadata
 *
 * Constructor menerima array hasil `openssl_x509_parse()` supaya expensive
 * parse dilakukan sekali di call site (factory) dan getter cheap. PEM string
 * asli juga di-cache — dibutuhkan `SignResult::certificatePem` dan LTV embed.
 *
 * PHP 7.4+ compatible.
 */
final class X509Certificate
{
    /** @var resource|\OpenSSLCertificate|mixed */
    private $resource;

    /** @var string original PEM (base64 armored) */
    private $pem;

    /** @var array<string,mixed> hasil openssl_x509_parse($cert, true) */
    private $parsed;

    /**
     * @param mixed               $resource resource / OpenSSLCertificate
     * @param string              $pem
     * @param array<string,mixed> $parsed   openssl_x509_parse() output
     * @throws ValidationException
     */
    public function __construct($resource, string $pem, array $parsed)
    {
        $isResource = is_resource($resource);
        $isObject = class_exists('OpenSSLCertificate', false)
            && ($resource instanceof \OpenSSLCertificate);
        if (!$isResource && !$isObject) {
            throw ValidationException::forField(
                'resource',
                'X509Certificate requires resource (PHP 7.x) or OpenSSLCertificate (PHP 8+)'
            );
        }
        if ($pem === '') {
            throw ValidationException::forField('pem', 'empty PEM string');
        }
        $this->resource = $resource;
        $this->pem = $pem;
        $this->parsed = $parsed;
    }

    /**
     * @return mixed handle untuk openssl_verify() / openssl_x509_*
     */
    public function getResource()
    {
        return $this->resource;
    }

    public function getPem(): string
    {
        return $this->pem;
    }

    /**
     * Subject Common Name. Return null kalau CN tidak ada di subject DN.
     * Kalau CN multi-value (rare), ambil elemen pertama.
     */
    public function getSubjectCN(): ?string
    {
        return $this->extractCn($this->parsed, 'subject');
    }

    /**
     * Issuer Common Name.
     */
    public function getIssuerCN(): ?string
    {
        return $this->extractCn($this->parsed, 'issuer');
    }

    /**
     * Serial number sebagai string desimal. Prefer serialNumberHex kalau
     * openssl_x509_parse melaporkannya (PHP 7.0+ menyediakan itu).
     */
    public function getSerialNumber(): string
    {
        if (isset($this->parsed['serialNumberHex']) && is_string($this->parsed['serialNumberHex'])) {
            return $this->parsed['serialNumberHex'];
        }
        if (isset($this->parsed['serialNumber'])) {
            return (string) $this->parsed['serialNumber'];
        }
        return '';
    }

    /**
     * notBefore sebagai Unix timestamp.
     */
    public function getNotBefore(): int
    {
        return isset($this->parsed['validFrom_time_t'])
            ? (int) $this->parsed['validFrom_time_t']
            : 0;
    }

    /**
     * notAfter sebagai Unix timestamp.
     */
    public function getNotAfter(): int
    {
        return isset($this->parsed['validTo_time_t'])
            ? (int) $this->parsed['validTo_time_t']
            : 0;
    }

    /**
     * Cert expired terhadap wall clock saat ini.
     */
    public function isExpired(): bool
    {
        return !$this->isValidAt(time());
    }

    /**
     * Cert valid pada timestamp tertentu (inclusive di kedua batas).
     */
    public function isValidAt(int $timestamp): bool
    {
        $nb = $this->getNotBefore();
        $na = $this->getNotAfter();
        if ($nb === 0 || $na === 0) {
            return false;
        }
        return $timestamp >= $nb && $timestamp <= $na;
    }

    /**
     * Factory: load cert dari PEM string.
     *
     * @param string $pem
     * @return self
     * @throws ValidationException PEM invalid / parse gagal
     */
    public static function fromPem(string $pem): self
    {
        if ($pem === '') {
            throw ValidationException::forField('pem', 'empty PEM string');
        }
        // Drain stale errors sebelum masuk OpenSSL call.
        while (openssl_error_string() !== false) { /* noop */ }

        $handle = openssl_x509_read($pem);
        if ($handle === false) {
            $errs = [];
            while (($e = openssl_error_string()) !== false) {
                $errs[] = $e;
            }
            throw ValidationException::forField(
                'pem',
                'openssl_x509_read() failed: ' . ($errs ? implode('; ', $errs) : 'unknown')
            );
        }
        $parsed = openssl_x509_parse($handle, true);
        if (!is_array($parsed)) {
            throw ValidationException::forField('pem', 'openssl_x509_parse() failed');
        }
        return new self($handle, $pem, $parsed);
    }

    /**
     * Ambil CN dari subject/issuer subarray. CN boleh scalar atau array.
     *
     * @param array<string,mixed> $parsed
     * @param string              $section 'subject' | 'issuer'
     * @return string|null
     */
    private function extractCn(array $parsed, string $section): ?string
    {
        if (!isset($parsed[$section]) || !is_array($parsed[$section])) {
            return null;
        }
        $dn = $parsed[$section];
        if (!array_key_exists('CN', $dn)) {
            return null;
        }
        $cn = $dn['CN'];
        if (is_array($cn)) {
            // Multi-CN: ambil string pertama.
            foreach ($cn as $v) {
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
            return null;
        }
        if (!is_string($cn) || $cn === '') {
            return null;
        }
        return $cn;
    }
}
