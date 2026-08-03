<?php

declare(strict_types=1);

namespace Ezdoc\Signature\KeyStore;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\KeyStore\FileKeyStore — KeyStore yang membaca material dari
 * filesystem folder.
 *
 * ## Layout
 *
 * `$rootDir` berisi file per-alias:
 *   - `{alias}.key`   : PEM private key (required)
 *   - `{alias}.crt`   : PEM leaf cert   (required)
 *   - `{alias}.chain` : PEM chain concatenated (optional; split di
 *                       "-----END CERTIFICATE-----")
 *
 * ## Passphrase
 *
 * Passphrase tunggal per instance — semua alias di folder pakai passphrase
 * yang sama, atau semua tanpa passphrase. Kalau butuh mix, gunakan multiple
 * instance `FileKeyStore` dan composite di atasnya.
 *
 * ## Security
 *
 * Alias sanitized `[A-Za-z0-9_-]+` untuk cegah path traversal. Root dir
 * di-`realpath()` di constructor; alias yang mencoba escape (mis. lewat
 * simlink) akan gagal open karena strict regex sudah menolak `.` dan `/`.
 *
 * PHP 7.4+ compatible.
 */
final class FileKeyStore implements KeyStore
{
    /** @var string absolute canonical root dir path */
    private $rootDir;

    /** @var string */
    private $passphrase;

    /**
     * @param string $rootDir folder berisi *.key / *.crt / *.chain
     * @param string $passphrase '' = tanpa passphrase
     * @throws NotFoundException rootDir tidak ada
     */
    public function __construct(string $rootDir, string $passphrase = '')
    {
        if ($rootDir === '' || !is_dir($rootDir)) {
            throw NotFoundException::forResource('keystore_root_dir', $rootDir);
        }
        $canonical = realpath($rootDir);
        if ($canonical === false) {
            throw NotFoundException::forResource('keystore_root_dir', $rootDir);
        }
        $this->rootDir = $canonical;
        $this->passphrase = $passphrase;
    }

    public function loadPrivateKey(string $alias): PrivateKey
    {
        $path = $this->pathFor($alias, 'key');
        if (!is_file($path) || !is_readable($path)) {
            throw NotFoundException::forResource('private_key_file', $path);
        }
        return PrivateKey::fromFile(
            $path,
            ($this->passphrase === '' ? null : $this->passphrase)
        );
    }

    public function loadCertificate(string $alias): X509Certificate
    {
        $path = $this->pathFor($alias, 'crt');
        if (!is_file($path) || !is_readable($path)) {
            throw NotFoundException::forResource('certificate_file', $path);
        }
        $pem = file_get_contents($path);
        if ($pem === false || $pem === '') {
            throw NotFoundException::forResource('certificate_file', $path);
        }
        return X509Certificate::fromPem($pem);
    }

    public function loadChain(string $alias): array
    {
        // Cek alias exist dulu — throw NotFound kalau leaf/private juga hilang.
        if (!$this->hasKey($alias)) {
            throw NotFoundException::forResource('keystore_alias', $alias);
        }
        $path = $this->pathFor($alias, 'chain');
        // Chain optional — file boleh tidak ada.
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        return $this->splitPemChain($raw);
    }

    public function hasKey(string $alias): bool
    {
        // Kalau alias tidak valid, treat sebagai "tidak ada" — no throw.
        if (!self::isValidAlias($alias)) {
            return false;
        }
        return is_file($this->pathFor($alias, 'key'))
            && is_file($this->pathFor($alias, 'crt'));
    }

    /**
     * Bangun path {rootDir}/{alias}.{ext}. Alias di-sanitize.
     *
     * @throws ValidationException alias char terlarang / kosong
     */
    private function pathFor(string $alias, string $ext): string
    {
        if (!self::isValidAlias($alias)) {
            throw ValidationException::forField(
                'alias',
                'alias must match [A-Za-z0-9_-]+ (path traversal prevention)'
            );
        }
        return $this->rootDir . DIRECTORY_SEPARATOR . $alias . '.' . $ext;
    }

    private static function isValidAlias(string $alias): bool
    {
        if ($alias === '') {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $alias);
    }

    /**
     * Split PEM chain jadi array X509Certificate.
     *
     * @return array<int,X509Certificate>
     */
    private function splitPemChain(string $concatenated): array
    {
        $delim = '-----END CERTIFICATE-----';
        $parts = explode($delim, $concatenated);
        $out = [];
        foreach ($parts as $part) {
            $trim = trim($part);
            if ($trim === '') {
                continue;
            }
            if (strpos($trim, '-----BEGIN CERTIFICATE-----') === false) {
                continue;
            }
            $pem = $trim . "\n" . $delim . "\n";
            $out[] = X509Certificate::fromPem($pem);
        }
        return $out;
    }
}
