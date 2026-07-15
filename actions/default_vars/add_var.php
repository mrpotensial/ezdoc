<?php
/**
 * POST action=add_var (body: var_name, description)
 *
 * Add new default variable ke `ezdoc_default_vars`. Duplicate var_name
 * → silent skip (idempotent).
 *
 * Sanitization: var_name harus alphanumeric + underscore only.
 * Auth: template manager only + audit log.
 *
 * Response: { success: true|false, message: "...", data: { var_name } }
 *
 * ## v0.9.9 refactor
 *
 * Thin controller — persistence via `Ezdoc\DefaultVars\DefaultVarsRepository`
 * (auto-wrap raw mysqli). Raw `mysqli_prepare` calls removed.
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak menambahkan default variable');

$varName = trim($_POST['var_name'] ?? '');
$varDesc = trim($_POST['description'] ?? '');

if ($varName === '') {
    ezdoc_respond_error(t('response.var_name_required', [], 'Variable name is required'));
}

// Sanitize: alphanumeric + underscore only
$varNameClean = preg_replace('/[^a-zA-Z0-9_]/', '', $varName);
if ($varNameClean === '') {
    ezdoc_respond_error(t('response.var_name_invalid_chars', [], 'Variable name must be alphanumeric or underscore'));
}

try {
    $repo = new \Ezdoc\DefaultVars\DefaultVarsRepository($conn);
    $insertedId = $repo->add($varNameClean, $varDesc !== '' ? $varDesc : null);
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.add_var_failed', ['error' => $e->getMessage()], 'Failed to add variable: {error}'));
}

// Audit — hanya kalau memang inserted (skip audit kalau duplicate silent)
if ($insertedId > 0) {
    ezdoc_audit_log('default_var.added', [
        'target_type' => 'default_var',
        'target_id'   => $varNameClean,
        'metadata'    => [
            'var_name'    => $varNameClean,
            'description' => $varDesc,
        ],
        'message' => "Tambah default var '{$varNameClean}'",
    ]);
}

ezdoc_respond_success([
    'var_name' => $varNameClean,
], t('response.var_added', [], 'Variable added'));
