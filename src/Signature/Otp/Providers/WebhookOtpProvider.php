<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp\Providers;

use Ezdoc\Signature\Otp\UserOtpProvider;
use Ezdoc\Signature\Otp\OtpSendResult;
use Ezdoc\Signature\Otp\OtpService;

/**
 * Universal HTTP/Webhook-based OTP provider.
 *
 * Sends OTP codes via HTTP request to any configurable endpoint URL.
 * Supports placeholder replacement in payload templates: {phone}, {name}, {code}.
 *
 * This provider is transport-agnostic — it doesn't care whether the endpoint
 * sends via WhatsApp, SMS, Email, or any other channel. That's the responsibility
 * of the webhook endpoint itself.
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

    /**
     * @param string $endpointUrl     URL endpoint tujuan (wajib).
     * @param array  $payloadTemplate Template array payload. Placeholders: {phone}, {name}, {code}.
     * @param string $method          HTTP method: POST, GET, PUT, dll.
     * @param array  $headers         Custom HTTP headers (e.g. ['Authorization: Bearer xyz']).
     */
    public function __construct(
        string $endpointUrl,
        array $payloadTemplate,
        string $method = 'POST',
        array $headers = []
    ) {
        if ($endpointUrl === '') {
            throw new \InvalidArgumentException('WebhookOtpProvider: endpointUrl wajib diisi.');
        }
        $this->endpointUrl = $endpointUrl;
        $this->payloadTemplate = $payloadTemplate;
        $this->method = strtoupper($method);
        $this->headers = $headers;
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
            throw new \RuntimeException('WebhookOtpProvider membutuhkan konstanta EZDOC_OTP_WEBHOOK_URL.');
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

        $ch = curl_init();

        $curlOptions = [
            CURLOPT_URL            => $this->endpointUrl,
            CURLOPT_CUSTOMREQUEST  => $this->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        if (!empty($this->headers)) {
            $curlOptions[CURLOPT_HTTPHEADER] = $this->headers;
        }

        if (in_array($this->method, ['POST', 'PUT', 'PATCH'], true)) {
            // Check if Content-Type is application/json
            $isJson = false;
            foreach ($this->headers as $header) {
                if (stripos($header, 'Content-Type: application/json') !== false) {
                    $isJson = true;
                    break;
                }
            }
            $curlOptions[CURLOPT_POSTFIELDS] = $isJson ? json_encode($payload) : http_build_query($payload);
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return OtpSendResult::failure("Koneksi ke Webhook OTP gagal: {$errorMsg}", [
                'curl_errno' => $errno,
                'http_code'  => $httpCode,
            ]);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            if ($httpCode >= 200 && $httpCode < 300) {
                return OtpSendResult::success('OTP berhasil dikirim (Webhook HTTP OK).', null, [
                    'raw_response' => substr((string) $response, 0, 500),
                    'http_code'    => $httpCode,
                ]);
            }
            return OtpSendResult::failure("Respon Webhook tidak valid (HTTP {$httpCode}).", [
                'raw_response' => substr((string) $response, 0, 500),
                'http_code'    => $httpCode,
            ]);
        }

        // Generic JSON success checking
        $status = $decoded['status'] ?? $decoded['state'] ?? '';
        if ($httpCode < 200 || $httpCode >= 300 || ($status !== 'ok' && $status !== 'success' && empty($decoded['success']) && !isset($decoded['message_id']))) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Pengiriman OTP gagal di sisi Webhook.';
            return OtpSendResult::failure($msg, $decoded);
        }

        return OtpSendResult::success(
            'OTP berhasil dikirim via Webhook.',
            $decoded['broadcast_id'] ?? $decoded['message_id'] ?? $decoded['id'] ?? null,
            $decoded
        );
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
