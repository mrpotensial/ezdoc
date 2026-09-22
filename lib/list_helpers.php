<?php
/**
 * ezdoc list-page helpers — shared HTML escape, relative time formatter,
 * dan document link params builder. Dipakai oleh form_pembuat_surat_list_v3.php
 * dan calon list views lainnya.
 *
 * Bagian dari v0.6.5 UI extraction.
 */

if (defined('EZDOC_LIST_HELPERS_LOADED')) return;
define('EZDOC_LIST_HELPERS_LOADED', true);

/**
 * HTML escape helper — consumer-safe wrapper.
 * Guarded dengan function_exists karena file lain juga define h_list().
 */
if (!function_exists('h_list')) {
    function h_list($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Format relative time, dalam bahasa layar: "5s ago" / "5d lalu", "3m ago" /
 * "3m lalu", "2h ago" / "2j lalu", "4d ago" / "4h lalu", atau tanggal "d/m/y"
 * kalau > 1 minggu.
 *
 * Teksnya dari katalog bahasa (`relative_time.*`), bukan tertanam di sini:
 * "5d" berarti lima detik dalam singkatan Indonesia, dan lima HARI dalam
 * singkatan Inggris.
 *
 * @param string|null $datetimeStr MySQL datetime string
 * @return string
 */
if (!function_exists('ezdoc_relative_time')) {
    function ezdoc_relative_time(?string $datetimeStr): string
    {
        if ($datetimeStr === null || $datetimeStr === '') return '';
        $ts = strtotime($datetimeStr);
        if ($ts === false) return '';

        $tr = static function (string $kunci, int $n, string $bawaan): string {
            return function_exists('t')
                ? t('relative_time.' . $kunci, ['n' => $n], $bawaan)
                : str_replace('{n}', (string) $n, $bawaan);
        };

        $diff = time() - $ts;
        if ($diff < 60)     return $tr('seconds', $diff, '{n}s ago');
        if ($diff < 3600)   return $tr('minutes', (int) floor($diff / 60), '{n}m ago');
        if ($diff < 86400)  return $tr('hours', (int) floor($diff / 3600), '{n}h ago');
        if ($diff < 604800) return $tr('days', (int) floor($diff / 86400), '{n}d ago');
        return date('d/m/y', $ts);
    }
}

/**
 * Build cetak URL query params dari document row.
 * Skip label param kalau label=='-' (kosong).
 *
 * Defensive: accept any type (not just array). Non-array input → return
 * empty query string (safe fallback). Prevents Fatal TypeError kalau caller
 * pass malformed row from failed query() (which returns ['error' => msg]).
 *
 * @param mixed $row Document row (assoc array). Non-array → empty string.
 * @return string URL-encoded query params (tanpa leading ?), empty kalau invalid
 */
if (!function_exists('ezdoc_doc_link_params')) {
    function ezdoc_doc_link_params($row): string
    {
        if (!is_array($row)) {
            return ''; // safe fallback — caller can render broken link tapi tidak crash
        }

        $params = 'template_id=' . (int) ($row['template_id'] ?? 0)
                . '&norm=' . urlencode((string) ($row['norm'] ?? ''))
                . '&nopen=' . urlencode((string) ($row['nopen'] ?? ''));

        $label = (string) ($row['label'] ?? '');
        if ($label !== '' && $label !== '-') {
            $params .= '&label=' . urlencode($label);
        }
        return $params;
    }
}
