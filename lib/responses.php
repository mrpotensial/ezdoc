<?php
/**
 * ezdoc responses — standardize JSON output untuk AJAX endpoints.
 *
 * Format:
 *   Success: { success: true, message: "...", data: {...} }
 *   Error:   { success: false, message: "..." } + extra fields
 *
 * Semua fungsi di sini exit setelah output — designed untuk endpoint handler
 * yang short-circuit request lifecycle.
 */

if (defined('EZDOC_RESPONSES_LOADED')) return;
define('EZDOC_RESPONSES_LOADED', true);

/**
 * Respond success JSON + exit.
 *
 * @param array<string,mixed> $data   Payload data (opsional)
 * @param string              $message Human-readable message
 * @return never
 */
function ezdoc_respond_success(array $data = [], string $message = ''): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    // Merge data ke top-level supaya backward-compat dengan frontend existing
    // (yang sometimes akses data.verify_url, sometimes response.verify_url)
    $payload = ['success' => true];
    if ($message !== '') $payload['message'] = $message;
    if (!empty($data)) $payload += $data;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Respond error JSON + exit.
 *
 * @param string             $message Error message untuk user
 * @param int                $code    HTTP status code (default 400)
 * @param array<string,mixed> $extra   Additional fields (spread ke response)
 * @return never
 */
function ezdoc_respond_error(string $message, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = ['success' => false, 'message' => $message] + $extra;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Respond raw JSON + exit. Untuk kasus khusus yang butuh full control payload
 * (mis. legacy format tanpa `success` wrapper).
 *
 * @param array<string,mixed> $payload
 * @param int $code
 * @return never
 */
function ezdoc_respond_raw(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
