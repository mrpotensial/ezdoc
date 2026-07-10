<?php
/**
 * ezdoc doc_template_helpers — pure functions untuk template placeholder
 * resolution & conditional section logic. Extracted from inline blocks di
 * `page/form_pembuat_surat_cetak_v3.php` (v0.6.5 refactor).
 *
 * No DB, no state (except `global $whitelistedVars` yang dipopulasi caller
 * lewat ezdoc_load_whitelisted_vars() sebelum resolveDefault dipanggil).
 * No side effects. Safe to load early.
 *
 * Dependencies:
 *   - `ubahTanggalKeIndonesia()` — defined in `pengeluaran/koneksi.php`
 *     (loaded ahead of this file oleh main entry).
 */

if (defined('EZDOC_DOC_TEMPLATE_HELPERS_LOADED')) return;
define('EZDOC_DOC_TEMPLATE_HELPERS_LOADED', true);

if (!function_exists('resolveDefault')) {
    /**
     * Resolve template default value string ke actual value.
     * Format:
     *   - "date:FORMAT"  → ubahTanggalKeIndonesia(date(FORMAT))
     *   - "$varname"     → nilai global $varname (harus ada di $whitelistedVars)
     *   - lainnya        → literal string
     *
     * @param string|null $default
     * @return string
     */
    function resolveDefault($default)
    {
        global $whitelistedVars;
        if (!$default || $default === '') return '';

        // Date format: date:FORMAT
        if (strncmp($default, 'date:', 5) === 0) {
            $format = substr($default, 5);
            return ubahTanggalKeIndonesia(date($format));
        }

        // PHP variable: $varname (must be in whitelist)
        if (isset($default[0]) && $default[0] === '$') {
            $varName = substr($default, 1);
            if (is_array($whitelistedVars) && in_array($varName, $whitelistedVars, true)) {
                global $$varName;
                return $$varName ?? '';
            }
            return '';
        }

        // Plain text
        return $default;
    }
}

if (!function_exists('evalSingleCondPHP')) {
    /**
     * Evaluate a single conditional operator ("field OP value").
     * Ops: >=, <=, !=, =, >, <
     * Kalau kedua sisi numeric → numeric compare, else string compare.
     *
     * @param string $cond   e.g. "umur>=17"
     * @param array  $values field map
     * @return bool
     */
    function evalSingleCondPHP($cond, $values)
    {
        $ops = ['>=', '<=', '!=', '=', '>', '<'];
        $op = '';
        $leftRaw = '';
        $rightRaw = '';
        foreach ($ops as $o) {
            $idx = strpos($cond, $o);
            if ($idx !== false && $idx > 0) {
                $op = $o;
                $leftRaw = trim(substr($cond, 0, $idx));
                $rightRaw = trim(substr($cond, $idx + strlen($o)));
                break;
            }
        }
        if ($op === '') return false;

        $leftVal = (string)($values[$leftRaw] ?? '');
        $numeric = is_numeric($leftVal) && is_numeric($rightRaw);
        if ($numeric) {
            $l = (float)$leftVal; $r = (float)$rightRaw;
            switch ($op) {
                case '=':  return $l === $r;
                case '!=': return $l !== $r;
                case '>':  return $l >  $r;
                case '<':  return $l <  $r;
                case '>=': return $l >= $r;
                case '<=': return $l <= $r;
            }
        }
        switch ($op) {
            case '=':  return $leftVal === $rightRaw;
            case '!=': return $leftVal !== $rightRaw;
            case '>':  return $leftVal >  $rightRaw;
            case '<':  return $leftVal <  $rightRaw;
            case '>=': return $leftVal >= $rightRaw;
            case '<=': return $leftVal <= $rightRaw;
        }
        return false;
    }
}

if (!function_exists('evalCondExprPHP')) {
    /**
     * Evaluate compound conditional expression with AND/OR (case-insensitive).
     * OR has lower precedence than AND — no parens supported.
     *
     * Contoh:
     *   "jenis_kelamin=P AND umur>=17"
     *   "status!=lajang OR has_anak=1"
     *
     * @param string $expr
     * @param array  $values
     * @return bool
     */
    function evalCondExprPHP($expr, $values)
    {
        if (empty($expr)) return true;
        $orParts = preg_split('/\s+OR\s+/i', $expr);
        foreach ($orParts as $orPart) {
            $andParts = preg_split('/\s+AND\s+/i', $orPart);
            $andOk = true;
            foreach ($andParts as $cond) {
                if (!evalSingleCondPHP(trim($cond), $values)) { $andOk = false; break; }
            }
            if ($andOk) return true;
        }
        return false;
    }
}

if (!function_exists('processConditionalSections')) {
    /**
     * Strip / keep `<div class="conditional-section" data-cond="...">` blocks
     * berdasarkan evalCondExprPHP.
     *
     * Untuk PDF: block dihapus kalau cond false. Untuk HTML preview: handled
     * by JS at runtime (function ini tidak dipanggil di preview path).
     *
     * @param string $html
     * @param array  $dbFields
     * @return string
     */
    function processConditionalSections($html, $dbFields)
    {
        return preg_replace_callback('/<div([^>]*class="[^"]*conditional-section[^"]*"[^>]*)>([\s\S]*?)<\/div>/i', function ($m) use ($dbFields) {
            $tag = $m[1];
            $inner = $m[2];
            if (preg_match('/data-cond="([^"]*)"/', $tag, $cm)) {
                $expr = html_entity_decode($cm[1], ENT_QUOTES, 'UTF-8');
                $ok = evalCondExprPHP($expr, $dbFields);
                return $ok ? $inner : '';
            }
            return $m[0];
        }, $html);
    }
}
