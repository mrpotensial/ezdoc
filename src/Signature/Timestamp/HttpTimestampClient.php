<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Timestamp;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\Remote\HttpClient;

/**
 * Ezdoc\Signature\Timestamp\HttpTimestampClient — pure-HTTP RFC 3161 client
 * tanpa shell-out.
 *
 * ## Kenapa ada?
 *
 * Kalau host disable `proc_open`/`exec` (shared hosting, cPanel restrictive
 * open_basedir, dsb) → {@see OpensslTimestampClient} tidak bisa jalan.
 * Kelas ini bikin TimeStampReq DER manually via minimal ASN.1 emitter dan
 * POST via {@see HttpClient}. Cukup untuk **mengambil** token.
 *
 * ## Batasan
 *
 *   - `verifyTimestamp()` **selalu return untrusted** — verify butuh parse
 *     CMS SignedData + validate cert chain, yang tidak feasible tanpa
 *     OpenSSL CLI atau pure-PHP ASN.1 lib (mis. phpseclib3).
 *   - Nonce di-include (8 random bytes) tapi TIDAK diverifikasi round-trip
 *     — token diterima as-is.
 *   - Hanya SHA-1/256/384/512 didukung.
 *
 * ## Kapan pakai?
 *
 *   - Development / staging dengan TSA gratis (FreeTSA, DigiCert).
 *   - Environment tanpa shell access, cukup butuh proof-of-existence
 *     tanpa verify locally (verify di-delegate ke tool eksternal).
 *   - Testing dengan dummy TSA yang echo request.
 *
 * PHP 7.4+ compatible.
 */
final class HttpTimestampClient implements TimestampClient
{
    /** @var string */
    private $tsaUrl;

    /** @var HttpClient */
    private $http;

    /** @var array<string,mixed> */
    private $config;

    /** @var int */
    private $timeout;

    /**
     * DER-encoded OID bodies (tanpa tag+length prefix) untuk algoritma hash
     * yang didukung. Referensi:
     *   - sha1   1.3.14.3.2.26
     *   - sha256 2.16.840.1.101.3.4.2.1
     *   - sha384 2.16.840.1.101.3.4.2.2
     *   - sha512 2.16.840.1.101.3.4.2.3
     *
     * @var array<string,string>
     */
    private static $HASH_OID_BYTES = [
        'sha1'   => "\x2B\x0E\x03\x02\x1A",
        'sha256' => "\x60\x86\x48\x01\x65\x03\x04\x02\x01",
        'sha384' => "\x60\x86\x48\x01\x65\x03\x04\x02\x02",
        'sha512' => "\x60\x86\x48\x01\x65\x03\x04\x02\x03",
    ];

    /**
     * Expected hash byte length per algo — dipakai untuk validasi input.
     *
     * @var array<string,int>
     */
    private static $HASH_LEN = [
        'sha1'   => 20,
        'sha256' => 32,
        'sha384' => 48,
        'sha512' => 64,
    ];

    /**
     * @param string              $tsaUrl RFC 3161 endpoint
     * @param HttpClient          $http
     * @param array<string,mixed> $config keys: timeout, auth_header, ca_bundle_path (unused)
     * @throws ValidationException
     */
    public function __construct(string $tsaUrl, HttpClient $http, array $config = [])
    {
        if ($tsaUrl === '') {
            throw ValidationException::forField('tsaUrl', 'TSA URL must be non-empty');
        }
        $this->tsaUrl = $tsaUrl;
        $this->http = $http;
        $this->config = $config;
        $this->timeout = isset($config['timeout']) && is_numeric($config['timeout']) && (int) $config['timeout'] > 0
            ? (int) $config['timeout']
            : 60;
    }

    /**
     * {@inheritdoc}
     */
    public function requestTimestamp(string $dataHash, string $hashAlgo = 'sha256'): TimestampToken
    {
        $algo = strtolower($hashAlgo);
        if (!isset(self::$HASH_OID_BYTES[$algo])) {
            throw ValidationException::forField('hashAlgo', 'unsupported hash algorithm: ' . $hashAlgo);
        }

        // Normalize ke raw binary
        $bin = self::normalizeToBinary($dataHash);
        $expected = self::$HASH_LEN[$algo];
        if (strlen($bin) !== $expected) {
            throw ValidationException::forField(
                'dataHash',
                sprintf('hash length mismatch for %s: expected %d bytes, got %d', $algo, $expected, strlen($bin))
            );
        }

        $reqBytes = self::buildTimeStampReq($algo, $bin);

        // POST
        $headers = [
            'Content-Type' => 'application/timestamp-query',
            'Accept' => 'application/timestamp-reply',
        ];
        if (isset($this->config['auth_header']) && is_string($this->config['auth_header']) && $this->config['auth_header'] !== '') {
            $headers['Authorization'] = $this->config['auth_header'];
        }

        $resp = $this->http->request('POST', $this->tsaUrl, [
            'headers' => $headers,
            'body' => $reqBytes,
            'timeout' => $this->timeout,
        ]);
        if (!$resp->isSuccess()) {
            throw new EzdocException(
                'TSA returned HTTP ' . $resp->getStatusCode(),
                [
                    'tsa_url' => $this->tsaUrl,
                    'status' => $resp->getStatusCode(),
                    'response_snippet' => substr($resp->getBody(), 0, 512),
                ]
            );
        }
        $body = $resp->getBody();
        if ($body === '') {
            throw new EzdocException('TSA returned empty body');
        }
        return new TimestampToken($body, $this->tsaUrl, $algo, bin2hex($bin));
    }

    /**
     * {@inheritdoc}
     *
     * Verify TIDAK didukung di pure-HTTP client. Consumer WAJIB pakai
     * {@see OpensslTimestampClient} atau tool eksternal.
     */
    public function verifyTimestamp(TimestampToken $token, string $originalDataHash): TimestampVerdict
    {
        return TimestampVerdict::untrusted(
            'HttpTimestampClient cannot verify — use OpensslTimestampClient for RFC 3161 verify',
            [
                'tsa_url' => $token->getTsaUrl(),
                'gen_time' => $token->getGenTime(),
                'has_tsa_cert' => $token->getTsaCertPem() !== null,
            ]
        );
    }

    // -----------------------------------------------------------------
    //  ASN.1 DER emission untuk TimeStampReq
    // -----------------------------------------------------------------

    /**
     * TimeStampReq ::= SEQUENCE {
     *     version           INTEGER  { v1(1) },
     *     messageImprint    MessageImprint,
     *     reqPolicy         OID OPTIONAL,          -- omit
     *     nonce             INTEGER OPTIONAL,
     *     certReq           BOOLEAN DEFAULT FALSE  -- TRUE
     *     extensions        [0] IMPLICIT Extensions OPTIONAL  -- omit
     * }
     */
    private static function buildTimeStampReq(string $algo, string $hashBin): string
    {
        // MessageImprint ::= SEQUENCE { hashAlgorithm AlgorithmIdentifier, hashedMessage OCTET STRING }
        $algoOidDer = self::derOid(self::$HASH_OID_BYTES[$algo]);
        $algoIdent = self::derSeq($algoOidDer . self::derNull());
        $messageImprint = self::derSeq($algoIdent . self::derOctet($hashBin));

        // version INTEGER 1
        $version = self::derIntFromInt(1);

        // nonce — 8 random bytes, ensure positive
        $nonce = '';
        try {
            $rand = random_bytes(8);
        } catch (\Throwable $e) {
            // Fallback: mt_rand — bukan crypto-safe tapi RFC 3161 nonce cukup uniqueness
            $rand = '';
            for ($i = 0; $i < 8; $i++) $rand .= chr(mt_rand(0, 255));
        }
        // MSB clear supaya positive
        $rand[0] = chr(ord($rand[0]) & 0x7F);
        if (ord($rand[0]) === 0) $rand[0] = chr(0x01); // avoid all-zero leading
        $nonce = self::derIntFromBytes($rand);

        // certReq BOOLEAN TRUE
        $certReq = self::derBool(true);

        return self::derSeq($version . $messageImprint . $nonce . $certReq);
    }

    private static function derLen(int $len): string
    {
        if ($len < 0) $len = 0;
        if ($len < 0x80) return chr($len);
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xFF) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSeq(string $content): string
    {
        return "\x30" . self::derLen(strlen($content)) . $content;
    }

    private static function derOid(string $oidBodyBytes): string
    {
        return "\x06" . self::derLen(strlen($oidBodyBytes)) . $oidBodyBytes;
    }

    private static function derNull(): string
    {
        return "\x05\x00";
    }

    private static function derOctet(string $bytes): string
    {
        return "\x04" . self::derLen(strlen($bytes)) . $bytes;
    }

    private static function derBool(bool $v): string
    {
        return "\x01\x01" . ($v ? "\xFF" : "\x00");
    }

    /**
     * INTEGER dari nilai non-negatif kecil (fits in PHP int).
     */
    private static function derIntFromInt(int $val): string
    {
        if ($val < 0) $val = 0;
        if ($val === 0) return "\x02\x01\x00";
        $bytes = '';
        $tmp = $val;
        while ($tmp > 0) {
            $bytes = chr($tmp & 0xFF) . $bytes;
            $tmp >>= 8;
        }
        // Prepend 0x00 kalau MSB set (biar positive)
        if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\x00" . $bytes;
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    /**
     * INTEGER dari raw big-endian bytes (positive assumed).
     */
    private static function derIntFromBytes(string $bytes): string
    {
        if ($bytes === '') $bytes = "\x00";
        // Ensure positive: prepend 0x00 kalau MSB set
        if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\x00" . $bytes;
        // Strip leading 0x00 yang tidak perlu (kalau ada duplikasi)
        while (strlen($bytes) > 1 && $bytes[0] === "\x00" && (ord($bytes[1]) & 0x80) === 0) {
            $bytes = substr($bytes, 1);
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    /**
     * Terima hex atau raw binary → return raw binary.
     */
    private static function normalizeToBinary(string $hash): string
    {
        if ($hash === '') return '';
        if (ctype_xdigit($hash) && (strlen($hash) % 2 === 0)) {
            $bin = @hex2bin($hash);
            return $bin === false ? '' : $bin;
        }
        return $hash;
    }
}
