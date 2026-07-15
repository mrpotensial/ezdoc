<?php

/**
 * ezdoc i18n — shared strings used by designer.php, generate.php, AND
 * actions/**\/*.php (AJAX endpoint responses, via actions/_dispatcher.php).
 *
 * Keys are English identifiers (locale-neutral, stable across locales) —
 * only the VALUES here are Indonesian. This matches how t() call sites
 * pass their `$default` fallback in English too (see docs/I18N.md) — the
 * key/default pair is the "source string" a developer reads in code, the
 * catalog value is what actually renders for this locale.
 *
 * Top-level sections here are RESERVED: view-specific catalogs
 * (lang/{locale}/designer.php, lang/{locale}/generate.php) must NOT
 * redeclare `actions`, `fallback`, or `response` as top-level keys, since
 * Ezdoc\UI\Config::merge() deep-merges associative arrays — a view file
 * re-declaring `actions` would blend with (not replace) this file's keys,
 * which is usually not what you want when adding a NEW view-only action.
 *
 * `response.*` deliberately lives HERE (not in a per-view catalog) because
 * actions/*.php can run under whichever Translator instance happened to be
 * built by the including view (designer's, generate's, or a standalone
 * 'actions' one from actions/_dispatcher.php's own fallback) — putting
 * these keys in common.php means they resolve correctly regardless of
 * which Translator instance is currently active, since EVERY
 * Translator::forView() call merges common.php first.
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
    'response' => [
        // AJAX endpoint response messages (actions/**/*.php) — populated
        // incrementally as call sites are converted. See comment above.

        // ── generic / shared across multiple action files ──
        'invalid_id'             => 'ID tidak valid',
        'invalid_doc_id'         => 'ID dokumen tidak valid',
        'invalid_template_id'   => 'ID template tidak valid',
        'invalid_parameters'     => 'Parameter tidak valid',
        'incomplete_parameters'  => 'Parameter tidak lengkap',
        'template_not_found'     => 'Template tidak ditemukan',
        'document_not_found'     => 'Dokumen tidak ditemukan',
        'delete_failed'          => 'Gagal hapus: {error}',
        'update_failed'          => 'Gagal update: {error}',

        // ── default_vars/add_var.php ──
        'var_name_required'      => 'Nama variabel wajib diisi',
        'var_name_invalid_chars' => 'Nama variabel harus alphanumeric atau underscore',
        'add_var_failed'         => 'Gagal menambahkan variabel: {error}',
        'var_added'              => 'Variabel ditambahkan',

        // ── default_vars/delete_var.php ──
        'delete_var_failed'      => 'Gagal menghapus variabel: {error}',
        'var_deleted'            => 'Variabel dihapus',

        // ── document/delete_slot.php ──
        'slot_has_locked_versions' => 'Slot punya {count} versi locked. Unlock dulu sebelum hapus.',
        'delete_slot_failed'       => 'Gagal hapus slot: {error}',
        'slot_deleted'             => 'Slot berhasil dihapus ({count} versi)',

        // ── document/delete_version.php ──
        'version_locked_cannot_delete' => 'Versi locked tidak bisa dihapus. Unlock dulu.',
        'last_version_in_slot'         => 'Ini versi terakhir di slot. Pakai "Hapus Slot" untuk hapus seluruh slot.',
        'version_deleted'              => 'Versi berhasil dihapus (soft delete)',

        // ── document/generate_qr.php ──
        'qr_data_empty'          => 'Data QR kosong',
        'qr_generator_unavailable' => 'QR generator tidak tersedia (generateQrForDompdf missing)',
        'qr_generate_failed'     => 'Gagal generate QR: {error}',

        // ── document/new_version.php ──
        'version_created'        => 'Versi baru v{version} berhasil dibuat',

        // ── document/restore_slot.php ──
        'restore_failed'         => 'Gagal restore: {error}',
        'slot_restored'          => 'Slot berhasil di-restore ({count} versi)',

        // ── document/save_document.php ──
        'patient_identity_required'   => 'No RM dan No Pendaftaran wajib diisi',
        'ttd_sign_forbidden'          => 'Tidak berhak menandatangani sebagai "{label}"',
        'document_locked_cannot_edit' => 'Dokumen ini locked dan tidak bisa diedit. Unlock dulu atau buat versi baru.',
        'save_failed'                 => 'Gagal: {error}',
        'document_saved'              => 'Dokumen berhasil disimpan',

        // ── document/toggle_lock.php ──
        'document_locked'        => 'Dokumen dilock',
        'document_unlocked'      => 'Dokumen diunlock',
        'update_lock_failed'     => 'Gagal update lock: {error}',

        // ── template/analyze_query.php ──
        'query_empty'             => 'Query kosong',
        'only_select_allowed'     => 'Hanya query SELECT yang diizinkan (terdeteksi: {keyword})',
        'query_must_start_select' => 'Query harus diawali SELECT atau WITH',
        'query_error'             => 'Query error: {error}',

        // ── template/cleanup_orphans.php ──
        'cleanup_failed'          => 'Cleanup gagal (rollback): {error}',
        'orphans_cleaned'         => '{updated} dokumen dibersihkan, {removed} field orphan dihapus',

        // ── template/rename_field.php ──
        'new_name_invalid_chars'  => 'Nama baru harus alphanumeric, underscore, atau hyphen',
        'rename_failed'           => 'Rename gagal (rollback): {error}',
        'field_renamed'           => '{updated} dokumen di-update',
        'field_renamed_with_skips' => "{updated} dokumen di-update ({skipped} skip karena '{new_name}' sudah ada value)",

        // ── template/save_template.php ──
        'template_name_required'  => 'Nama template wajib diisi',
        'save_template_failed'    => 'Gagal menyimpan: {error}',
        'template_saved'          => 'Template berhasil disimpan',
    ],
];
