<?php

declare(strict_types=1);

namespace Ezdoc\Signature\KeyStore;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\KeyStore\PrivateKey — wrapper tipis untuk private key
 * handle OpenSSL.
 *
 * ## Portable typing
 *
 * PHP 7.x: `openssl_pkey_get_private()` return `resource` (type "OpenSSL key").
 * PHP 8+:  return `OpenSSLAsymmetricKey` object.
 *
 * Kelas ini simpan handle sebagai untyped property (`mixed`) supaya kompatibel
 * di keduanya. Consumer memanggil `getResource()` dan meneruskan hasil apa
 * adanya ke `openssl_sign()` / `openssl_verify()` — kedua fungsi menerima
 * baik resource maupun object secara transparan.
 *
 * ## Lifecycle
 *
 * PHP 7.x meng-GC resource ketika ref count = 0, tapi tetap kita panggil
 * `openssl_pkey_free()` di destructor untuk deterministic release. PHP 8+
 * meng-GC object; `openssl_pkey_free()` sudah no-op di 8.0 dan hilang di
 * 8.4, jadi kita guard dengan `is_resource()` — object slip lewat aman.
 *
 * PHP 7.4+ compatible.
 */
final class PrivateKey
{
    /** @var resource|\OpenSSLAsymmetricKey|mixed */
    private $resource;

    /**
     * @param mixed $resource resource (PHP 7.x) atau OpenSSLAsymmetricKey (8+)
     * @throws ValidationException
     */
    public function __construct($resource)
    {
        $isResource = is_resource($resource);
        $isObject = class_exists('OpenSSLAsymmetricKey', false)
            && ($resource instanceof \OpenSSLAsymmetricKey);
        if (!$isResource && !$isObject) {
            throw ValidationException::forField(
                'resource',
                'PrivateKey requires resource (PHP 7.x) or OpenSSLAsymmetricKey (PHP 8+)'
            );
        }
        $this->resource = $resource;
    }

    /**
     * Return handle untuk diteruskan ke openssl_sign / openssl_verify.
     * Type intentionally mixed — portable across PHP 7.x resource & 8+ object.
     *
     * @return mixed
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Factory: load private key dari PEM string. Passphrase opsional.
     *
     * @param string      $pem
     * @param string|null $passphrase
     * @return self
     * @throws ValidationException PEM invalid / passphrase salah
     */
    public static function fromPem(string $pem, ?string $passphrase = null): self
    {
        if ($pem === '') {
            throw ValidationException::forField('pem', 'empty PEM string');
        }
        self::drainOpenSslErrors();
        $handle = openssl_pkey_get_private($pem, $passphrase);
        if ($handle === false) {
            $errs = self::drainOpenSslErrors();
            throw ValidationException::forField(
                'pem',
                'openssl_pkey_get_private() failed: ' . ($errs !== '' ? $errs : 'unknown')
            );
        }
        return new self($handle);
    }

    /**
     * Factory: load private key dari file path.
     *
     * @param string      $path
     * @param string|null $passphrase
     * @return self
     * @throws NotFoundException file tidak ada / tidak readable
     * @throws ValidationException PEM invalid / passphrase salah
     */
    public static function fromFile(string $path, ?string $passphrase = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw NotFoundException::forResource('private_key_file', $path);
        }
        self::drainOpenSslErrors();
        // openssl_pkey_get_private() accepts "file://" scheme
        $handle = openssl_pkey_get_private('file://' . $path, $passphrase);
        if ($handle === false) {
            $errs = self::drainOpenSslErrors();
            throw ValidationException::forField(
                'file',
                'openssl_pkey_get_private() failed for ' . $path . ': '
                . ($errs !== '' ? $errs : 'unknown')
            );
        }
        return new self($handle);
    }

    /**
     * Deterministic free untuk PHP 7.x resource. Object di 8+ di-GC.
     */
    public function __destruct()
    {
        // PHP 8.0+: openssl_pkey_free() adalah no-op; PHP 8.4: fungsi hilang.
        // is_resource() return false untuk object — guard aman di keduanya.
        if (PHP_VERSION_ID < 80000 && is_resource($this->resource)) {
            @openssl_pkey_free($this->resource);
        }
        $this->resource = null;
    }

    /**
     * Drain OpenSSL error queue jadi single string. Multi error di-join "; ".
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
