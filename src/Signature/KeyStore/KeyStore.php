<?php

declare(strict_types=1);

namespace Ezdoc\Signature\KeyStore;

/**
 * Ezdoc\Signature\KeyStore\KeyStore — contract untuk lookup private key,
 * certificate, dan (opsional) certificate chain berdasarkan alias.
 *
 * ## Alias
 *
 * `$alias` adalah opaque string handle (mis. "default", "billing", "director").
 * Implementation bebas menentukan namespace fisiknya (file, env var, DB, KMS).
 * Alias yang tidak dikenal WAJIB throw `NotFoundException` — bukan return null,
 * bukan silent fallback ke default. Provider yang sign wajib eksplisit soal
 * identity kuncinya.
 *
 * ## Wrappers
 *
 * `PrivateKey` dan `X509Certificate` adalah wrapper tipis untuk resource
 * (PHP 7.x) / OpenSSL* object (PHP 8+). Method mengembalikan wrapper —
 * bukan raw PEM / resource — supaya consumer tidak perlu duplicate parsing.
 *
 * ## Chain
 *
 * `loadChain()` mengembalikan array intermediate + root CA cert (leaf
 * excluded). KeyStore yang tidak memiliki chain untuk alias tsb boleh
 * return empty array — bukan throw. Provider yang butuh full chain
 * (LTV verification, pkcs7) wajib cek `count($chain) > 0` sendiri.
 *
 * PHP 7.4+ compatible.
 */
interface KeyStore
{
    /**
     * Load private key untuk alias tersebut.
     *
     * @param string $alias
     * @return PrivateKey
     * @throws \Ezdoc\Exceptions\NotFoundException alias tidak dikenal / file/env kosong
     * @throws \Ezdoc\Exceptions\ValidationException PEM corrupt / passphrase salah
     */
    public function loadPrivateKey(string $alias): PrivateKey;

    /**
     * Load signing certificate (leaf) untuk alias tersebut.
     *
     * @param string $alias
     * @return X509Certificate
     * @throws \Ezdoc\Exceptions\NotFoundException
     * @throws \Ezdoc\Exceptions\ValidationException
     */
    public function loadCertificate(string $alias): X509Certificate;

    /**
     * Load certificate chain (intermediate + root) untuk alias tersebut.
     * Leaf certificate TIDAK termasuk — pakai `loadCertificate()` untuk itu.
     * Return empty array kalau alias ada tapi chain tidak tersedia.
     *
     * @param string $alias
     * @return array<int,X509Certificate>
     * @throws \Ezdoc\Exceptions\NotFoundException alias tidak dikenal
     */
    public function loadChain(string $alias): array;

    /**
     * Cek keberadaan alias (private key + cert lengkap) tanpa memuat.
     *
     * @param string $alias
     * @return bool
     */
    public function hasKey(string $alias): bool;
}
