<?php

declare(strict_types=1);

namespace Ezdoc\Signature\KeyStore;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\KeyStore\EnvKeyStore — KeyStore yang membaca material dari
 * environment variables.
 *
 * ## Env var scheme
 *
 * Alias `foo` (case-insensitive, uppercased untuk lookup) memetakan ke:
 *   - `{PREFIX}FOO_PRIVATE`     : base64-encoded PEM private key (required)
 *   - `{PREFIX}FOO_CERT`        : base64-encoded PEM leaf cert (required)
 *   - `{PREFIX}FOO_CHAIN`       : base64-encoded PEM chain (optional; split
 *                                 di "-----END CERTIFICATE-----")
 *   - `{PREFIX}FOO_PASSPHRASE`  : raw passphrase (optional)
 *
 * Default prefix: `EZDOC_KEY_`. Base64 wrapper dipakai supaya env var aman
 * dari newline / quoting shell.
 *
 * ## Cocok untuk
 *
 * Container / systemd / .env deployment. Untuk hospital on-prem, biasanya
 * pakai `FileKeyStore` — file mode 0400 lebih audit-friendly.
 *
 * PHP 7.4+ compatible.
 */
final class EnvKeyStore implements KeyStore
{
    /** @var string */
    private $prefix;

    /**
     * @param string $prefix env var name prefix, default 'EZDOC_KEY_'
     */
    public function __construct(string $prefix = 'EZDOC_KEY_')
    {
        // Normalize prefix — uppercase, trailing underscore optional.
        $p = strtoupper($prefix);
        if ($p === '') {
            $p = 'EZDOC_KEY_';
        }
        $this->prefix = $p;
    }

    public function loadPrivateKey(string $alias): PrivateKey
    {
        $slot = $this->slot($alias);
        $pem = $this->requirePemEnv($slot . '_PRIVATE', 'private key');
        $passphrase = $this->getRawEnv($slot . '_PASSPHRASE');
        return PrivateKey::fromPem($pem, ($passphrase === '' ? null : $passphrase));
    }

    public function loadCertificate(string $alias): X509Certificate
    {
        $slot = $this->slot($alias);
        $pem = $this->requirePemEnv($slot . '_CERT', 'certificate');
        return X509Certificate::fromPem($pem);
    }

    public function loadChain(string $alias): array
    {
        $slot = $this->slot($alias);
        // CHAIN opsional — return empty array kalau tidak ada.
        $raw = $this->getRawEnv($slot . '_CHAIN');
        if ($raw === '') {
            return [];
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false || $decoded === '') {
            throw ValidationException::forField(
                $this->prefix . $slot . '_CHAIN',
                'invalid base64'
            );
        }
        return $this->splitPemChain($decoded);
    }

    public function hasKey(string $alias): bool
    {
        $slot = $this->slot($alias);
        return $this->getRawEnv($slot . '_PRIVATE') !== ''
            && $this->getRawEnv($slot . '_CERT') !== '';
    }

    /**
     * Sanitize alias jadi env-safe uppercase segment.
     *
     * @throws ValidationException alias kosong / char terlarang
     */
    private function slot(string $alias): string
    {
        if ($alias === '') {
            throw ValidationException::forField('alias', 'must be non-empty');
        }
        // Batas: [a-zA-Z0-9_-]+ — sama dgn FileKeyStore untuk konsistensi.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $alias)) {
            throw ValidationException::forField(
                'alias',
                'alias must match [A-Za-z0-9_-]+'
            );
        }
        // Env vars UPPERCASE + '-' → '_' (bash tidak accept dash di nama var).
        return strtoupper(str_replace('-', '_', $alias));
    }

    /**
     * Baca env var raw (belum decode).
     */
    private function getRawEnv(string $suffix): string
    {
        $name = $this->prefix . $suffix;
        $val = getenv($name);
        if (!is_string($val)) {
            return '';
        }
        return $val;
    }

    /**
     * Baca env var, decode base64, throw NotFoundException kalau kosong.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function requirePemEnv(string $suffix, string $label): string
    {
        $name = $this->prefix . $suffix;
        $raw = $this->getRawEnv($suffix);
        if ($raw === '') {
            throw NotFoundException::forResource('env_' . strtolower($label), $name);
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false || $decoded === '') {
            throw ValidationException::forField($name, 'invalid base64');
        }
        return $decoded;
    }

    /**
     * Split concatenated PEM chain menjadi array X509Certificate.
     *
     * @param string $concatenated
     * @return array<int,X509Certificate>
     * @throws ValidationException
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
            // Cari BEGIN block sebelum tambah kembali END delimiter.
            if (strpos($trim, '-----BEGIN CERTIFICATE-----') === false) {
                continue;
            }
            $pem = $trim . "\n" . $delim . "\n";
            $out[] = X509Certificate::fromPem($pem);
        }
        return $out;
    }
}
