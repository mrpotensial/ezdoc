<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Remote;

use Ezdoc\Audit\Logger;
use Ezdoc\Exceptions\AccessDeniedException;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\ProviderCapabilities;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\SignResult;
use Ezdoc\Signature\SignatureProvider;
use Ezdoc\Signature\Verdict;
use Ezdoc\Signature\VerifyContext;

/**
 * Ezdoc\Signature\Remote\BaseRemoteProvider — abstract base untuk semua
 * SignatureProvider yang berbicara ke PSrE remote REST API (Peruri,
 * Privy, VIDA, BSrE, Digisign, TekenAja, iOTENTIK, dsb).
 *
 * ## Template-method pattern
 *
 * Subclass wajib implement metode abstrak (endpoint path, payload
 * builder, response parser, auth headers, provider name). Base class
 * mengurus:
 *
 *   - HTTP client wiring + retry (3x exponential backoff untuk error
 *     network / 5xx)
 *   - Request-Id (idempotency) header di setiap POST — mencegah dobel
 *     charge / dobel session pada retry
 *   - Response status validation ({@see requireSuccess()})
 *   - Audit log tiap state transition (siap regulasi UU ITE / PP 71
 *     kalau `Logger` di-inject)
 *
 * ## Config schema
 *
 * Constructor menerima `$config` associative array. Key yang dikenali:
 *
 *   - `base_url`         (string, required)   scheme://host tanpa trailing /
 *   - `api_key`          (string, optional)   dipakai default authHeaders()
 *   - `client_id`        (string, optional)   sebagian PSrE pakai ini
 *   - `client_secret`    (string, optional)   HMAC signing / OAuth2
 *   - `timeout`          (int,    optional)   detik; default 30
 *   - `max_retries`      (int,    optional)   default 3
 *   - `retry_base_ms`    (int,    optional)   base backoff (ms); default 500
 *   - `default_headers`  (array,  optional)   selalu di-merge di setiap req
 *   - `poll_interval_ms` (int,    optional)   default 1500
 *   - `poll_max_tries`   (int,    optional)   default 20 (~30s poll window)
 *
 * Consumer (subclass) boleh baca key tambahan lewat {@see getConfig()}.
 *
 * ## Error taxonomy
 *
 * {@see requireSuccess()} mapping HTTP status ke exception canonical:
 *
 *   - 401/403 → {@see AccessDeniedException}  (invalid key / expired token)
 *   - 404     → {@see NotFoundException}      (session / cert not found)
 *   - 400/422 → {@see ValidationException}    (payload / OTP invalid)
 *   - 5xx     → {@see EzdocException}         (provider unavailable — retryable)
 *   - lain    → {@see EzdocException}         (generic)
 *
 * Subclass boleh override `requireSuccess()` untuk mapping code-specific
 * (mis. `OTP_INVALID` → domain exception khusus).
 *
 * ## OpenSSL cross-runtime note
 *
 * Untuk verify envelope (PKCS#7 / PAdES) lokal, subclass yang butuh
 * akses OpenSSL resource TIDAK boleh type-hint sebagai
 * `\OpenSSLAsymmetricKey` / `\OpenSSLCertificate` di signature — PHP 7.x
 * masih pakai resource(int). Pakai `mixed` (docblock) + runtime guard
 * `class_exists('OpenSSLCertificate', false)` bila perlu diskriminasi.
 *
 * PHP 7.4+ compatible.
 */
abstract class BaseRemoteProvider implements SignatureProvider
{
    /** @var HttpClient */
    private $http;

    /** @var array<string,mixed> */
    private $config;

    /** @var Logger|null */
    private $logger;

    /** @var int */
    private $maxRetries;

    /** @var int base backoff in ms */
    private $retryBaseMs;

    /** @var int per-request timeout in seconds */
    private $timeout;

    /** @var string base_url normalized (no trailing slash) */
    private $baseUrl;

    /** @var int poll interval ms */
    private $pollIntervalMs;

    /** @var int poll max tries */
    private $pollMaxTries;

    /**
     * @param HttpClient          $http
     * @param array<string,mixed> $config lihat class doc schema
     * @param Logger|null         $logger optional audit sink
     * @throws ValidationException
     */
    public function __construct(HttpClient $http, array $config, ?Logger $logger = null)
    {
        if (!isset($config['base_url']) || !is_string($config['base_url']) || $config['base_url'] === '') {
            throw ValidationException::forField('config.base_url', 'required non-empty string');
        }
        $this->baseUrl = rtrim($config['base_url'], '/');

        $this->http = $http;
        $this->config = $config;
        $this->logger = $logger;

        $this->maxRetries = isset($config['max_retries']) && is_numeric($config['max_retries'])
            ? max(0, (int) $config['max_retries'])
            : 3;

        $this->retryBaseMs = isset($config['retry_base_ms']) && is_numeric($config['retry_base_ms'])
            ? max(50, (int) $config['retry_base_ms'])
            : 500;

        $this->timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? max(1, (int) $config['timeout'])
            : 30;

        $this->pollIntervalMs = isset($config['poll_interval_ms']) && is_numeric($config['poll_interval_ms'])
            ? max(100, (int) $config['poll_interval_ms'])
            : 1500;

        $this->pollMaxTries = isset($config['poll_max_tries']) && is_numeric($config['poll_max_tries'])
            ? max(1, (int) $config['poll_max_tries'])
            : 20;
    }

    // ---------------------------------------------------------------------
    // Abstract contract — subclass wajib implement
    // ---------------------------------------------------------------------

    /**
     * Nama provider unik (mis. 'peruri', 'privy', 'vida', 'bsre').
     */
    abstract public function getProviderName(): string;

    /**
     * Path (relative ke base_url) untuk initiate signing session.
     * Contoh: '/api/v1/sign' atau '/documents/sign'.
     */
    abstract protected function initiateSigningEndpoint(): string;

    /**
     * Path untuk submit OTP verification.
     * Contoh: '/api/v1/verify-otp'.
     */
    abstract protected function verifyOtpEndpoint(): string;

    /**
     * Path untuk cek identity / profile / cert status.
     * Contoh: '/api/v1/identity' atau '/me'.
     */
    abstract protected function getIdentityEndpoint(): string;

    /**
     * Build request body untuk initiate signing dari {@see SignRequest}.
     * Return array yang akan di-JSON-encode oleh HTTP client.
     *
     * @return array<string,mixed>
     */
    abstract protected function buildSignRequestPayload(SignRequest $req): array;

    /**
     * Parse response initiate (kalau signing sync/langsung selesai) atau
     * response OTP submit yang membawa envelope final, ke {@see SignResult}.
     *
     * @throws EzdocException
     */
    abstract protected function parseSignResponse(HttpResponse $resp): SignResult;

    /**
     * Parse response initiate menjadi {@see SignSession} — dipakai kalau
     * flow-nya async (pending → otp_required → processing → completed).
     *
     * @throws EzdocException
     */
    abstract protected function parseSessionResponse(HttpResponse $resp): SignSession;

    /**
     * Parse response initiate yang butuh OTP jadi {@see OtpChallenge}.
     * Boleh return null kalau provider tidak sediakan detail OTP.
     *
     * @throws EzdocException
     */
    abstract protected function parseOtpChallenge(HttpResponse $resp): OtpChallenge;

    /**
     * Auth headers yang dipasang di setiap request. Contoh:
     *   - `Authorization: Bearer <token>` (OAuth2)
     *   - `X-API-Key`, `X-Signature`, `X-Timestamp` (HMAC signing)
     *
     * Subclass yang butuh token refresh cache OAuth2 boleh implement di
     * sini (dengan LazyToken / TokenCache di property).
     *
     * @return array<string,string>
     */
    abstract protected function authHeaders(): array;

    // ---------------------------------------------------------------------
    // Subclass hook — override optional
    // ---------------------------------------------------------------------

    /**
     * Override untuk deklarasi capabilities khusus provider. Default:
     * level 2, format ['pkcs7','pades','raw'], TSA supported.
     */
    protected function getCaps(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            $this->getProviderName(),
            2,
            ['pkcs7', 'pades', 'raw'],
            true,
            null,
            'Remote PSrE provider (' . $this->getProviderName() . ')'
        );
    }

    // ---------------------------------------------------------------------
    // SignatureProvider — final concrete implementations
    // ---------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Sync path: initiate → kalau response completed, langsung parse jadi
     * SignResult. Kalau otp_required / pending / processing, poll sampai
     * completed atau timeout. Untuk flow yang butuh interaksi manual OTP
     * (SMS/email confirm), caller sebaiknya pakai {@see initiate()} +
     * {@see submitOtp()} directly instead of {@see sign()}.
     *
     * @throws EzdocException
     */
    final public function sign(SignRequest $req): SignResult
    {
        $resp = $this->initiateRaw($req);
        $this->requireSuccess($resp);

        // Coba parse sebagai session dulu untuk cek status; kalau gagal
        // (provider return SignResult langsung tanpa envelope status),
        // fall back ke parseSignResponse.
        try {
            $session = $this->parseSessionResponse($resp);
        } catch (EzdocException $e) {
            // Provider sync — langsung parse jadi result
            return $this->parseSignResponse($resp);
        }

        if ($session->isCompleted()) {
            return $this->parseSignResponse($resp);
        }

        // Async — poll until completed / expired
        $sid = $session->getSessionId();
        $tries = 0;
        while ($tries < $this->pollMaxTries) {
            $tries++;
            usleep($this->pollIntervalMs * 1000);
            $session = $this->getSession($sid);
            if ($session->isCompleted()) {
                $final = $this->httpGet($this->initiateSigningEndpoint() . '/' . rawurlencode($sid));
                $this->requireSuccess($final);
                return $this->parseSignResponse($final);
            }
            if ($session->isExpired()) {
                throw new EzdocException(
                    'Remote signing session expired',
                    ['provider' => $this->getProviderName(), 'session_id' => $sid]
                );
            }
            if ($session->getStatus() === SignSession::STATUS_FAILED) {
                throw new EzdocException(
                    'Remote signing session failed',
                    ['provider' => $this->getProviderName(), 'session_id' => $sid]
                );
            }
            if ($session->needsOtp()) {
                // Butuh input OTP — caller harus pakai submitOtp() flow
                throw new EzdocException(
                    'Remote signing requires OTP; use initiate() + submitOtp() flow instead of sign()',
                    ['provider' => $this->getProviderName(), 'session_id' => $sid]
                );
            }
        }

        throw new EzdocException(
            'Remote signing poll timeout',
            [
                'provider' => $this->getProviderName(),
                'session_id' => $sid,
                'poll_tries' => $tries,
            ]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Default: POST envelope + content ke `/verify` endpoint (kalau
     * provider expose). Subclass boleh override untuk local verification
     * murni (mis. PKCS#7 dengan cert yang di-cache).
     */
    final public function verify(string $envelope, VerifyContext $ctx): Verdict
    {
        if ($envelope === '') {
            return Verdict::error('empty envelope');
        }

        try {
            $payload = [
                'envelope_b64' => base64_encode($envelope),
                'content_b64' => base64_encode($ctx->getContentBytes()),
                'signer_id' => $ctx->getExpectedSignerId(),
            ];
            $resp = $this->httpPost('/verify', ['body' => $payload, 'json' => true]);
        } catch (EzdocException $e) {
            return Verdict::error(
                'Remote verify transport error: ' . $e->getMessage(),
                ['provider' => $this->getProviderName()]
            );
        }

        if (!$resp->isSuccess()) {
            return Verdict::error(
                'Remote verify HTTP ' . $resp->getStatusCode(),
                ['provider' => $this->getProviderName(), 'body' => $resp->getBody()]
            );
        }

        try {
            $data = $resp->getJsonBody();
        } catch (ValidationException $e) {
            return Verdict::error('Remote verify invalid JSON body');
        }

        $status = isset($data['status']) ? strtolower((string) $data['status']) : Verdict::STATUS_ERROR;
        $signerId = isset($data['signer_id']) ? (string) $data['signer_id'] : null;
        $signedAt = isset($data['signed_at']) ? (string) $data['signed_at'] : null;
        $reason = isset($data['reason']) ? (string) $data['reason'] : 'verified via remote';
        $checks = isset($data['checks']) && is_array($data['checks']) ? $data['checks'] : [];

        return new Verdict($status, $signerId, $signedAt, $reason, $checks);
    }

    /**
     * {@inheritdoc}
     */
    final public function capabilities(): ProviderCapabilities
    {
        return $this->getCaps();
    }

    // ---------------------------------------------------------------------
    // Multi-step flow — public entry points untuk caller (Controller)
    // ---------------------------------------------------------------------

    /**
     * Start async signing session. Return SignSession dengan status yang
     * menunjukkan flow selanjutnya (otp_required → submitOtp, dst).
     *
     * @throws EzdocException
     */
    public function initiate(SignRequest $req): SignSession
    {
        $resp = $this->initiateRaw($req);
        $this->requireSuccess($resp);
        $session = $this->parseSessionResponse($resp);
        $this->audit('psre.initiate', [
            'result' => 'success',
            'metadata' => [
                'provider' => $this->getProviderName(),
                'session_id' => $session->getSessionId(),
                'status' => $session->getStatus(),
            ],
        ]);
        return $session;
    }

    /**
     * Submit OTP untuk session yang sudah initiate. Return SignResult
     * kalau envelope langsung terbit; kalau masih processing, caller
     * harus polling via {@see getSession()}.
     *
     * @throws EzdocException
     */
    public function submitOtp(string $sessionId, string $otp): SignResult
    {
        if ($sessionId === '') {
            throw ValidationException::forField('sessionId', 'required non-empty string');
        }
        if ($otp === '') {
            throw ValidationException::forField('otp', 'required non-empty string');
        }

        $resp = $this->httpPost(
            $this->verifyOtpEndpoint(),
            [
                'body' => [
                    'session_id' => $sessionId,
                    'otp' => $otp,
                ],
                'json' => true,
                'headers' => ['X-Request-Id' => $this->makeRequestId($sessionId . ':otp')],
            ]
        );
        $this->requireSuccess($resp);

        $result = $this->parseSignResponse($resp);
        $this->audit('psre.otp.verified', [
            'result' => 'success',
            'metadata' => [
                'provider' => $this->getProviderName(),
                'session_id' => $sessionId,
            ],
        ]);
        return $result;
    }

    /**
     * Poll status session. Endpoint default: initiate path + '/{sid}'.
     * Override kalau provider pakai path lain (mis. '/sessions/{sid}').
     *
     * @throws EzdocException
     */
    public function getSession(string $sessionId): SignSession
    {
        if ($sessionId === '') {
            throw ValidationException::forField('sessionId', 'required non-empty string');
        }
        $path = $this->initiateSigningEndpoint() . '/' . rawurlencode($sessionId);
        $resp = $this->httpGet($path);
        $this->requireSuccess($resp);
        return $this->parseSessionResponse($resp);
    }

    // ---------------------------------------------------------------------
    // HTTP helpers — untuk subclass
    // ---------------------------------------------------------------------

    /**
     * Getter untuk config raw (subclass butuh key custom).
     *
     * @return array<string,mixed>
     */
    final protected function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Lookup 1 config key dengan default.
     *
     * @param mixed $default
     * @return mixed
     */
    final protected function getConfigValue(string $key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    /**
     * @param array<string,mixed> $options
     */
    final protected function httpGet(string $path, array $options = []): HttpResponse
    {
        return $this->requestWithRetry('GET', $path, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    final protected function httpPost(string $path, array $options = []): HttpResponse
    {
        // Auto-inject X-Request-Id kalau caller belum set
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            $options['headers'] = [];
        }
        if (!self::headerExists($options['headers'], 'X-Request-Id')) {
            $options['headers']['X-Request-Id'] = $this->makeRequestId($path);
        }
        return $this->requestWithRetry('POST', $path, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    final protected function httpPut(string $path, array $options = []): HttpResponse
    {
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            $options['headers'] = [];
        }
        if (!self::headerExists($options['headers'], 'X-Request-Id')) {
            $options['headers']['X-Request-Id'] = $this->makeRequestId($path);
        }
        return $this->requestWithRetry('PUT', $path, $options);
    }

    /**
     * Ensure 2xx; kalau tidak, map HTTP status ke exception canonical.
     * Subclass boleh override untuk mapping code-specific (mis. body
     * `{code: "OTP_INVALID"}` → OtpInvalidError domain exception).
     *
     * @throws EzdocException
     */
    final protected function requireSuccess(HttpResponse $resp): void
    {
        if ($resp->isSuccess()) return;

        $status = $resp->getStatusCode();
        $ctx = [
            'provider' => $this->getProviderName(),
            'http_status' => $status,
            'body_preview' => substr($resp->getBody(), 0, 512),
        ];

        // Coba extract error code / message dari JSON envelope
        $code = null;
        $message = null;
        try {
            $data = $resp->getJsonBody();
            if (isset($data['code']) && is_string($data['code'])) {
                $code = $data['code'];
                $ctx['error_code'] = $code;
            }
            if (isset($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
                $ctx['error_message'] = $message;
            }
            if (isset($data['request_id']) && is_string($data['request_id'])) {
                $ctx['request_id'] = $data['request_id'];
            }
        } catch (ValidationException $e) {
            // body bukan JSON — biarkan
        }

        $baseMsg = $this->getProviderName() . ' HTTP ' . $status;
        if ($code !== null) $baseMsg .= ' [' . $code . ']';
        if ($message !== null) $baseMsg .= ' ' . $message;

        if ($status === 401 || $status === 403) {
            throw new AccessDeniedException($baseMsg, $ctx);
        }
        if ($status === 404) {
            throw new NotFoundException($baseMsg, $ctx);
        }
        if ($status === 400 || $status === 422) {
            throw new ValidationException($baseMsg, $ctx);
        }
        // 5xx dan lain-lain — generic EzdocException; caller decide retryable
        throw new EzdocException($baseMsg, $ctx);
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    /**
     * Kirim raw request untuk initiate — tidak apply requireSuccess()
     * karena caller (sign / initiate) mungkin butuh inspect status.
     */
    private function initiateRaw(SignRequest $req): HttpResponse
    {
        $payload = $this->buildSignRequestPayload($req);
        return $this->httpPost(
            $this->initiateSigningEndpoint(),
            [
                'body' => $payload,
                'json' => true,
                'headers' => [
                    'X-Request-Id' => $this->makeRequestId($req->getContentHash()),
                ],
            ]
        );
    }

    /**
     * Dispatch ke HttpClient dengan retry exponential backoff untuk error
     * network / 5xx. Retry HANYA untuk GET dan idempotent POST (yang
     * bawa X-Request-Id yang sama — provider harus dedupe di sisi mereka).
     *
     * @param array<string,mixed> $options
     * @throws EzdocException
     */
    private function requestWithRetry(string $method, string $path, array $options): HttpResponse
    {
        $url = $this->resolveUrl($path);
        $options = $this->prepareOptions($options);

        $attempt = 0;
        $lastNetErr = null;
        while (true) {
            $attempt++;
            try {
                $resp = $this->http->request($method, $url, $options);
            } catch (EzdocException $e) {
                $lastNetErr = $e;
                // Retry network error
                if ($attempt > $this->maxRetries) {
                    throw new EzdocException(
                        'Remote request failed after retries: ' . $e->getMessage(),
                        [
                            'provider' => $this->getProviderName(),
                            'method' => $method,
                            'url' => $url,
                            'attempts' => $attempt,
                        ],
                        $e
                    );
                }
                $this->backoff($attempt);
                continue;
            }

            // Retry hanya untuk 5xx (server error) atau 429 (rate limit)
            $status = $resp->getStatusCode();
            $retryable = ($status >= 500 && $status < 600) || $status === 429;
            if ($retryable && $attempt <= $this->maxRetries) {
                $this->backoff($attempt);
                continue;
            }
            return $resp;
        }
    }

    /**
     * Merge auth + default headers ke request options.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function prepareOptions(array $options): array
    {
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            $options['headers'] = [];
        }
        $auth = $this->authHeaders();
        foreach ($auth as $k => $v) {
            if (!self::headerExists($options['headers'], $k)) {
                $options['headers'][$k] = $v;
            }
        }
        $defaultHeaders = $this->getConfigValue('default_headers', []);
        if (is_array($defaultHeaders)) {
            foreach ($defaultHeaders as $k => $v) {
                if (is_string($k) && !self::headerExists($options['headers'], $k)) {
                    $options['headers'][$k] = $v;
                }
            }
        }
        if (!isset($options['timeout'])) {
            $options['timeout'] = $this->timeout;
        }
        return $options;
    }

    /**
     * Combine base_url + path (path bisa absolute URL — passthrough).
     */
    private function resolveUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $this->baseUrl . $path;
    }

    private function backoff(int $attempt): void
    {
        // Exponential + small jitter: base * 2^(attempt-1) + rand(0..base/2)
        $ms = $this->retryBaseMs * (1 << ($attempt - 1));
        $jitter = (int) (mt_rand(0, (int) max(1, $this->retryBaseMs / 2)));
        $sleep = ($ms + $jitter) * 1000;
        if ($sleep > 0) usleep($sleep);
    }

    /**
     * Deterministic-ish request id — pakai contenthash + provider + nano
     * timestamp. Caller boleh replace via header 'X-Request-Id' explicit.
     */
    private function makeRequestId(string $salt): string
    {
        $seed = $this->getProviderName() . '|' . $salt . '|' . microtime(true) . '|' . mt_rand();
        return substr(hash('sha256', $seed), 0, 32);
    }

    /**
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

    /**
     * Silent audit sink — no-op kalau logger null.
     *
     * @param array<string,mixed> $ctx
     */
    protected function audit(string $eventType, array $ctx): void
    {
        if ($this->logger === null) return;
        try {
            $this->logger->log($eventType, $ctx);
        } catch (\Throwable $e) {
            // audit silent-fail — jangan block business flow
        }
    }
}
