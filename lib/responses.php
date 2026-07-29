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
 * Turn an uncaught fatal into JSON, so an action always answers in the format
 * its caller parses.
 *
 * Callers do `await resp.json()`. A fatal error produces an empty body, so the
 * browser reports "Unexpected end of JSON input" — which says nothing about
 * what actually broke and sends everyone looking in the wrong place. The real
 * error is in the server log, if whoever is debugging happens to have access.
 *
 * try/catch cannot cover this: E_ERROR, E_PARSE and friends unwind the process
 * without throwing. A shutdown function is the only hook that sees them, which
 * is how Laravel and Symfony both handle it.
 *
 * The message itself is logged always and returned to the client only when
 * `EZDOC_DEBUG` is on — a fatal message can name file paths and internals.
 *
 * Call this from an endpoint before doing any work. Registering it more than
 * once is harmless.
 */
function ezdoc_guard_fatal_as_json(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        // Only errors that actually end the process. Warnings and notices are
        // not failures and must not replace a response that was already sent.
        if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        $detail = $err['message'] . ' in ' . $err['file'] . ':' . $err['line'];
        @error_log('[ezdoc:fatal] ' . $detail);

        // The Router runs actions inside an output buffer. Discard it: whatever
        // partial output exists came from a request that did not finish, and
        // leaving it in front of the JSON makes the body unparseable too.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        $debug = defined('EZDOC_DEBUG') && EZDOC_DEBUG;
        echo json_encode([
            'success' => false,
            'message' => $debug
                ? $detail
                : 'The server hit a fatal error. Check the log for [ezdoc:fatal], '
                    . 'or define EZDOC_DEBUG to see it here.',
            'fatal'   => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
}

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
