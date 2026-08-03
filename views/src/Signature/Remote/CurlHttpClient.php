<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Remote;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Remote\CurlHttpClient — default {@see HttpClient}
 * implementation memakai ekstensi curl.
 *
 * ## Default options
 *
 * Constructor menerima array `$defaultOptions` yang berisi opsi curl
 * (CURLOPT_* key => value) untuk selalu di-apply di setiap request.
 * Cocok untuk config yang jarang berubah:
 *
 *   - CURLOPT_SSL_VERIFYPEER / CURLOPT_SSL_VERIFYHOST — TLS verify
 *   - CURLOPT_CAINFO — CA bundle path
 *   - CURLOPT_SSLCERT / CURLOPT_SSLKEY / CURLOPT_SSLKEYPASSWD — mTLS
 *     (dipakai BSrE production, Peruri enterprise tier)
 *   - CURLOPT_PROXY / CURLOPT_PROXYUSERPWD — corporate proxy
 *
 * Default options di-*merge* dengan per-request options; per-request
 * value menang. Header per-request selalu ditambah/replace default header.
 *
 * ## Error mapping
 *
 * Network / DNS / timeout / TLS handshake failure → {@see EzdocException}
 * dengan context berisi CURLE code + curl_error() message.
 * HTTP 4xx/5xx → return {@see HttpResponse} apa adanya, biar caller yang
 * mapping ke exception domain-specific.
 *
 * PHP 7.4+ compatible.
 */
final class CurlHttpClient implements HttpClient
{
    /** @var array<int,mixed> */
    private $defaultOptions;

    /** @var int */
    const DEFAULT_TIMEOUT_SEC = 30;

    /**
     * @param array<int,mixed> $defaultOptions curl CURLOPT_* key => value.
     *                                         Tidak divalidasi — caller tanggung
     *                                         jawab pastikan key valid.
     * @throws ValidationException kalau ekstensi curl tidak tersedia
     */
    public function __construct(array $defaultOptions = [])
    {
        if (!extension_loaded('curl')) {
            throw ValidationException::forField(
                'ext-curl',
                'CurlHttpClient requires PHP curl extension'
            );
        }
        $this->defaultOptions = $defaultOptions;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        if ($url === '') {
            throw ValidationException::forField('url', 'must be non-empty');
        }
        if ($method === '') {
            throw ValidationException::forField('method', 'must be non-empty');
        }
        $method = strtoupper($method);

        // ---- Merge options
        $headers = isset($options['headers']) && is_array($options['headers'])
            ? $options['headers']
            : [];
        $body = array_key_exists('body', $options) ? $options['body'] : null;
        $asJson = !empty($options['json']);
        $timeout = isset($options['timeout']) && is_numeric($options['timeout'])
            ? (int) $options['timeout']
            : self::DEFAULT_TIMEOUT_SEC;
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT_SEC;

        // Optional query string append
        if (isset($options['query']) && is_array($options['query']) && !empty($options['query'])) {
            $qs = http_build_query($options['query']);
            if ($qs !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $qs;
            }
        }

        // ---- Body encoding
        $bodyString = null;
        if ($body !== null) {
            if ($asJson) {
                if (!is_array($body) && !is_object($body) && !is_string($body)) {
                    throw ValidationException::forField('body', 'JSON body must be array|object|string');
                }
                if (is_string($body)) {
                    // assume caller already provided JSON string
                    $bodyString = $body;
                } else {
                    $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($encoded === false) {
                        throw ValidationException::forField(
                            'body',
                            'json_encode failed: ' . json_last_error_msg()
                        );
                    }
                    $bodyString = $encoded;
                }
                // Add default Content-Type kalau caller belum set (case-insensitive)
                if (!self::headerExists($headers, 'Content-Type')) {
                    $headers['Content-Type'] = 'application/json';
                }
            } else {
                if (is_array($body)) {
                    // form-urlencoded
                    $bodyString = http_build_query($body);
                    if (!self::headerExists($headers, 'Content-Type')) {
                        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                    }
                } elseif (is_string($body)) {
                    $bodyString = $body;
                } else {
                    throw ValidationException::forField('body', 'must be string|array');
                }
            }
        }

        // ---- Build curl handle
        $ch = curl_init();
        if ($ch === false) {
            throw new EzdocException('CurlHttpClient: curl_init() failed', [
                'url' => $url,
                'method' => $method,
            ]);
        }

        // Apply default options first, then override with per-request settings
        if (!empty($this->defaultOptions)) {
            curl_setopt_array($ch, $this->defaultOptions);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // include response headers in body
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 10));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // let caller handle redirects

        // TLS safe defaults — hanya kalau caller belum override via defaultOptions
        if (!array_key_exists(CURLOPT_SSL_VERIFYPEER, $this->defaultOptions)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        }
        if (!array_key_exists(CURLOPT_SSL_VERIFYHOST, $this->defaultOptions)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        // Headers → CURLOPT_HTTPHEADER format "Key: Value"
        if (!empty($headers)) {
            $hdrList = [];
            foreach ($headers as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                if (is_array($v)) {
                    foreach ($v as $vi) {
                        $hdrList[] = $k . ': ' . (string) $vi;
                    }
                } else {
                    $hdrList[] = $k . ': ' . (string) $v;
                }
            }
            if (!empty($hdrList)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrList);
            }
        }

        // Body
        if ($bodyString !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyString);
        }

        // ---- Execute
        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            curl_close($ch);
            throw new EzdocException(
                'CurlHttpClient: transport error: ' . $errstr,
                [
                    'url' => $url,
                    'method' => $method,
                    'curl_errno' => $errno,
                    'curl_error' => $errstr,
                ]
            );
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawStr = is_string($raw) ? $raw : '';
        $rawHeaders = $headerSize > 0 ? substr($rawStr, 0, $headerSize) : '';
        $body = $headerSize > 0 ? substr($rawStr, $headerSize) : $rawStr;
        if ($body === false) $body = '';

        $parsedHeaders = self::parseHeaders($rawHeaders);

        return new HttpResponse($status, $parsedHeaders, $body);
    }

    /**
     * Parse raw HTTP header block ke assoc array.
     * Kalau ada redirect atau proxy, curl bisa balikin beberapa header block
     * berturut-turut — kita ambil block terakhir.
     *
     * @return array<string,string>
     */
    private static function parseHeaders(string $raw): array
    {
        if ($raw === '') return [];
        // Split per response block (redirect chain)
        $blocks = preg_split("/\r?\n\r?\n/", trim($raw));
        if ($blocks === false || empty($blocks)) return [];
        $last = end($blocks);

        $out = [];
        $lines = preg_split("/\r?\n/", $last);
        if ($lines === false) return [];
        foreach ($lines as $line) {
            if ($line === '' || strpos($line, 'HTTP/') === 0) continue;
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') continue;
            // Kalau duplikat header (mis. Set-Cookie), append koma-separated
            if (isset($out[$name])) {
                $out[$name] .= ', ' . $value;
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Case-insensitive header existence check.
     *
     * @param array<string,mixed> $headers
     */
    private static function headerExists(array $headers, string $name): bool
    {
        $lc = strtolower($name);
        foreach (array_keys($headers) as $k) {
            if (is_string($k) && strtolower($k) === $lc) return true;
        }
        return false;
    }
}
