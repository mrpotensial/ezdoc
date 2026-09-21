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
        'restore' => 'Restore',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'close' => 'Close',
        'ok' => 'OK',
        'edit' => 'Edit',
        'hide' => 'Hide',
        'view_document' => 'View that document',
    ],
    'fallback' => [
        'signature' => 'Signature',
    ],
    'response' => [
        'hide_signed_needs_reason' => 'This version carries {count} signature(s). Give a reason of at least {min} characters before hiding it.',
        'version_not_hidden' => 'This version is not hidden — there is nothing to restore.',
        'version_restored' => 'Version v{version} is back in the list.',
        'version_hidden' => 'Version hidden from the list. Nothing was removed — it can be restored.',
        'version_locked_cannot_hide' => 'Locked version cannot be hidden. Unlock it first.',
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
        'last_version_in_slot' => 'This is the last version in the slot. Use "Delete Slot" to remove the entire slot.',
        'slot_version_conflict' => 'This document was created by another save a moment ago. Reload the page to continue from the existing version.',
        'qr_data_empty' => 'QR data is empty',
        'qr_generator_unavailable' => 'QR generator unavailable (generateQrForDompdf missing)',
        'qr_generate_failed' => 'Failed to generate QR: {error}',
        'version_created' => 'New version v{version} created successfully',
        'restore_failed' => 'Failed to restore: {error}',
        'slot_restored' => 'Slot restored successfully ({count} version(s))',
        'patient_identity_required' => 'Medical record number and registration number are required',
        'ttd_sign_forbidden' => 'Not authorized to sign as "{label}"',
        'document_locked_cannot_edit' => 'This document is locked and cannot be edited. Unlock it first or create a new version.',
        // Distinct from document_locked_cannot_edit: that one is an explicit
        // lock, this is a consequence of having been signed. Unsigned slots stay
        // fillable — it is the content that freezes.
        'document_signed_fields_frozen' => 'This document has been signed, so its content can no longer change. Create a new version to revise it. Changed: {fields}',
        'signature_replace_needs_new_version' => 'Signature "{ttd}" is already recorded and cannot be replaced by saving. Create a new version instead.',
        'signature_removal_needs_new_version' => 'Signature "{ttd}" is already recorded and cannot be removed by saving. Create a new version instead.',
        'save_failed' => 'Failed: {error}',
        'document_saved' => 'Document saved successfully',
        'document_unchanged' => 'No changes — nothing was saved',
        'archive_not_found' => 'No display archive for this document',
        'archive_file_missing' => 'The archive is recorded but its file cannot be read',
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

        // ── document/send_ttd_otp.php + verify_ttd_otp.php ──
        // Verification failures deliberately share one message so a caller
        // cannot tell "no such challenge" from "expired" from "wrong code".
        'otp_phone_required' => 'A recipient phone number is required.',
        'otp_not_required' => 'This signature slot does not require OTP verification.',
        'otp_slot_not_found' => 'Signature slot not found in the template.',
        'otp_send_failed' => 'Could not send the code. Please try again.',
        'otp_sent' => 'A code has been sent to {phone}.',
        'otp_invalid' => 'Incorrect or expired code.',
        'otp_verified' => 'Verified.',
        'otp_token_required' => 'OTP verification is required before signing "{label}".',
        'otp_not_configured' => 'OTP delivery is not configured for this application.',
        'otp_phone_override_denied' => 'The code can only be sent to the number on file.',

        // ── V6: document-bound code, requested via a QR on the operator's screen ──
        'scan_not_configured' => 'Scan-to-sign is not configured for this installation.',
        'scan_request_failed' => 'Could not create the code request. Please try again.',
    ],
];
