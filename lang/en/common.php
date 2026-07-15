<?php

/**
 * ezdoc i18n — shared strings used by BOTH designer.php and generate.php.
 *
 * English locale. Keys mirror lang/id/common.php exactly (same identifiers,
 * this is the source-language catalog per docs/I18N.md's convention).
 * Shared by designer.php, generate.php, AND actions/**\/*.php (response.*).
 *
 * Top-level sections here are RESERVED: view-specific catalogs
 * (lang/{locale}/designer.php, lang/{locale}/generate.php) must NOT
 * redeclare `actions`, `fallback`, or `response` as top-level keys.
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
    'response' => [
        'invalid_id' => 'Invalid ID',
        'invalid_doc_id' => 'Invalid document ID',
        'invalid_template_id' => 'Invalid template ID',
        'invalid_parameters' => 'Invalid parameters',
        'incomplete_parameters' => 'Incomplete parameters',
        'template_not_found' => 'Template not found',
        'document_not_found' => 'Document not found',
        'delete_failed' => 'Failed to delete: {error}',
        'update_failed' => 'Failed to update: {error}',
        'var_name_required' => 'Variable name is required',
        'var_name_invalid_chars' => 'Variable name must be alphanumeric or underscore',
        'add_var_failed' => 'Failed to add variable: {error}',
        'var_added' => 'Variable added',
        'delete_var_failed' => 'Failed to delete variable: {error}',
        'var_deleted' => 'Variable deleted',
        'slot_has_locked_versions' => 'Slot has {count} locked version(s). Unlock them first before deleting.',
        'delete_slot_failed' => 'Failed to delete slot: {error}',
        'slot_deleted' => 'Slot deleted successfully ({count} version(s))',
        'version_locked_cannot_delete' => 'Locked version cannot be deleted. Unlock it first.',
        'last_version_in_slot' => 'This is the last version in the slot. Use "Delete Slot" to remove the entire slot.',
        'version_deleted' => 'Version deleted successfully (soft delete)',
        'qr_data_empty' => 'QR data is empty',
        'qr_generator_unavailable' => 'QR generator unavailable (generateQrForDompdf missing)',
        'qr_generate_failed' => 'Failed to generate QR: {error}',
        'version_created' => 'New version v{version} created successfully',
        'restore_failed' => 'Failed to restore: {error}',
        'slot_restored' => 'Slot restored successfully ({count} version(s))',
        'patient_identity_required' => 'Medical record number and registration number are required',
        'ttd_sign_forbidden' => 'Not authorized to sign as "{label}"',
        'document_locked_cannot_edit' => 'This document is locked and cannot be edited. Unlock it first or create a new version.',
        'save_failed' => 'Failed: {error}',
        'document_saved' => 'Document saved successfully',
        'document_locked' => 'Document locked',
        'document_unlocked' => 'Document unlocked',
        'update_lock_failed' => 'Failed to update lock: {error}',
        'query_empty' => 'Query is empty',
        'only_select_allowed' => 'Only SELECT queries are allowed (detected: {keyword})',
        'query_must_start_select' => 'Query must start with SELECT or WITH',
        'query_error' => 'Query error: {error}',
        'cleanup_failed' => 'Cleanup failed (rolled back): {error}',
        'orphans_cleaned' => '{updated} document(s) cleaned, {removed} orphan field(s) removed',
        'new_name_invalid_chars' => 'New name must be alphanumeric, underscore, or hyphen',
        'rename_failed' => 'Rename failed (rolled back): {error}',
        'field_renamed' => '{updated} document(s) updated',
        'field_renamed_with_skips' => '{updated} document(s) updated ({skipped} skipped because \'{new_name}\' already has a value)',
        'template_name_required' => 'Template name is required',
        'save_template_failed' => 'Failed to save: {error}',
        'template_saved' => 'Template saved successfully',
    ],
];
