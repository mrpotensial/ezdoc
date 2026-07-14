<?php

declare(strict_types=1);

namespace Ezdoc\Http;

/**
 * Ezdoc\Http\RequestContext — framework-agnostic HTTP request VO.
 *
 * Purpose: library code MUST NOT touch $_GET / $_POST / $_SERVER directly.
 * Every read that would otherwise hit a super-global goes through this
 * object. Consumer adapters (Laravel, Slim, CI4, Symfony) build one from
 * their framework's request object; native PHP consumers use
 * {@see self::fromGlobals()}.
 *
 * PHP 7.4+ compatible — no readonly, no promoted props, no enums.
 *
 * @psalm-immutable
 */
final class RequestContext
{
    /** @var string HTTP verb (upper-case). */
    public $method;

    /** @var array<string,mixed> Parsed query params (like $_GET). */
    public $query;

    /** @var array<string,mixed> Parsed form params (like $_POST). */
    public $post;

    /** @var array<string,mixed> Server env (like $_SERVER). */
    public $server;

    /** @var array<string,mixed> Uploaded files (like $_FILES). */
    public $files;

    /** @var string Raw request body (php://input equivalent). */
    public $rawBody;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        string $method,
        array $query,
        array $post,
        array $server,
        array $files = [],
        string $rawBody = ''
    ) {
        $this->method  = strtoupper($method);
        $this->query   = $query;
        $this->post    = $post;
        $this->server  = $server;
        $this->files   = $files;
        $this->rawBody = $rawBody;
    }

    /**
     * Build from PHP super-globals — main entry for plain-PHP consumers.
     * NOTE: this is the ONLY place in the library allowed to read $_GET etc.
     */
    public static function fromGlobals(): self
    {
        $method  = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $rawBody = '';
        // Only read body for methods that may have one — avoid blocking on CLI.
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $raw = @file_get_contents('php://input');
            $rawBody = is_string($raw) ? $raw : '';
        }
        return new self(
            $method,
            isset($_GET) ? $_GET : [],
            isset($_POST) ? $_POST : [],
            isset($_SERVER) ? $_SERVER : [],
            isset($_FILES) ? $_FILES : [],
            $rawBody
        );
    }

    /**
     * Build from an array override — for tests + framework adapters.
     *
     * @param array<string,mixed> $override Accepts: method, query, post, server, files, rawBody
     */
    public static function fromArray(array $override): self
    {
        return new self(
            isset($override['method']) ? (string) $override['method'] : 'GET',
            isset($override['query']) && is_array($override['query']) ? $override['query'] : [],
            isset($override['post']) && is_array($override['post']) ? $override['post'] : [],
            isset($override['server']) && is_array($override['server']) ? $override['server'] : [],
            isset($override['files']) && is_array($override['files']) ? $override['files'] : [],
            isset($override['rawBody']) ? (string) $override['rawBody'] : ''
        );
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, $default = null)
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function post(string $key, $default = null)
    {
        return array_key_exists($key, $this->post) ? $this->post[$key] : $default;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Request path (script-relative or absolute). */
    public function path(): string
    {
        $uri = isset($this->server['REQUEST_URI']) ? (string) $this->server['REQUEST_URI'] : '';
        $q   = strpos($uri, '?');
        return $q === false ? $uri : substr($uri, 0, $q);
    }

    /**
     * Case-insensitive header lookup. Returns null if absent.
     */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        // Some servers expose Content-Type / Content-Length without HTTP_ prefix.
        $upper = strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$upper])) {
            return (string) $this->server[$upper];
        }
        return null;
    }

    /**
     * True if request looks like AJAX or an Ezdoc action dispatch signal.
     */
    public function isAjax(): bool
    {
        if (($this->header('X-Requested-With') ?? '') === 'XMLHttpRequest') {
            return true;
        }
        return isset($this->post['ajax'])
            || isset($this->post['_ajax'])
            || isset($this->post['_doc_action']);
    }

    /**
     * @return array<string,mixed>
     */
    public function files(): array
    {
        return $this->files;
    }
}
