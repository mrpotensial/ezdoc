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
 * HTML escape helper — koneksi.php-safe wrapper.
 * Guarded dengan function_exists karena file lain juga define h_list().
 */
if (!function_exists('h_list')) {
    function h_list($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Format relative time (Indonesian short form): "5d lalu", "3m lalu", "2j lalu",
 * "4h lalu", atau tanggal "d/m/y" kalau > 1 minggu.
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

        $diff = time() - $ts;
        if ($diff < 60)     return $diff . 'd lalu';
        if ($diff < 3600)   return floor($diff / 60) . 'm lalu';
        if ($diff < 86400)  return floor($diff / 3600) . 'j lalu';
        if ($diff < 604800) return floor($diff / 86400) . 'h lalu';
        return date('d/m/y', $ts);
    }
}

/**
 * Build cetak URL query params dari document row.
 * Skip label param kalau label=='-' (kosong).
 *
 * @param array $row Document row dengan template_id, norm, nopen, label
 * @return string URL-encoded query params (tanpa leading ?)
 */
if (!function_exists('ezdoc_doc_link_params')) {
    function ezdoc_doc_link_params(array $row): string
    {
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
