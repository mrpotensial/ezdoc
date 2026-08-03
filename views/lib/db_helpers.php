<?php
/**
 * ezdoc DB helpers — portable query wrappers.
 *
 * Purpose: provide industry-standard prepared-statement API sambil tetap
 * backward-compatible dengan legacy `query()` global function (yg mungkin
 * di-define consumer di bootstrap file).
 *
 * Preference order (best → worst):
 *   1. Repository classes (Ezdoc\Document\DocumentRepository, dll) — v0.4+
 *   2. ezdoc_query_prepared($sql, $params, $types) — prepared statement wrapper
 *   3. ezdoc_query() — legacy query() fallback, backward compat only
 *
 * Consumer library user harus preferensi #1 (Repository), fallback #2 kalau
 * butuh raw SQL. Legacy `query()` (#3) TIDAK direkomendasi untuk code baru
 * — vulnerable to injection kalau input tidak di-escape properly.
 *
 * PHP 7.4+ compatible.
 */

if (defined('EZDOC_DB_HELPERS_LOADED')) return;
define('EZDOC_DB_HELPERS_LOADED', true);

/**
 * Portable query wrapper — pakai prepared statement kalau params disediakan,
 * fallback ke raw query kalau tidak (backward compat dengan legacy `query()`).
 *
 * @param string $sql  SQL query. Pakai `?` placeholder kalau ada params.
 * @param array $params  Parameter values untuk bind (positional).
 * @param string $types  Type string untuk mysqli_stmt_bind_param
 *                       (default auto-detect: string, int, float, blob).
 *                       Format: 's' string, 'i' int, 'd' double, 'b' blob.
 * @return array<array<string,mixed>>  Result rows sebagai assoc arrays. Empty on error.
 *
 * @example
 *   $rows = ezdoc_query_prepared("SELECT * FROM ezdoc_documents WHERE id = ?", [42]);
 *   $rows = ezdoc_query_prepared(
 *       "SELECT * FROM ezdoc_documents WHERE norm = ? AND nopen = ?",
 *       [$norm, $nopen], 'ss'
 *   );
 */
if (!function_exists('ezdoc_query_prepared')) {
    function ezdoc_query_prepared(string $sql, array $params = [], string $types = ''): array
    {
        $conn = ezdoc_get_db_connection();
        if ($conn === null) return [];

        // No params → raw query (fallback backward compat)
        if (empty($params)) {
            $result = @mysqli_query($conn, $sql);
            if (!$result) return [];
            $rows = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
            mysqli_free_result($result);
            return $rows;
        }

        // Auto-detect types kalau tidak disediakan
        if ($types === '') {
            $types = '';
            foreach ($params as $p) {
                if (is_int($p))         $types .= 'i';
                elseif (is_float($p))   $types .= 'd';
                elseif ($p === null)    $types .= 's';  // bind NULL as string
                else                    $types .= 's';
            }
        }

        // Sanity check: types length must match params count
        if (strlen($types) !== count($params)) {
            @error_log('[ezdoc_query_prepared] types length mismatch: ' . $sql);
            return [];
        }

        $stmt = @mysqli_prepare($conn, $sql);
        if (!$stmt) {
            @error_log('[ezdoc_query_prepared] prepare failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
            return [];
        }

        // bind_param requires refs — build refs array
        $bindRefs = [$types];
        foreach ($params as $k => $v) {
            $bindRefs[] = &$params[$k];
        }
        @call_user_func_array([$stmt, 'bind_param'], $bindRefs);

        if (!@mysqli_stmt_execute($stmt)) {
            @error_log('[ezdoc_query_prepared] execute failed: ' . mysqli_stmt_error($stmt));
            @mysqli_stmt_close($stmt);
            return [];
        }

        $result = @mysqli_stmt_get_result($stmt);
        $rows = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
            mysqli_free_result($result);
        }
        @mysqli_stmt_close($stmt);

        return $rows;
    }
}

/**
 * Legacy-compatible query wrapper. Deteksi:
 *   1. Kalau `query()` global function ada (consumer bootstrap defined it) → gunakan itu (backward compat)
 *   2. Fallback → wraps mysqli_query() dengan Context connection
 *
 * DEPRECATED for new code — pakai ezdoc_query_prepared() atau Repository class.
 *
 * @return array<array<string,mixed>>|false  Rows or false on error
 *
 * @example (backward compat)
 *   $rows = ezdoc_query("SELECT * FROM ezdoc_templates WHERE is_current = 1");
 */
if (!function_exists('ezdoc_query')) {
    function ezdoc_query(string $sql)
    {
        // Priority 1: use legacy query() kalau tersedia
        if (function_exists('query')) {
            return query($sql);
        }

        // Priority 2: fallback native mysqli
        $conn = ezdoc_get_db_connection();
        if ($conn === null) return false;

        $result = @mysqli_query($conn, $sql);
        if (!$result) {
            @error_log('[ezdoc_query] failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
            return false;
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);
        return $rows;
    }
}

/**
 * Get DB connection dari Context (kalau available) atau $GLOBALS['conn'].
 * Return null kalau tidak ada connection.
 *
 * @return \mysqli|null
 */
if (!function_exists('ezdoc_get_db_connection')) {
    function ezdoc_get_db_connection()
    {
        // Priority 1: Ezdoc Context (v0.3+ library-ready)
        if (class_exists('\\Ezdoc\\Context', false)) {
            try {
                $ctx = \Ezdoc\Context::default();
                if ($ctx->db instanceof \mysqli) return $ctx->db;
            } catch (\Throwable $e) {
                // Fall through to legacy check
            }
        }

        // Priority 2: legacy consumer bootstrap $conn global
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof \mysqli) {
            return $GLOBALS['conn'];
        }

        return null;
    }
}

/**
 * SQL identifier escape helper — untuk table/column names yang tidak bisa
 * di-parameterize via bind_param. Whitelist [a-zA-Z0-9_].
 * Throw ValidationException kalau invalid.
 *
 * @throws \Ezdoc\Exceptions\ValidationException
 */
if (!function_exists('ezdoc_escape_identifier')) {
    function ezdoc_escape_identifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            if (class_exists('\\Ezdoc\\Exceptions\\ValidationException', false)) {
                throw \Ezdoc\Exceptions\ValidationException::forField(
                    'identifier',
                    "Invalid SQL identifier: '{$identifier}' (alphanumeric + underscore only)"
                );
            }
            throw new \InvalidArgumentException("Invalid SQL identifier: '{$identifier}'");
        }
        return "`{$identifier}`";
    }
}
