<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Remote;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Remote\HttpResponse — immutable HTTP response DTO.
 *
 * Header lookup pakai case-insensitive key — sesuai RFC 7230 header name
 * bukan case-sensitive. Storage internal pakai lowercase key + preserve
 * original untuk {@see getHeaders()}.
 *
 * PHP 7.4+ compatible.
 */
final class HttpResponse
{
    /** @var int */
    private $statusCode;

    /** @var array<string,string> original-case keys */
    private $headers;

    /** @var array<string,string> lowercased keys for lookup */
    private $headersLc;

    /** @var string */
    private $body;

    /**
     * @param int                   $statusCode HTTP status 100..599 (clamp)
     * @param array<string,string>  $headers    header name => value (original case)
     * @param string                $body       raw response body
     */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        // Clamp status ke range HTTP normal
        if ($statusCode < 0) $statusCode = 0;
        if ($statusCode > 999) $statusCode = 999;
        $this->statusCode = $statusCode;

        $clean = [];
        $lc = [];
        foreach ($headers as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            // Value bisa array kalau multi-value header — flatten ke koma
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', $v));
            }
            $v = (string) $v;
            $clean[$k] = $v;
            $lc[strtolower($k)] = $v;
        }
        $this->headers = $clean;
        $this->headersLc = $lc;

        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string,string> original-case keys
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Case-insensitive header lookup.
     */
    public function getHeader(string $name): ?string
    {
        $k = strtolower($name);
        return isset($this->headersLc[$k]) ? $this->headersLc[$k] : null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * True untuk HTTP 2xx.
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Decode body sebagai JSON associative array.
     *
     * @return array<string|int,mixed>
     * @throws ValidationException kalau body kosong atau bukan JSON valid
     *                             atau top-level bukan object/array
     */
    public function getJsonBody(): array
    {
        if ($this->body === '') {
            throw ValidationException::forField('body', 'response body is empty, cannot decode JSON');
        }
        $decoded = json_decode($this->body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::forField(
                'body',
                'response body is not valid JSON: ' . json_last_error_msg()
            );
        }
        if (!is_array($decoded)) {
            throw ValidationException::forField(
                'body',
                'JSON body top-level must be object or array, got ' . gettype($decoded)
            );
        }
        return $decoded;
    }
}
