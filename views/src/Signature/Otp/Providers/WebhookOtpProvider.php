<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp\Providers;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\Otp\UserOtpProvider;
use Ezdoc\Signature\Otp\OtpSendResult;
use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Remote\CurlHttpClient;
use Ezdoc\Signature\Remote\HttpClient;

/**
 * Universal HTTP/Webhook-based OTP provider.
 *
 * Sends OTP codes via HTTP request to any configurable endpoint URL.
 * Supports placeholder replacement in payload templates: {phone}, {name}, {code}.
 *
 * Transport-agnostic — whether the endpoint delivers via WhatsApp, SMS or
 * email is the endpoint's concern, not this class's.
 *
 * ## When NOT to use this
 *
 * If the delivery code already runs inside the consumer's process, call it
 * directly through {@see CallbackOtpProvider} instead. Pointing this provider
 * at the consumer's own session-guarded endpoint means a loopback request
 * that carries no session — the usual symptom is a blanket HTTP 403.
 *
 * ## Transport
 *
 * Delegates to {@see HttpClient} (default {@see CurlHttpClient}), which
 * verifies TLS certificates and enforces timeouts. Inject a different client
 * for mTLS, a custom CA bundle, or a test double.
 *
 * Usage example:
 *   $provider = new WebhookOtpProvider(
 *       'https://api.example.com/otp/send',
 *       ['to' => '{phone}', 'message' => 'Your OTP is {code}'],
 *       'POST',
 *       ['Authorization: Bearer YOUR_TOKEN', 'Content-Type: application/json']
 *   );
 */
class WebhookOtpProvider implements UserOtpProvider
{
    private string $endpointUrl;
    private string $method;
    private array $headers;
    private array $payloadTemplate;

    /** @var HttpClient */
    private $http;

    /**
     * @param string          $endpointUrl     Target endpoint URL (required).
     * @param array           $payloadTemplate Payload template. Placeholders: {phone}, {name}, {code}.
     * @param string          $method          HTTP method: POST, GET, PUT, ...
     * @param array           $headers         Custom HTTP headers (e.g. ['Authorization: Bearer xyz']).
     * @param HttpClient|null $http            Transport; defaults to {@see CurlHttpClient}.
     */
    public function __construct(
        string $endpointUrl,
        array $payloadTemplate,
        string $method = 'POST',
        array $headers = [],
        ?HttpClient $http = null
    ) {
        if ($endpointUrl === '') {
            throw new \InvalidArgumentException('WebhookOtpProvider: endpointUrl is required.');
        }
        $this->endpointUrl = $endpointUrl;
        $this->payloadTemplate = $payloadTemplate;
        $this->method = strtoupper($method);
        $this->headers = $headers;
        $this->http = $http ?: new CurlHttpClient();
    }

    /**
     * Static Factory Method — resolves dependencies dari konstanta konfigurasi.
     * Digunakan oleh Ezdoc\Context::fromGlobals() jika provider ini di-set.
     *
     * Konstanta yang dibaca:
     * - EZDOC_OTP_WEBHOOK_URL (wajib)
     * - EZDOC_OTP_WEBHOOK_PAYLOAD (wajib, format JSON string)
     * - EZDOC_OTP_WEBHOOK_METHOD (opsional, default POST)
     * - EZDOC_OTP_WEBHOOK_HEADERS (opsional, format JSON array of strings)
     */
    public static function fromGlobals(): self
    {
        $url = defined('EZDOC_OTP_WEBHOOK_URL') ? EZDOC_OTP_WEBHOOK_URL : '';
        if ($url === '') {
            throw new \RuntimeException('WebhookOtpProvider requires the EZDOC_OTP_WEBHOOK_URL constant.');
        }

        $payloadJson = defined('EZDOC_OTP_WEBHOOK_PAYLOAD') ? EZDOC_OTP_WEBHOOK_PAYLOAD : '{}';
        $payload = json_decode($payloadJson, true) ?: [];

        $method = defined('EZDOC_OTP_WEBHOOK_METHOD') ? EZDOC_OTP_WEBHOOK_METHOD : 'POST';

        $headersJson = defined('EZDOC_OTP_WEBHOOK_HEADERS') ? EZDOC_OTP_WEBHOOK_HEADERS : '[]';
        $headers = json_decode($headersJson, true) ?: [];

        return new self($url, $payload, $method, $headers);
    }

    public function send(string $phone, string $name, string $code, array $context = []): OtpSendResult
    {
        $cleanPhone = OtpService::normalizePhone($phone);
        $userName = trim($name) ?: 'User';

        $replacements = [
            '{phone}' => $cleanPhone,
            '{name}'  => $userName,
            '{code}'  => $code,
        ];

        // Process placeholders recursively in payload template
        $payload = $this->applyTemplate($this->payloadTemplate, $replacements);

        // Body encoding follows the caller's declared Content-Type; default to
        // form encoding, which is what most webhook endpoints expect.
        $isJson = false;
        foreach ($this->headers as $header) {
            if (is_string($header) && stripos($header, 'content-type:') === 0
                && stripos($header, 'application/json') !== false) {
                $isJson = true;
                break;
            }
        }

        $options = [
            'headers' => $this->headersToMap($this->headers),
            'timeout' => 15,
        ];
        if (in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['body'] = $isJson ? $payload : http_build_query($payload);
            $options['json'] = $isJson;
        }

        try {
            $resp = $this->http->request($this->method, $this->endpointUrl, $options);
        } catch (EzdocException $e) {
            // Transport failure. The detail (endpoint host, TLS error, DNS)
            // goes to the log; the caller gets a generic message so vendor
            // and infrastructure internals never reach the browser.
            @error_log('[ezdoc:otp:webhook] transport error: ' . $e->getMessage());
            return OtpSendResult::failure('OTP delivery failed (transport error).');
        }

        $httpCode = $resp->getStatusCode();
        $body = $resp->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            // Non-JSON body: fall back to the HTTP status alone.
            if ($resp->isSuccess()) {
                return OtpSendResult::success('OTP sent.', null, ['http_code' => $httpCode]);
            }
            @error_log("[ezdoc:otp:webhook] HTTP {$httpCode}, body: " . substr($body, 0, 500));
            return OtpSendResult::failure('OTP delivery rejected by endpoint.', ['http_code' => $httpCode]);
        }

        // Generic JSON success heuristic — accepts the shapes most webhook
        // endpoints return without requiring per-vendor configuration.
        $status = $decoded['status'] ?? $decoded['state'] ?? '';
        $looksOk = $resp->isSuccess() && (
            $status === 'ok'
            || $status === 'success'
            || !empty($decoded['success'])
            || isset($decoded['message_id'])
        );

        if (!$looksOk) {
            @error_log("[ezdoc:otp:webhook] HTTP {$httpCode}, payload: " . substr($body, 0, 500));
            return OtpSendResult::failure('OTP delivery rejected by endpoint.', ['http_code' => $httpCode]);
        }

        $referenceId = $decoded['broadcast_id'] ?? $decoded['message_id'] ?? $decoded['id'] ?? null;

        return OtpSendResult::success(
            'OTP sent.',
            is_scalar($referenceId) ? (string) $referenceId : null,
            ['http_code' => $httpCode]
        );
    }

    /**
     * Convert `['Header: value']` strings into the `['Header' => 'value']`
     * map that {@see HttpClient} expects. Entries already in map form pass
     * through unchanged.
     *
     * @param array<int|string, string> $headers
     * @return array<string, string>
     */
    private function headersToMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                $map[$key] = (string) $value;
                continue;
            }
            $pos = strpos((string) $value, ':');
            if ($pos === false) {
                continue;
            }
            $map[trim(substr((string) $value, 0, $pos))] = trim(substr((string) $value, $pos + 1));
        }
        return $map;
    }

    private function applyTemplate(array $template, array $replacements): array
    {
        $result = [];
        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->applyTemplate($value, $replacements);
            } elseif (is_string($value)) {
                $result[$key] = strtr($value, $replacements);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
