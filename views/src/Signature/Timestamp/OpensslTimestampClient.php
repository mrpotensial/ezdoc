<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Timestamp;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\Remote\HttpClient;

/**
 * Ezdoc\Signature\Timestamp\OpensslTimestampClient — reference RFC 3161
 * client memakai shell-out ke `openssl ts` CLI.
 *
 * ## Kenapa CLI?
 *
 * PHP tidak punya native OpenSSL TSA API (`openssl_ts_*` tidak ada).
 * Pure-PHP ASN.1 emit + parse untuk RFC 3161 verbose dan error-prone.
 * Yang paling stabil di lingkungan produksi Linux/Windows adalah
 * shell-out ke `openssl` CLI yang sudah battle-tested.
 *
 * ## Requirements
 *
 *   - `openssl` binary tersedia di PATH
 *   - PHP function `proc_open` tidak di-disable (`disable_functions`)
 *   - Untuk request: openssl >= 1.0.2 (`ts -query -digest ... -cert`)
 *   - Untuk verify: openssl >= 1.1.0 (`-digest` di `-verify`)
 *
 * Kalau `proc_open` disabled → throw {@see EzdocException}. Consumer bisa
 * fallback ke {@see HttpTimestampClient} yang tidak butuh CLI.
 *
 * ## Config
 *
 *   - `timeout` (int, default 60)          — detik HTTP request
 *   - `auth_header` (string, optional)     — mis. 'Basic ' . base64(user:pass) untuk BSrE
 *   - `ca_bundle_path` (string, optional)  — path PEM untuk verify (required kalau
 *                                            mau verify; kalau kosong verify return
 *                                            untrusted)
 *   - `openssl_bin` (string, default 'openssl') — override path binary
 *
 * PHP 7.4+ compatible.
 */
final class OpensslTimestampClient implements TimestampClient
{
    /** @var string */
    private $tsaUrl;

    /** @var HttpClient */
    private $http;

    /** @var array<string,mixed> */
    private $config;

    /** @var string */
    private $opensslBin;

    /** @var int */
    private $timeout;

    /** @var string tempfile prefix */
    const TMP_PREFIX = 'ezts_';

    /**
     * @param string              $tsaUrl RFC 3161 TSA endpoint
     * @param HttpClient          $http
     * @param array<string,mixed> $config
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
        $this->opensslBin = isset($config['openssl_bin']) && is_string($config['openssl_bin']) && $config['openssl_bin'] !== ''
            ? $config['openssl_bin']
            : 'openssl';
        $this->timeout = isset($config['timeout']) && is_numeric($config['timeout']) && (int) $config['timeout'] > 0
            ? (int) $config['timeout']
            : 60;
    }

    /**
     * {@inheritdoc}
     */
    public function requestTimestamp(string $dataHash, string $hashAlgo = 'sha256'): TimestampToken
    {
        self::assertShellAvailable();

        $algo = strtolower($hashAlgo);
        if (!in_array($algo, ['sha1', 'sha256', 'sha384', 'sha512'], true)) {
            throw ValidationException::forField('hashAlgo', 'unsupported hash algorithm: ' . $hashAlgo);
        }
        $hexHash = self::normalizeToHex($dataHash);
        if ($hexHash === '') {
            throw ValidationException::forField('dataHash', 'empty hash');
        }

        // 1) Build TimeStampReq DER via `openssl ts -query -digest <hex> -<algo> -cert`
        //    Nonce di-include by default (jangan pass -no_nonce).
        $tsqPath = self::mkTmp();
        try {
            $args = [
                'ts', '-query',
                '-digest', $hexHash,
                '-' . $algo,
                '-cert',
                '-out', $tsqPath,
            ];
            list($code, $stdout, $stderr) = $this->runOpenssl($args);
            if ($code !== 0) {
                throw new EzdocException(
                    'openssl ts -query failed (exit=' . $code . '): ' . trim($stderr),
                    ['args' => $args, 'stdout' => $stdout, 'stderr' => $stderr]
                );
            }
            $reqBytes = @file_get_contents($tsqPath);
            if ($reqBytes === false || $reqBytes === '') {
                throw new EzdocException('openssl ts -query produced empty output');
            }
        } finally {
            self::safeUnlink($tsqPath);
        }

        // 2) POST ke TSA endpoint
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

        // Optional Content-Type sanity — TSA harus balas application/timestamp-reply,
        // tapi beberapa dev TSA balas octet-stream. Warning only, tidak throw.
        return new TimestampToken($body, $this->tsaUrl, $algo, $hexHash);
    }

    /**
     * {@inheritdoc}
     */
    public function verifyTimestamp(TimestampToken $token, string $originalDataHash): TimestampVerdict
    {
        self::assertShellAvailable();

        $caBundle = isset($this->config['ca_bundle_path']) && is_string($this->config['ca_bundle_path'])
            ? $this->config['ca_bundle_path']
            : '';
        if ($caBundle === '' || !is_file($caBundle)) {
            return TimestampVerdict::untrusted(
                'no ca_bundle_path configured — cannot verify TSA chain',
                ['ca_bundle_path' => $caBundle]
            );
        }

        $hexHash = self::normalizeToHex($originalDataHash);
        if ($hexHash === '') {
            return TimestampVerdict::invalid('empty originalDataHash');
        }

        $tokenPath = self::mkTmp();
        $tsaCertPath = null;
        try {
            if (file_put_contents($tokenPath, $token->getBytes()) === false) {
                return TimestampVerdict::invalid('failed to write token to temp file');
            }
            @chmod($tokenPath, 0600);

            $args = [
                'ts', '-verify',
                '-in', $tokenPath,
                '-digest', $hexHash,
                '-CAfile', $caBundle,
            ];

            // Embed TSA cert (kalau ada) sebagai untrusted intermediate.
            if ($token->getTsaCertPem() !== null) {
                $tsaCertPath = self::mkTmp();
                if (@file_put_contents($tsaCertPath, $token->getTsaCertPem()) !== false) {
                    @chmod($tsaCertPath, 0600);
                    $args[] = '-untrusted';
                    $args[] = $tsaCertPath;
                }
            }

            list($code, $stdout, $stderr) = $this->runOpenssl($args);
            $combined = trim($stdout . "\n" . $stderr);

            $checks = [
                'exit_code' => $code,
                'ca_bundle' => $caBundle,
                'openssl_output' => $combined,
                'gen_time' => $token->getGenTime(),
            ];

            if ($code === 0 && strpos($combined, 'Verification: OK') !== false) {
                $ts = $token->getGenTime();
                if ($ts === null) $ts = time();
                return TimestampVerdict::valid($ts, 'openssl ts -verify OK', $checks);
            }
            if (strpos($combined, 'Verification: FAILED') !== false || strpos($combined, 'mismatch') !== false) {
                return TimestampVerdict::invalid(
                    'openssl ts -verify FAILED: ' . self::truncate($combined, 300),
                    $checks
                );
            }
            return TimestampVerdict::invalid(
                'openssl ts -verify error (exit=' . $code . '): ' . self::truncate($combined, 300),
                $checks
            );
        } finally {
            self::safeUnlink($tokenPath);
            if ($tsaCertPath !== null) self::safeUnlink($tsaCertPath);
        }
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    /**
     * Jalankan `openssl <args>` via proc_open. Return [exitCode, stdout, stderr].
     * Semua args di-escape lewat escapeshellarg.
     *
     * @param array<int,string> $args
     * @return array{0:int,1:string,2:string}
     * @throws EzdocException
     */
    private function runOpenssl(array $args): array
    {
        $escaped = [];
        foreach ($args as $a) {
            $escaped[] = escapeshellarg($a);
        }
        // opensslBin may contain spaces on Windows — escape terpisah.
        $cmd = escapeshellarg($this->opensslBin) . ' ' . implode(' ', $escaped);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($proc)) {
            throw new EzdocException('proc_open failed for: ' . $cmd);
        }
        @fclose($pipes[0]);
        $stdout = @stream_get_contents($pipes[1]);
        $stderr = @stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $code = @proc_close($proc);
        if (!is_int($code)) $code = -1;
        return [
            $code,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
        ];
    }

    /**
     * @throws EzdocException kalau shell disabled
     */
    private static function assertShellAvailable(): void
    {
        if (!function_exists('proc_open')) {
            throw new EzdocException(
                'OpenSSL CLI required for OpensslTimestampClient but proc_open() is disabled — '
                . 'use HttpTimestampClient as fallback (verify unsupported)'
            );
        }
    }

    /**
     * Terima hex string ATAU raw binary hash → return lowercase hex.
     */
    private static function normalizeToHex(string $hash): string
    {
        if ($hash === '') return '';
        if (ctype_xdigit($hash) && (strlen($hash) % 2 === 0)) {
            return strtolower($hash);
        }
        return bin2hex($hash);
    }

    /**
     * @throws EzdocException tempnam gagal
     */
    private static function mkTmp(): string
    {
        $p = @tempnam(sys_get_temp_dir(), self::TMP_PREFIX);
        if ($p === false) {
            throw new EzdocException('tempnam() failed');
        }
        return $p;
    }

    private static function safeUnlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '...(truncated)';
    }
}
