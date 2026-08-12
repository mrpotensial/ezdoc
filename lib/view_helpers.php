<?php

/**
 * Dua pembantu terpendek yang dipakai hampir setiap berkas tampilan.
 *
 * Keduanya sebelumnya dideklarasikan di dalam `views/document/generate.php`,
 * dengan penjaga `function_exists`. Itu bekerja selama hanya berkas itu yang
 * membutuhkannya — dan berhenti bekerja begitu perakitan PDF dipindah keluar
 * (lihat docs/260811-beku-pdf-tiap-tanda-tangan.md).
 *
 * Dipindah ke sini apa adanya, tanpa satu huruf pun berubah. Penjaga
 * `function_exists` di tempat lamanya jadi tidak pernah menyala, dan itu memang
 * yang diinginkan: satu definisi, bukan dua yang kebetulan sama hari ini.
 *
 * PHP 7.4+ compatible.
 */

// Local helper — escape output (keeps existing h() call sites working)
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Local helper — translate a dot-notation i18n key (see lang/id/*.php).
// $default preserves the original Indonesian copy so a missing/mistyped key
// still displays correct text instead of a raw key or blank string.
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $default = null): string {
        $translator = $GLOBALS['translator'] ?? null;
        if (!($translator instanceof \Ezdoc\UI\Translator)) {
            return $default !== null ? $default : $key;
        }
        return $translator->t($key, $params, $default);
    }
}
