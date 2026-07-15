<?php

/**
 * ezdoc i18n — shared strings used by BOTH designer.php and generate.php.
 *
 * English locale. Keys mirror lang/id/common.php exactly (same identifiers,
 * this is the source-language catalog per docs/I18N.md's convention).
 *
 * Top-level sections here are RESERVED: view-specific catalogs
 * (lang/{locale}/designer.php, lang/{locale}/generate.php) must NOT
 * redeclare `actions` or `fallback` as top-level keys.
 *
 * spec: docs/I18N.md
 */

return [
    'actions' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'close' => 'Close',
        'ok' => 'OK',
        'edit' => 'Edit',
    ],
    'fallback' => [
        'signature' => 'Signature',
    ],
];
