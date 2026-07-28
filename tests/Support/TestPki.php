<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Support;

/**
 * Throwaway X.509 material for signature tests.
 *
 * Generating real keys and certificates beats fixture files: the tests then
 * exercise the same OpenSSL paths production uses, and nothing expires in the
 * repository a year from now.
 *
 * Key generation is expensive, so each shape is produced once per process and
 * reused.
 *
 * ## openssl.cnf on Windows
 *
 * Key generation needs a config file. PHP on Windows ships one at
 * `extras/ssl/openssl.cnf` but does not point OPENSSL_CONF at it, and setting
 * that variable from inside a running script is too late — the extension reads
 * it when it loads. So {@see opensslConf()} locates the file and every call
 * receives it through the `config` option instead.
 */
final class TestPki
{
    /** @var array<string, array{key: mixed, keyPem: string, certPem: string}> */
    private static array $cache = [];

    /** @var bool|null */
    private static $available = null;

    /** @var string|null|false Resolved config path; false once we know there is none. */
    private static $confPath = null;

    /**
     * Locate an openssl.cnf to hand to the key-generation calls.
     *
     * The extension reads OPENSSL_CONF when it loads, so setting the variable
     * from inside a running script is too late — hence passing `config`
     * explicitly on every call instead.
     *
     * @return string|null
     */
    private static function opensslConf(): ?string
    {
        if (self::$confPath !== null) {
            return self::$confPath === false ? null : self::$confPath;
        }

        $fromEnv = getenv('OPENSSL_CONF');
        if ($fromEnv && is_file($fromEnv)) {
            return self::$confPath = $fromEnv;
        }

        // PHP_BINDIR is baked in at compile time and on Windows builds usually
        // says C:\php regardless of where PHP actually lives. The directory of
        // the running binary is the reliable one.
        $binDir = defined('PHP_BINARY') && PHP_BINARY !== '' ? dirname(PHP_BINARY) : PHP_BINDIR;

        $candidates = [
            $binDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $binDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/usr/local/etc/openssl/openssl.cnf',
            '/etc/pki/tls/openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return self::$confPath = $path;
            }
        }

        // Unix builds generally work without one.
        self::$confPath = false;
        return null;
    }

    /**
     * Merge the resolved config path into an openssl options array.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function withConf(array $options = []): array
    {
        $conf = self::opensslConf();
        if ($conf !== null) {
            $options['config'] = $conf;
        }
        return $options;
    }

    /**
     * Whether this machine can generate keys at all.
     */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (!extension_loaded('openssl')) {
            return self::$available = false;
        }

        // Drain any stale error before probing.
        while (openssl_error_string() !== false) {
            // discard
        }

        $probe = @openssl_pkey_new(self::withConf([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));

        return self::$available = ($probe !== false);
    }

    /**
     * A self-signed certificate and its private key.
     *
     * @param string $variant Cache key; also lets a test ask for a second,
     *                        unrelated identity to prove keys are not
     *                        interchangeable.
     * @param int    $days    Validity. Negative produces an already-expired
     *                        certificate.
     * @param string $cn      Common name.
     *
     * @return array{key: mixed, keyPem: string, certPem: string}
     */
    public static function selfSigned(string $variant = 'default', int $days = 365, string $cn = 'ezdoc-test'): array
    {
        $cacheKey = $variant . '|' . $days . '|' . $cn;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $key = openssl_pkey_new(self::withConf([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }

        $dn = [
            'countryName'         => 'ID',
            'organizationName'    => 'Ezdoc Test Authority',
            'commonName'          => $cn,
        ];

        $csr = openssl_csr_new($dn, $key, self::withConf(['digest_alg' => 'sha256']));
        if ($csr === false) {
            throw new \RuntimeException('openssl_csr_new failed: ' . openssl_error_string());
        }

        // Negative $days backdates notAfter, which is how the expired-cert
        // cases are built. A non-zero serial matches what a real CA issues —
        // openssl defaults to 0, which reads back as an empty serial.
        $cert = openssl_csr_sign(
            $csr,
            null,
            $key,
            $days,
            self::withConf(['digest_alg' => 'sha256']),
            random_int(1, PHP_INT_MAX)
        );
        if ($cert === false) {
            throw new \RuntimeException('openssl_csr_sign failed: ' . openssl_error_string());
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, self::withConf());

        return self::$cache[$cacheKey] = [
            'key'     => $key,
            'keyPem'  => $keyPem,
            'certPem' => $certPem,
        ];
    }

    /**
     * Write PEM material to a temporary file and return its path.
     * Caller is responsible for unlinking.
     */
    public static function tempFile(string $contents, string $prefix = 'ezdoc_pki'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $contents);
        return $path;
    }
}
