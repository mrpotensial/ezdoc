<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Providers;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\ProviderCapabilities;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\SignResult;

/**
 * Ezdoc\Signature\Providers\PrivyProvider — level-3 PSrE adapter (STUB).
 *
 * PrivyID adalah PSrE tersertifikasi Kominfo yang populer di fintech,
 * banking, dan HR SaaS Indonesia. Menyediakan REST API + mobile SDK.
 * Untuk enterprise tier, signing server-side (HSM-held key). Untuk retail,
 * biometric on-device via Privy mobile app.
 *
 * ## Status v0.7 — STUB
 *
 * Provider ini adalah SKELETON. Real integration dijadwalkan v0.7.1 begitu
 * sandbox credentials + dokumentasi kontrak API tersedia dari Privy.
 *
 * TODO markers (`// TODO(v0.7-real): ...`) menandai titik-titik yang
 * harus dilengkapi.
 *
 * ## Privy vs Peruri — perbedaan penting
 *
 *   - **Signer lookup**: Privy pakai `signer_email` (Privy account) —
 *     BUKAN NIK seperti Peruri. Signer harus sudah punya Privy Account
 *     dan sudah verified (KYC via foto KTP + selfie).
 *   - **Consent flow**: Privy dominant flow adalah push notification ke
 *     mobile app (Privy Sign) — user tap approve di HP, bukan input OTP
 *     di web form. Untuk enterprise API-driven, OTP-by-email atau OTP-by-WA
 *     tersedia sebagai fallback.
 *   - **merchant_id**: Privy require merchant_id (kontrak level enterprise
 *     account) untuk semua request — dikirim di header atau body.
 *   - **Envelope default**: PAdES untuk PDF, PKCS#7/CMS untuk detached.
 *     Privy menyimpan salinan signed document di storage mereka dan
 *     mengembalikan `document_url` (temporary signed URL).
 *
 * ## Where to get sandbox credentials
 *
 * 1. Daftar merchant di Privy business portal:
 *    - https://business.privy.id (verifikasi URL saat integrasi)
 *    - Sandbox base URL biasanya: https://api-sandbox.privy.id
 * 2. Onboarding butuh:
 *    - NPWP + akta
 *    - Sample dokumen yang akan ditandatangani
 *    - Jumlah signer registered di Privy (jika < X, ada tier discount)
 * 3. Setelah approved:
 *    - `client_id` + `client_secret`
 *    - `merchant_id` (Privy-specific tenant identifier)
 *    - Test signer accounts (email sandbox yang bisa diverifikasi)
 *
 * ## Test cert
 *
 * Privy issue cert di sisi Privy CA — consumer TIDAK pegang private key
 * signer. Untuk verify hasil sign, gunakan Privy CA public cert bundle
 * (download di portal → `config/psre/privy-ca-bundle.pem`).
 *
 * ## Auth flow (asumsi — verifikasi dgn Privy)
 *
 *   POST /oauth/token
 *     Content-Type: application/x-www-form-urlencoded
 *     body: grant_type=client_credentials&client_id=...&client_secret=...
 *     → { access_token, token_type: 'Bearer', expires_in: 3600 }
 *
 *   Alternatif tier: HMAC-SHA256 di header X-Signature
 *   dengan X-Merchant-Id + X-Timestamp.
 *
 * ## Multi-step signing flow (asumsi)
 *
 *   POST /api/v1/sign
 *     body: { merchant_id, signer_email, document, ... }
 *     → { session_id, status: 'AWAITING_APPROVAL', channel: 'PRIVY_APP' | 'OTP_EMAIL', ... }
 *
 *   Path A (mobile app approval):
 *     Poll GET /api/v1/sign/{session_id}
 *     Atau register webhook_url — Privy POST balik saat SIGNED
 *
 *   Path B (OTP fallback):
 *     POST /api/v1/verify-otp { session_id, otp }
 *     → { status: 'SIGNED', envelope_b64, document_url, ... }
 *
 * PHP 7.4+ compatible.
 */
final class PrivyProvider extends BaseRemoteProvider
{
    /** @var string */
    const PROVIDER_NAME = 'privy';

    /** @var int */
    const DEFAULT_TIMEOUT = 30;

    /** @var array<string,mixed> */
    private $config;

    /**
     * @param HttpClient          $http
     * @param array<string,mixed> $config Required: base_url, client_id, client_secret,
     *                                    merchant_id, signer_email.
     *                                    Optional: timeout (default 30), callback_url,
     *                                    test_mode (bool).
     * @throws ValidationException
     */
    public function __construct(HttpClient $http, array $config)
    {
        $this->assertRequired(
            $config,
            ['base_url', 'client_id', 'client_secret', 'merchant_id', 'signer_email']
        );

        if (!isset($config['timeout']) || !is_int($config['timeout']) || $config['timeout'] <= 0) {
            $config['timeout'] = self::DEFAULT_TIMEOUT;
        }
        if (!isset($config['test_mode'])) {
            $config['test_mode'] = false;
        }

        // Lightweight email sanity check — full validation di Privy side.
        if (!filter_var($config['signer_email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::forField('signer_email', 'must be valid email');
        }

        $this->config = $config;

        parent::__construct($http, $config);
    }

    /**
     * @param array<string,mixed> $config
     * @return self
     * @throws ValidationException
     */
    public static function fromConfig(array $config): self
    {
        // TODO(v0.7-real): support HttpClient override via $config['http'].
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
     * TODO(v0.7-real): Privy docs mungkin pakai `/v1/documents` atau
     * `/enterprise/sign`. Ini asumsi berdasarkan pola PSrE umum.
     */
    protected function initiateSigningEndpoint(): string
    {
        return '/api/v1/sign';
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): utk Privy, flow default adalah **mobile push approval**,
     * bukan OTP input. Endpoint ini hanya berlaku kalau tier merchant meng-
     * enable OTP fallback. Konfirmasi via Privy account manager.
     */
    protected function verifyOtpEndpoint(): string
    {
        return '/api/v1/verify-otp';
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): kandidat `/api/v1/user/{email}` atau
     * `/api/v1/signer/lookup?email=...`.
     */
    protected function getIdentityEndpoint(): string
    {
        return '/api/v1/identity';
    }

    /**
     * {@inheritdoc}
     *
     * TODO(v0.7-real): pilih path auth berdasarkan tier contract:
     *
     *   Path A — OAuth2 client_credentials (dominant di Privy Enterprise):
     *     Bearer access_token dari POST /oauth/token
     *     + X-Merchant-Id header
     *
     *   Path B — API key + HMAC (Privy Signing API / Retail):
     *     X-API-Key, X-Timestamp, X-Signature
     *     Signature = base64(HMAC-SHA256(secret, METHOD + PATH + TIMESTAMP + BODY))
     *
     * @return array<string,string>
     */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->buildAccessToken(),
            'X-Merchant-Id' => (string) $this->config['merchant_id'],
            'X-Request-Id'  => $this->generateRequestId(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Build sign request body.
     *
     * TODO(v0.7-real): confirm JSON schema. Peluang perbedaan:
     *   - Privy pakai `signer_email` bukan `signer_id`
     *   - Field `document` (nested): { name, type: 'pdf', base64: '...' }
     *   - `signature_positions`: [{ page, x, y, width, height, signer_email }]
     *   - `callback_url` + `redirect_url` (untuk web-flow signing)
     *   - Flag `notify_signer` (bool) — kirim email invite otomatis
     *
     * @param SignRequest $req
     * @return array<string,mixed>
     */
    protected function buildSignRequestPayload(SignRequest $req): array
    {
        $signType = 'CMS';
        if ($req->getEnvelopeFormat() === 'pades') {
            $signType = 'PDF';
        }

        $payload = [
            'merchant_id'      => $this->config['merchant_id'],
            'signer_email'     => $this->config['signer_email'], // PRIVY: email, bukan NIK
            'content_hash'     => $req->getContentHash(),
            'content_bytes_b64' => base64_encode($req->getContentBytes()),
            'sign_type'        => $signType,
            'callback_url'     => isset($this->config['callback_url'])
                ? $this->config['callback_url']
                : null,
            // TODO(v0.7-real): support signature positioning fields untuk PAdES.
        ];

        if ($this->config['test_mode']) {
            $payload['test_mode'] = true;
        }

        return $payload;
    }

    /**
     * Parse response menjadi SignResult saat status 'SIGNED'.
     *
     * TODO(v0.7-real): field mapping Privy — kandidat shape:
     *   {
     *     "session_id": "sess_xxx",
     *     "status": "SIGNED",
     *     "envelope_b64": "MIIF...",
     *     "document_url": "https://storage.privy.id/signed/xxx.pdf?sig=...",
     *     "signer_cert_pem": "-----BEGIN CERTIFICATE-----...",
     *     "signed_at": "2026-07-10T03:14:15Z",
     *     "tsa_timestamp": "2026-07-10T03:14:16Z",
     *     "signer_metadata": { "email": "user@rsiaanugrah.com", "name": "...", "privy_id": "PRV..." }
     *   }
     *
     * @param HttpResponse $res
     * @return SignResult
     * @throws EzdocException
     */
    protected function parseSignResponse(HttpResponse $res): SignResult
    {
        $body = $this->decodeJsonBody($res);

        if (!isset($body['envelope_b64']) || !is_string($body['envelope_b64'])) {
            throw new EzdocException(
                'PrivyProvider::parseSignResponse missing envelope_b64',
                ['status_code' => $res->getStatusCode(), 'body_keys' => array_keys($body)]
            );
        }
        $envelope = base64_decode($body['envelope_b64'], true);
        if ($envelope === false || $envelope === '') {
            throw new EzdocException(
                'PrivyProvider::parseSignResponse envelope_b64 not decodable'
            );
        }

        $format = 'pkcs7';
        // TODO(v0.7-real): detect PAdES envelope via response flag.

        return new SignResult([
            'envelope'       => $envelope,
            'envelopeFormat' => $format,
            'signerId'       => (string) $this->config['signer_email'],
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
                'document_url'  => isset($body['document_url']) ? $body['document_url'] : null,
                'tsa_timestamp' => isset($body['tsa_timestamp']) ? $body['tsa_timestamp'] : null,
                'signer_meta'   => isset($body['signer_metadata']) ? $body['signer_metadata'] : [],
                'merchant_id'   => $this->config['merchant_id'],
                // TODO(v0.7-real): raw provider payload untuk audit.
            ],
        ]);
    }

    /**
     * Parse AWAITING_* response → SignSession DTO.
     *
     * TODO(v0.7-real): Privy status enum kemungkinan:
     *   PENDING | AWAITING_APPROVAL | AWAITING_OTP | SIGNED | FAILED | EXPIRED | CANCELLED
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
                'PrivyProvider::parseSessionResponse missing session_id'
            );
        }
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
     * Parse OTP challenge fields. Untuk Privy default flow (mobile push),
     * ini bisa return challenge dgn channel='PRIVY_APP' — bukan OTP klasik.
     *
     * TODO(v0.7-real): field mapping — mungkin `push_channel` bukan `otp_channel`,
     * mungkin `approval_url` bukan `masked_target`.
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
            'channel'             => isset($body['otp_channel']) ? (string) $body['otp_channel'] : 'PRIVY_APP',
            'masked_target'       => isset($body['otp_masked_target'])
                ? (string) $body['otp_masked_target']
                : (isset($this->config['signer_email']) ? self::maskEmail((string) $this->config['signer_email']) : ''),
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
            'Level 3 PSrE Privy (STUB v0.7). PKCS#7 detached + PAdES. Default '
            . 'consent channel: mobile push (Privy App). OTP fallback available. '
            . 'Real API mapping pending v0.7.1.'
        );
    }

    /**
     * Placeholder for OAuth2 / API-key derivation.
     *
     * TODO(v0.7-real): implement real token acquisition. Contoh OAuth2:
     *
     *   $res = $this->http->post(
     *     rtrim($this->config['base_url'], '/') . '/oauth/token',
     *     http_build_query([
     *       'grant_type'    => 'client_credentials',
     *       'client_id'     => $this->config['client_id'],
     *       'client_secret' => $this->config['client_secret'],
     *       'scope'         => 'sign document.read',
     *     ]),
     *     ['Content-Type' => 'application/x-www-form-urlencoded']
     *   );
     *
     * Cache token in-memory + honor `expires_in` sebelum re-fetch.
     */
    protected function buildAccessToken(): string
    {
        // TODO(v0.7-real): replace with real token acquisition + cache.
        return 'STUB_PRIVY_TOKEN_PLACEHOLDER_DO_NOT_SHIP';
    }

    /**
     * Mask email untuk display OTP target — 'u***r@domain.tld'.
     */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 2) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $head = substr($local, 0, 1);
        $tail = substr($local, -1);
        return $head . str_repeat('*', max(1, strlen($local) - 2)) . $tail . $domain;
    }
}
