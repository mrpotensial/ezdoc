<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Providers;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\ProviderCapabilities;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\SignResult;

/**
 * Ezdoc\Signature\Providers\PeruriProvider — level-3 PSrE adapter (STUB).
 *
 * Peruri Digital Sign (Perum Peruri) adalah PSrE tersertifikasi Kominfo
 * milik BUMN. Menyediakan REST API + wrapper SDK (Java/Node) untuk
 * server-side signing dengan HSM. Envelope umum: CMS/PKCS#7 detached,
 * PAdES-B/B-LT untuk PDF. Cocok untuk L3 non-repudiation di bawah UU ITE.
 *
 * ## Status v0.7 — STUB
 *
 * Provider ini adalah SKELETON. Endpoint URL, payload JSON schema, response
 * mapping, dan auth flow SEMUA masih placeholder. Real integration dijadwalkan
 * v0.7.1 begitu sandbox credentials + dokumentasi API tersedia dari Peruri.
 *
 * TODO markers (`// TODO(v0.7-real): ...`) menandai titik-titik yang harus
 * diisi dengan spesifikasi kontrak nyata.
 *
 * ## Where to get sandbox credentials
 *
 * 1. Daftar akun di Peruri developer portal:
 *    - Production docs (as of docs date): https://developer.peruri.co.id
 *    - Sandbox base URL biasanya: https://api-sandbox.peruri.co.id
 *      (VERIFIKASI ke akun manager Peruri — subject to change.)
 * 2. Ajukan onboarding B2B via akun manager Peruri. Butuh:
 *    - NPWP + akta perusahaan
 *    - Signing use case description (mis. "internal HR letters")
 *    - Estimasi volume TTE/bulan (untuk pricing tier)
 * 3. Setelah approved, dapat:
 *    - `client_id` + `client_secret` (OAuth2) ATAU `api_key` + HMAC secret
 *    - Test certificate untuk sandbox signer
 *    - mTLS client cert kalau enterprise tier
 *
 * ## Test cert
 *
 * Sandbox test cert dikeluarkan otomatis untuk signer yang terdaftar di
 * portal (NIK dummy). Untuk verify hasil sign lokal, download intermediate
 * CA bundle Peruri dari portal → simpan sebagai PEM di
 * `config/psre/peruri-ca-bundle.pem` dan pakai untuk cert chain validation.
 *
 * ## Auth flow (asumsi — verifikasi dgn Peruri)
 *
 * Skenario paling umum untuk Peruri:
 *   A. OAuth2 client_credentials → POST /oauth/token → { access_token, expires_in }
 *      → Authorization: Bearer <access_token>
 *   B. API key + HMAC-SHA256 signing → header X-API-Key + X-Timestamp + X-Signature
 *      Signature = HMAC-SHA256(secret, METHOD + '\n' + PATH + '\n' + TIMESTAMP + '\n' + BODY_HASH)
 *   C. mTLS pada TLS handshake untuk enterprise tier
 *
 * Untuk v0.7 stub, `authHeaders()` mengembalikan Bearer placeholder.
 * v0.7.1 harus pilih path berdasarkan tier kontrak.
 *
 * ## Multi-step signing flow (asumsi)
 *
 *   POST /api/v1/sign
 *     → { session_id, status: 'AWAITING_OTP', otp_channel, expires_at }
 *   User dapat OTP via SMS/WhatsApp/Email
 *   POST /api/v1/verify-otp { session_id, otp }
 *     → { status: 'SIGNED', envelope_b64, signer_cert_pem, tsa_timestamp, signed_at }
 *
 * Idempotency: WAJIB kirim X-Request-Id (UUIDv4) di POST /sign untuk menghindari
 * double-charge kalau retry pada 5xx.
 *
 * PHP 7.4+ compatible. Kelas ini tidak me-load provider apa pun untuk
 * runtime hingga BaseRemoteProvider + HttpClient interface tersedia
 * (dijadwalkan v0.7.1 — lihat docs/PSRE-INTEGRATION.md).
 */
final class PeruriProvider extends BaseRemoteProvider
{
    /** @var string */
    const PROVIDER_NAME = 'peruri';

    /** @var int detik */
    const DEFAULT_TIMEOUT = 30;

    /** @var array<string,mixed> */
    private $config;

    /**
     * @param HttpClient          $http   HTTP client swappable (default CurlHttpClient)
     * @param array<string,mixed> $config Required keys: base_url, client_id, client_secret, signer_id.
     *                                    Optional: timeout (int, default 30), callback_url (string),
     *                                    test_mode (bool, default false).
     * @throws ValidationException
     */
    public function __construct(HttpClient $http, array $config)
    {
        $this->assertRequired($config, ['base_url', 'client_id', 'client_secret', 'signer_id']);

        if (!isset($config['timeout']) || !is_int($config['timeout']) || $config['timeout'] <= 0) {
            $config['timeout'] = self::DEFAULT_TIMEOUT;
        }
        if (!isset($config['test_mode'])) {
            $config['test_mode'] = false;
        }

        $this->config = $config;

        // BaseRemoteProvider handles HttpClient injection + shared plumbing
        // (retry, request-id generation, error taxonomy). See v0.7.1 base class.
        parent::__construct($http, $config);
    }

    /**
     * Factory helper: shorthand construction from associative config array.
     * Uses CurlHttpClient default; consumer that needs custom transport
     * (Guzzle wrapper, mock) should call constructor directly.
     *
     * @param array<string,mixed> $config
     * @return self
     * @throws ValidationException
     */
    public static function fromConfig(array $config): self
    {
        // TODO(v0.7-real): allow HttpClient override via $config['http'] if instance.
        return new self(new CurlHttpClient(), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): confirm path — Peruri docs may name it
     * `/v2/sign/document`, `/api/tte/sign`, dsb. Sesuaikan dgn contract.
     */
    protected function initiateSigningEndpoint(): string
    {
        return '/api/v1/sign';
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): confirm — beberapa Peruri tier pakai
     * `/api/v1/sign/{session_id}/confirm` bukan endpoint terpisah.
     */
    protected function verifyOtpEndpoint(): string
    {
        return '/api/v1/verify-otp';
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): mungkin `/api/v1/signer/{signer_id}` atau `/me`
     * bergantung tier. Verifikasi ke portal Peruri.
     */
    protected function getIdentityEndpoint(): string
    {
        return '/api/v1/identity';
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): replace with actual Peruri auth. Ada 3 kemungkinan:
     *
     *   Path A — OAuth2 client_credentials:
     *     $token = $this->fetchOAuthToken();  // cache in memory / storage
     *     return ['Authorization' => 'Bearer ' . $token];
     *
     *   Path B — API key + HMAC-SHA256:
     *     $ts = (string) time();
     *     $sig = hash_hmac('sha256', $method . "\n" . $path . "\n" . $ts . "\n" . $bodyHash, $secret);
     *     return [
     *         'X-API-Key'    => $this->config['client_id'],
     *         'X-Timestamp'  => $ts,
     *         'X-Signature'  => $sig,
     *     ];
     *
     *   Path C — pre-signed JWT bearer (enterprise):
     *     return ['Authorization' => 'Bearer ' . $this->buildClientAssertionJwt()];
     *
     * Untuk stub, kembalikan Bearer placeholder supaya struktur adapter jelas.
     *
     * @return array<string,string>
     */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->buildAccessToken(),
            'X-Request-Id'  => $this->generateRequestId(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Build the JSON body for POST /sign.
     *
     * TODO(v0.7-real): match Peruri's actual JSON schema. Field names di
     * bawah adalah tebakan berdasarkan pola PSrE umum. Kandidat perbedaan:
     *   - Peruri mungkin pakai `nik` bukan `signer_id`
     *   - `content_bytes_b64` bisa jadi `document_base64` atau `payload_b64`
     *   - `sign_type` mungkin enum: 'TANDATANGAN' | 'PARAF' | 'STAMPING'
     *   - Field `visible_signature` (bool) + `page`, `x`, `y`, `width`, `height`
     *     untuk PAdES visible signature
     *   - Field `reason`, `location`, `contact_info` (RFC 3161-style meta)
     *
     * @param SignRequest $req
     * @return array<string,mixed>
     */
    protected function buildSignRequestPayload(SignRequest $req): array
    {
        $signType = 'CMS'; // default detached CMS envelope
        if ($req->getEnvelopeFormat() === 'pades') {
            $signType = 'PDF'; // TODO(v0.7-real): confirm Peruri PDF sign flag
        }

        $payload = [
            'signer_id'        => $this->config['signer_id'],
            'content_hash'     => $req->getContentHash(),
            'content_bytes_b64' => base64_encode($req->getContentBytes()),
            'sign_type'        => $signType,
            'callback_url'     => isset($this->config['callback_url'])
                ? $this->config['callback_url']
                : null,
            // TODO(v0.7-real): tambah `visible_signature`, `reason`, `location`
            //                  saat kontrak signing_type = PDF (PAdES) dikonfirmasi.
        ];

        // TODO(v0.7-real): Peruri kemungkinan wajibkan idempotency_key di body,
        // bukan hanya header X-Request-Id. Cek docs.
        if ($this->config['test_mode']) {
            $payload['test_mode'] = true;
        }

        return $payload;
    }

    /**
     * Parse response POST /sign atau POST /verify-otp menjadi SignResult
     * saat status akhir sudah 'SIGNED'.
     *
     * TODO(v0.7-real): mapping field response Peruri. Bentuk yang diharapkan:
     *   {
     *     "session_id": "sess_abc123",
     *     "status": "SIGNED",
     *     "envelope_b64": "MIIF...",         // base64(CMS)
     *     "signer_cert_pem": "-----BEGIN CERTIFICATE----- ...",
     *     "tsa_timestamp": "2026-07-10T03:14:15Z",
     *     "signed_at": "2026-07-10T03:14:14Z",
     *     "signer_metadata": { "cn": "Nama", "nik": "3xxxx", "serial": "..." }
     *   }
     *
     * Kalau field beda nama, adjust di mapping di bawah.
     *
     * @param HttpResponse $res
     * @return SignResult
     * @throws EzdocException on shape mismatch
     */
    protected function parseSignResponse(HttpResponse $res): SignResult
    {
        $body = $this->decodeJsonBody($res);

        // TODO(v0.7-real): tighten shape check. For now assume success shape.
        if (!isset($body['envelope_b64']) || !is_string($body['envelope_b64'])) {
            throw new EzdocException(
                'PeruriProvider::parseSignResponse missing envelope_b64',
                ['status_code' => $res->getStatusCode(), 'body_keys' => array_keys($body)]
            );
        }
        $envelope = base64_decode($body['envelope_b64'], true);
        if ($envelope === false || $envelope === '') {
            throw new EzdocException(
                'PeruriProvider::parseSignResponse envelope_b64 not decodable'
            );
        }

        $format = 'pkcs7';
        // TODO(v0.7-real): detect PAdES vs PKCS7 dari header field
        // seperti `envelope_format` atau `sign_type` — Peruri response
        // biasanya tulis eksplisit.

        return new SignResult([
            'envelope'       => $envelope,
            'envelopeFormat' => $format,
            'signerId'       => $this->config['signer_id'],
            'certificatePem' => isset($body['signer_cert_pem']) && is_string($body['signer_cert_pem'])
                ? $body['signer_cert_pem']
                : null,
            'providerName'   => self::PROVIDER_NAME,
            'level'          => 3,
            'signedAt'       => isset($body['signed_at']) && is_string($body['signed_at'])
                ? $body['signed_at']
                : self::nowIso8601Ms(),
            'metadata'       => [
                'session_id'    => isset($body['session_id']) ? $body['session_id'] : null,
                'tsa_timestamp' => isset($body['tsa_timestamp']) ? $body['tsa_timestamp'] : null,
                'signer_meta'   => isset($body['signer_metadata']) ? $body['signer_metadata'] : [],
                // TODO(v0.7-real): pass raw provider payload for audit trail.
            ],
        ]);
    }

    /**
     * Parse POST /sign response yang masih AWAITING_OTP → SignSession DTO.
     *
     * TODO(v0.7-real): shape session response:
     *   {
     *     "session_id": "...",
     *     "status": "AWAITING_OTP",
     *     "expires_at": "2026-07-10T03:24:15Z",
     *     "callback_url": null,
     *     "otp_channel": "SMS",
     *     "otp_masked_target": "0812***5678"
     *   }
     *
     * @param HttpResponse $res
     * @return SignSession
     * @throws EzdocException
     */
    protected function parseSessionResponse(HttpResponse $res): SignSession
    {
        $body = $this->decodeJsonBody($res);
        if (!isset($body['session_id']) || !is_string($body['session_id'])) {
            throw new EzdocException(
                'PeruriProvider::parseSessionResponse missing session_id'
            );
        }
        // TODO(v0.7-real): map full status enum (PENDING|AWAITING_OTP|SIGNED|FAILED|EXPIRED).
        return new SignSession([
            'session_id'    => $body['session_id'],
            'provider'      => self::PROVIDER_NAME,
            'document_ref'  => isset($body['document_ref']) ? (string) $body['document_ref'] : '',
            'status'        => isset($body['status']) ? (string) $body['status'] : 'PENDING',
            'created_at'    => self::nowIso8601Ms(),
            'expires_at'    => isset($body['expires_at']) ? (string) $body['expires_at'] : null,
            'callback_url'  => isset($this->config['callback_url'])
                ? $this->config['callback_url']
                : null,
        ]);
    }

    /**
     * Parse OTP challenge metadata dari response awaiting-OTP.
     *
     * TODO(v0.7-real): shape OTP fields:
     *   {
     *     "otp_channel": "SMS" | "EMAIL" | "WA",
     *     "otp_masked_target": "0812***5678",
     *     "otp_attempts_remaining": 3,
     *     "otp_resend_available_at": "2026-07-10T03:15:15Z"
     *   }
     *
     * @param HttpResponse $res
     * @return OtpChallenge
     * @throws EzdocException
     */
    protected function parseOtpChallenge(HttpResponse $res): OtpChallenge
    {
        $body = $this->decodeJsonBody($res);
        return new OtpChallenge([
            'session_id'          => isset($body['session_id']) ? (string) $body['session_id'] : '',
            'channel'             => isset($body['otp_channel']) ? (string) $body['otp_channel'] : 'SMS',
            'masked_target'       => isset($body['otp_masked_target']) ? (string) $body['otp_masked_target'] : '',
            'attempts_remaining'  => isset($body['otp_attempts_remaining']) ? (int) $body['otp_attempts_remaining'] : 3,
            'resend_available_at' => isset($body['otp_resend_available_at']) ? (string) $body['otp_resend_available_at'] : null,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getCaps(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            self::PROVIDER_NAME,
            3,
            ['pkcs7', 'pades'],
            true,
            null,
            'Level 3 PSrE Peruri (STUB v0.7). PKCS#7 detached + PAdES-B/B-LT. '
            . 'TSA timestamping via Peruri TSA endpoint. Real API mapping pending v0.7.1.'
        );
    }

    /**
     * Placeholder for OAuth2 token fetch / API-key derivation.
     *
     * TODO(v0.7-real): implement real cache-aware token acquisition.
     * Contoh untuk OAuth2 client_credentials:
     *
     *   $res = $this->http->post(
     *     $this->config['base_url'] . '/oauth/token',
     *     [
     *       'grant_type' => 'client_credentials',
     *       'client_id' => $this->config['client_id'],
     *       'client_secret' => $this->config['client_secret'],
     *       'scope' => 'sign',
     *     ],
     *     ['Content-Type' => 'application/x-www-form-urlencoded']
     *   );
     *   $data = json_decode($res->getBody(), true);
     *   return $data['access_token'];
     *
     * Untuk stub, return placeholder string yang jelas ditandai
     * agar tidak accidentally lolos ke request nyata.
     */
    protected function buildAccessToken(): string
    {
        // TODO(v0.7-real): replace with real token acquisition + memory cache.
        return 'STUB_PERURI_TOKEN_PLACEHOLDER_DO_NOT_SHIP';
    }
}
