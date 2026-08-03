<?php

declare(strict_types=1);

namespace Ezdoc\Http;

/**
 * Ezdoc\Http\ResponseWriter — deferred-emit response builder.
 *
 * Contract: handlers build up status + headers + body via chainable calls,
 * then App::run() decides whether to {@see emit()} (send headers + return
 * body) or capture the body for the framework adapter. Anti-pattern #4
 * from PRD §6.13 explicitly forbids owning the response cycle — this class
 * never calls exit/die.
 *
 * Stream mode: for large assets we bypass the in-memory buffer via
 * {@see stream()} — emit() invokes readfile() and returns null (no body
 * string, headers already sent).
 *
 * PHP 7.4+ compatible.
 */
final class ResponseWriter
{
    /** @var int */
    private $status = 200;

    /** @var array<string,string> Header name → single value. */
    private $headers = [];

    /** @var string Accumulated body (unless streaming). */
    private $body = '';

    /** @var string|null Absolute path to stream on emit. */
    private $streamFile = null;

    /** @var bool True after emit() ran once (idempotent). */
    private $emitted = false;

    public function status(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** Append to body. */
    public function write(string $chunk): self
    {
        $this->body .= $chunk;
        return $this;
    }

    /**
     * Set body to HTML content with proper Content-Type.
     */
    public function html(string $content, int $status = 200): self
    {
        $this->status  = $status;
        $this->body    = $content;
        $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $this;
    }

    /**
     * Set body to JSON-encoded payload.
     *
     * @param mixed $data
     */
    public function json($data, int $status = 200): self
    {
        $this->status  = $status;
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->body    = $encoded === false ? '{}' : $encoded;
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $this;
    }

    /**
     * Prepare a 3xx redirect. Does NOT emit — call emit() after.
     */
    public function redirect(string $url, int $status = 302): self
    {
        $this->status  = $status;
        $this->headers['Location'] = $url;
        $this->body = '';
        return $this;
    }

    /**
     * Mark this response as a file stream. emit() will readfile() the path
     * then return null (body is not buffered).
     */
    public function stream(string $absPath, string $contentType = 'application/octet-stream'): self
    {
        $this->streamFile = $absPath;
        $this->headers['Content-Type'] = $contentType;
        return $this;
    }

    /**
     * Convenience: JSON error payload with status code.
     */
    public function error(string $message, int $status = 500): self
    {
        return $this->json(['success' => false, 'error' => $message], $status);
    }

    /**
     * Send headers + status (if not already sent) and return the body string.
     * For stream responses: readfile() to output then return null.
     *
     * Safe to call multiple times — subsequent calls are no-ops re: headers.
     */
    public function emit(): ?string
    {
        if ($this->emitted) {
            return $this->streamFile !== null ? null : $this->body;
        }
        $this->emitted = true;
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->streamFile !== null) {
            @readfile($this->streamFile);
            return null;
        }
        return $this->body;
    }

    // ─── Introspection helpers (for testing + framework adapters) ─────

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStreamFile(): ?string
    {
        return $this->streamFile;
    }
}
