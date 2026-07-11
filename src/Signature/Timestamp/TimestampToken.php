<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Timestamp;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Timestamp\TimestampToken — DTO immutable untuk RFC 3161
 * TimeStampResp / TimeStampToken.
 *
 * ## Struktur
 *
 * Raw `$bytes` adalah body HTTP response TSA (Content-Type
 * `application/timestamp-reply`) dalam DER encoding. Format:
 *
 *     TimeStampResp ::= SEQUENCE {
 *         status           PKIStatusInfo,
 *         timeStampToken   TimeStampToken OPTIONAL }
 *
 * `timeStampToken` = CMS SignedData yang eContent-nya adalah TSTInfo
 * (OID 1.2.840.113549.1.9.16.1.4). TSTInfo carry: version, policy,
 * messageImprint, serialNumber, genTime, accuracy, tsa, ...
 *
 * ## Parse best-effort
 *
 * Parser di kelas ini adalah **minimal DER walker** — TIDAK menggantikan
 * `openssl ts -reply -text` untuk produksi. Kami extract:
 *   - genTime (unix ts)
 *   - policy OID
 *   - serialNumber (hex)
 *   - TSA signing cert PEM (kalau di-embed via certReq=TRUE)
 * Kalau bytes malformed / bukan RFC 3161 → semua field parsed = null,
 * TIDAK throw. Consumer boleh cek `getGenTime() === null` untuk deteksi.
 *
 * ## Serialization
 *
 * Bytes selalu DER (binary). Untuk JSON transport, pakai
 * {@see toBase64()} / {@see fromBase64()}.
 *
 * PHP 7.4+ compatible.
 */
final class TimestampToken
{
    /** @var string raw DER response bytes */
    private $bytes;

    /** @var string TSA URL yang men-generate token ini */
    private $tsaUrl;

    /** @var string algoritma hash pada saat request (sha256 dll) */
    private $requestedHashAlgo;

    /** @var string hex-encoded hash yang di-request ke TSA */
    private $requestedDataHash;

    /** @var int|null unix timestamp dari TSTInfo.genTime */
    private $genTime;

    /** @var string|null hex-encoded serialNumber dari TSTInfo */
    private $serialNumber;

    /** @var string|null policy OID dot-notation dari TSTInfo */
    private $policyOid;

    /** @var string|null PEM signing cert TSA (kalau di-embed) */
    private $tsaCertPem;

    /**
     * @param string $bytes             raw DER TimeStampResp; non-empty
     * @param string $tsaUrl            TSA endpoint URL (untuk audit / provenance)
     * @param string $requestedHashAlgo mis. 'sha256'
     * @param string $requestedDataHash hex-encoded hash yang di-request
     * @throws ValidationException bytes kosong
     */
    public function __construct(string $bytes, string $tsaUrl, string $requestedHashAlgo, string $requestedDataHash)
    {
        if ($bytes === '') {
            throw ValidationException::forField('bytes', 'timestamp token bytes must be non-empty');
        }
        $this->bytes = $bytes;
        $this->tsaUrl = $tsaUrl;
        $this->requestedHashAlgo = strtolower($requestedHashAlgo);
        $this->requestedDataHash = strtolower($requestedDataHash);

        // Best-effort parse. Isi null kalau gagal.
        $parsed = self::tryParse($bytes);
        $this->genTime = $parsed['genTime'];
        $this->serialNumber = $parsed['serialNumber'];
        $this->policyOid = $parsed['policyOid'];
        $this->tsaCertPem = $parsed['tsaCertPem'];
    }

    /**
     * Static factory — auto-parse.
     *
     * @param string $bytes  raw DER
     * @param string $tsaUrl TSA endpoint
     * @param string $algo   algoritma yang dipakai (default sha256)
     * @param string $hash   hex hash yang di-request (default kosong)
     */
    public static function fromBytes(string $bytes, string $tsaUrl, string $algo = 'sha256', string $hash = ''): self
    {
        return new self($bytes, $tsaUrl, $algo, $hash);
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }

    public function getTsaUrl(): string
    {
        return $this->tsaUrl;
    }

    public function getRequestedHashAlgo(): string
    {
        return $this->requestedHashAlgo;
    }

    public function getRequestedDataHash(): string
    {
        return $this->requestedDataHash;
    }

    /**
     * @return int|null unix timestamp UTC, atau null kalau tidak bisa di-parse
     */
    public function getGenTime(): ?int
    {
        return $this->genTime;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function getPolicyOid(): ?string
    {
        return $this->policyOid;
    }

    public function getTsaCertPem(): ?string
    {
        return $this->tsaCertPem;
    }

    /**
     * Encode raw DER ke base64 untuk JSON transport / DB TEXT column.
     */
    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    /**
     * Rebuild dari base64.
     *
     * @throws ValidationException base64 invalid / hasil kosong
     */
    public static function fromBase64(string $b64, string $tsaUrl, string $algo = 'sha256', string $hash = ''): self
    {
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::forField('bytes', 'invalid base64 timestamp token');
        }
        return new self($bytes, $tsaUrl, $algo, $hash);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'bytes_b64' => base64_encode($this->bytes),
            'bytes_size' => strlen($this->bytes),
            'tsa_url' => $this->tsaUrl,
            'requested_hash_algo' => $this->requestedHashAlgo,
            'requested_data_hash' => $this->requestedDataHash,
            'gen_time' => $this->genTime,
            'gen_time_iso' => $this->genTime !== null ? gmdate('Y-m-d\TH:i:s\Z', $this->genTime) : null,
            'serial_number' => $this->serialNumber,
            'policy_oid' => $this->policyOid,
            'tsa_cert_pem' => $this->tsaCertPem,
        ];
    }

    // -----------------------------------------------------------------
    //  ASN.1 / DER minimal parser
    // -----------------------------------------------------------------

    /**
     * @return array{genTime:int|null, serialNumber:string|null, policyOid:string|null, tsaCertPem:string|null}
     */
    private static function tryParse(string $der): array
    {
        $result = [
            'genTime' => null,
            'serialNumber' => null,
            'policyOid' => null,
            'tsaCertPem' => null,
        ];
        if ($der === '' || strlen($der) < 10) {
            return $result;
        }
        try {
            $nodes = [];
            self::walkDer($der, 0, strlen($der), $nodes, 0);

            // 1) Find TSTInfo: locate id-ct-tstInfo OID (1.2.840.113549.1.9.16.1.4)
            //    then the following OCTET STRING carries TSTInfo DER.
            $tstOid = "\x2A\x86\x48\x86\xF7\x0D\x01\x09\x10\x01\x04";
            $seenTstOid = false;
            foreach ($nodes as $n) {
                if ($seenTstOid && $n['tag'] === 0x04 && strlen($n['content']) > 20) {
                    $inner = $n['content'];
                    if (isset($inner[0]) && $inner[0] === "\x30") {
                        self::parseTstInfo($inner, $result);
                        break;
                    }
                }
                if ($n['tag'] === 0x06 && $n['content'] === $tstOid) {
                    $seenTstOid = true;
                }
            }

            // 2) Fallback genTime: first GeneralizedTime encountered.
            if ($result['genTime'] === null) {
                foreach ($nodes as $n) {
                    if ($n['tag'] === 0x18) {
                        $t = self::parseGeneralizedTime($n['content']);
                        if ($t !== null) {
                            $result['genTime'] = $t;
                            break;
                        }
                    }
                }
            }

            // 3) TSA signing cert: first [0] IMPLICIT context tag with content
            //    that starts with SEQUENCE tag (0x30) and is cert-sized.
            if ($result['tsaCertPem'] === null) {
                foreach ($nodes as $n) {
                    if ($n['tag'] === 0xA0 && strlen($n['content']) > 100 && $n['content'][0] === "\x30") {
                        list($cLen, $cLb) = self::readDerLen($n['content'], 1);
                        $total = 1 + $cLb + $cLen;
                        if ($cLen > 0 && $total <= strlen($n['content'])) {
                            $certBytes = substr($n['content'], 0, $total);
                            $result['tsaCertPem'] = "-----BEGIN CERTIFICATE-----\n"
                                . chunk_split(base64_encode($certBytes), 64, "\n")
                                . "-----END CERTIFICATE-----\n";
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // best-effort; swallow parse errors
        }
        return $result;
    }

    /**
     * TSTInfo ::= SEQUENCE {
     *   version INTEGER, policy OID, messageImprint SEQ, serialNumber INTEGER,
     *   genTime GeneralizedTime, accuracy? SEQ, ordering? BOOLEAN, nonce? INTEGER,
     *   tsa? [0] GeneralName, extensions? [1] Extensions }
     *
     * @param array<string,mixed> $result modified by-ref
     */
    private static function parseTstInfo(string $der, array &$result): void
    {
        $end = strlen($der);
        if ($end < 4 || $der[0] !== "\x30") return;
        $off = 1;
        list($seqLen, $lb) = self::readDerLen($der, $off);
        $off += $lb;
        $stop = min($end, $off + $seqLen);

        // version INTEGER — skip
        if ($off < $stop && $der[$off] === "\x02") {
            $off++;
            list($vLen, $vLb) = self::readDerLen($der, $off);
            $off += $vLb + $vLen;
        }
        // policy OID
        if ($off < $stop && $der[$off] === "\x06") {
            $off++;
            list($oLen, $oLb) = self::readDerLen($der, $off);
            $off += $oLb;
            if ($off + $oLen <= $stop) {
                $result['policyOid'] = self::decodeOid(substr($der, $off, $oLen));
            }
            $off += $oLen;
        }
        // messageImprint SEQUENCE — skip
        if ($off < $stop && $der[$off] === "\x30") {
            $off++;
            list($mLen, $mLb) = self::readDerLen($der, $off);
            $off += $mLb + $mLen;
        }
        // serialNumber INTEGER
        if ($off < $stop && $der[$off] === "\x02") {
            $off++;
            list($sLen, $sLb) = self::readDerLen($der, $off);
            $off += $sLb;
            if ($off + $sLen <= $stop && $sLen > 0) {
                $raw = substr($der, $off, $sLen);
                $hex = bin2hex($raw);
                // Strip leading zero pad if present (positive int leading 0x00)
                if (strlen($hex) > 2 && substr($hex, 0, 2) === '00') {
                    $hex = substr($hex, 2);
                }
                $result['serialNumber'] = $hex;
            }
            $off += $sLen;
        }
        // genTime GeneralizedTime
        if ($off < $stop && $der[$off] === "\x18") {
            $off++;
            list($gLen, $gLb) = self::readDerLen($der, $off);
            $off += $gLb;
            if ($off + $gLen <= $stop) {
                $result['genTime'] = self::parseGeneralizedTime(substr($der, $off, $gLen));
            }
        }
    }

    /**
     * Walk DER tree, collect nodes flat.
     *
     * @param array<int,array<string,mixed>> $out modified by-ref
     */
    private static function walkDer(string $der, int $start, int $end, array &$out, int $depth): void
    {
        $off = $start;
        $len = strlen($der);
        while ($off < $end && $off < $len && $depth < 12) {
            $tag = ord($der[$off]);
            $off++;
            if ($off >= $len) break;
            list($cLen, $cLb) = self::readDerLen($der, $off);
            $off += $cLb;
            if ($cLen < 0 || $off + $cLen > $len) break;
            $content = substr($der, $off, $cLen);
            $out[] = ['tag' => $tag, 'depth' => $depth, 'content' => $content];
            // Constructed types (bit 6 set = 0x20) recurse.
            if (($tag & 0x20) !== 0) {
                self::walkDer($der, $off, $off + $cLen, $out, $depth + 1);
            }
            $off += $cLen;
        }
    }

    /**
     * @return array{0:int,1:int} [length, bytes_consumed]
     */
    private static function readDerLen(string $der, int $offset): array
    {
        $l = strlen($der);
        if ($offset >= $l) return [0, 0];
        $b = ord($der[$offset]);
        if (($b & 0x80) === 0) return [$b, 1];
        $n = $b & 0x7F;
        if ($n === 0 || $n > 4) return [0, 1]; // indefinite atau too big
        if ($offset + $n >= $l) return [0, 1];
        $len = 0;
        for ($i = 1; $i <= $n; $i++) {
            $len = ($len << 8) | ord($der[$offset + $i]);
        }
        return [$len, $n + 1];
    }

    /**
     * Decode DER-encoded OID content bytes ke dot-notation string.
     */
    private static function decodeOid(string $bytes): string
    {
        if ($bytes === '') return '';
        $first = ord($bytes[0]);
        if ($first < 80) {
            $a = intdiv($first, 40);
            $b = $first - $a * 40;
        } else {
            $a = 2;
            $b = $first - 80;
        }
        $parts = [(string) $a, (string) $b];
        $val = 0;
        $len = strlen($bytes);
        for ($i = 1; $i < $len; $i++) {
            $c = ord($bytes[$i]);
            $val = ($val << 7) | ($c & 0x7F);
            if (($c & 0x80) === 0) {
                $parts[] = (string) $val;
                $val = 0;
            }
        }
        return implode('.', $parts);
    }

    /**
     * Parse GeneralizedTime → unix ts UTC. Format: YYYYMMDDHHMMSS[.fff]Z.
     */
    private static function parseGeneralizedTime(string $s): ?int
    {
        if ($s === '') return null;
        $m = [];
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:\.\d+)?Z$/', $s, $m)) {
            return null;
        }
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        return $ts === false ? null : $ts;
    }
}
