<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Remote;

/**
 * Ezdoc\Signature\Remote\HttpClient — kontrak minimal HTTP client yang
 * dipakai oleh {@see BaseRemoteProvider} dan turunannya (PSrE adapter:
 * Peruri, Privy, VIDA, BSrE, Digisign, dll).
 *
 * Sengaja dibuat kecil supaya swappable:
 *   - Default impl: {@see CurlHttpClient} (curl langsung, no dependencies).
 *   - Consumer boleh inject impl lain (Guzzle wrapper, mock in tests, dsb).
 *
 * ## Options schema
 *
 * Argument `$options` untuk {@see request()} adalah associative array
 * dengan key opsional berikut:
 *
 *   - `headers`  (array<string,string>)  header key => value; boleh kosong
 *   - `body`     (string|array)          request body; array + `json`=true
 *                                        auto-encode via json_encode
 *   - `json`     (bool)                  kalau true, `body` di-JSON-encode
 *                                        dan header Content-Type di-set
 *                                        `application/json` (default false)
 *   - `timeout`  (int)                   detik; default 30
 *   - `query`    (array<string,mixed>)   optional; append ke URL sebagai
 *                                        query string (opsional; impl
 *                                        default handle ini)
 *
 * ## Error semantics
 *
 * Implementasi WAJIB throw {@see \Ezdoc\Exceptions\EzdocException} pada
 * kegagalan network / DNS / timeout / TLS handshake. HTTP status 4xx/5xx
 * BUKAN kegagalan network — return {@see HttpResponse} apa adanya, biar
 * caller yang decide (mis. mapping ke AuthError/ValidationError di
 * BaseRemoteProvider::requireSuccess()).
 *
 * PHP 7.4+ compatible.
 */
interface HttpClient
{
    /**
     * Kirim HTTP request dan return response.
     *
     * @param string              $method  verb HTTP (GET/POST/PUT/DELETE/PATCH)
     * @param string              $url     absolute URL (schema://host/path)
     * @param array<string,mixed> $options lihat schema di class doc
     * @return HttpResponse
     * @throws \Ezdoc\Exceptions\EzdocException     network / DNS / timeout / TLS
     * @throws \Ezdoc\Exceptions\ValidationException option invalid
     */
    public function request(string $method, string $url, array $options = []): HttpResponse;
}
