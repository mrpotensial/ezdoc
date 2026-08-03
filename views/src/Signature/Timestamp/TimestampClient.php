<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Timestamp;

use Ezdoc\Exceptions\EzdocException;

/**
 * Ezdoc\Signature\Timestamp\TimestampClient — kontrak minimal untuk client
 * RFC 3161 Time-Stamping Authority (TSA).
 *
 * Bertugas:
 *   1. Kirim messageImprint (hash + algorithm) ke TSA endpoint via HTTP,
 *      terima TimeStampResp (DER binary) yang berisi TSTInfo signed oleh TSA.
 *   2. Verifikasi ulang token terhadap original hash (bandingkan
 *      messageImprint, cek TSA cert chain, EKU id-kp-timeStamping).
 *
 * ## Kontrak hash
 *
 * `$dataHash` boleh berupa:
 *   - Lowercase/uppercase hex string (mis. `hash('sha256', $data)`), atau
 *   - Raw binary hash bytes.
 * Implementasi WAJIB deteksi dan konversi ke bentuk yang dibutuhkan
 * (openssl CLI menerima hex via `-digest`, wire format ASN.1 pakai binary).
 *
 * ## Implementasi standar
 *
 *   - {@see OpensslTimestampClient} — shell-out ke `openssl ts` CLI,
 *     bisa request + verify. Cocok untuk produksi kalau shell available.
 *   - {@see HttpTimestampClient} — pure HTTP, bikin TimeStampReq via
 *     minimal ASN.1 emission. Cocok kalau shell disabled. Verify: unsupported.
 *
 * ## Error semantics
 *
 * Implementasi WAJIB throw {@see EzdocException} pada kegagalan network,
 * malformed response, atau TSA rejection. Verify tidak throw kalau signature
 * secara struktural valid tapi mismatch — return {@see TimestampVerdict}
 * dengan `valid=false`.
 *
 * PHP 7.4+ compatible.
 */
interface TimestampClient
{
    /**
     * Request timestamp token dari TSA.
     *
     * @param string $dataHash  hex string (preferred) atau raw binary hash
     * @param string $hashAlgo  algoritma hash: sha256 (default), sha1, sha384, sha512
     * @return TimestampToken   token DER + metadata parsed
     * @throws EzdocException   network / DNS / TLS gagal, TSA rejection, malformed response
     */
    public function requestTimestamp(string $dataHash, string $hashAlgo = 'sha256'): TimestampToken;

    /**
     * Verifikasi token terhadap original hash.
     *
     * Cek:
     *   - status = granted
     *   - messageImprint token cocok dengan `$originalDataHash`
     *   - CMS signature valid (TSA cert)
     *   - Cert chain sampai trusted root, EKU id-kp-timeStamping
     *
     * @param TimestampToken $token
     * @param string         $originalDataHash hex string atau raw binary — HARUS sama
     *                                         dengan hash yang di-request
     * @return TimestampVerdict
     */
    public function verifyTimestamp(TimestampToken $token, string $originalDataHash): TimestampVerdict;
}
