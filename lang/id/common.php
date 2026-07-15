<?php

/**
 * ezdoc i18n — shared strings used by BOTH designer.php and generate.php.
 *
 * Keys are English identifiers (locale-neutral, stable across locales) —
 * only the VALUES here are Indonesian. This matches how t() call sites
 * pass their `$default` fallback in English too (see docs/I18N.md) — the
 * key/default pair is the "source string" a developer reads in code, the
 * catalog value is what actually renders for this locale.
 *
 * Top-level sections here are RESERVED: view-specific catalogs
 * (lang/{locale}/designer.php, lang/{locale}/generate.php) must NOT
 * redeclare `actions` or `fallback` as top-level keys, since
 * Ezdoc\UI\Config::merge() deep-merges associative arrays — a view file
 * re-declaring `actions` would blend with (not replace) this file's keys,
 * which is usually not what you want when adding a NEW view-only action.
 *
 * spec: docs/I18N.md
 */

return [
    'actions' => [
        'save'   => 'Simpan',
        'cancel' => 'Batal',
        'delete' => 'Hapus',
        'close'  => 'Tutup',
        'ok'     => 'OK',
        'edit'   => 'Edit',
    ],
    'fallback' => [
        // Default TTD (tanda tangan / signature) label shown when no
        // data-label is set on a signature placeholder. Was duplicated
        // ~10x verbatim across designer.php + generate.php before this
        // migration.
        'signature' => 'Tanda Tangan',
    ],
];
