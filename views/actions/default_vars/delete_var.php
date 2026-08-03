<?php
/**
 * POST action=delete_var (body: var_id)
 *
 * Delete default variable dari `ezdoc_default_vars` by ID.
 * Auth: template manager only + audit log.
 *
 * Response: { success: true }
 *
 * ## v0.9.9 refactor
 *
 * Thin controller — persistence via `Ezdoc\DefaultVars\DefaultVarsRepository`.
 */

use Ezdoc\Context;

ezdoc_require_manage_templates('Tidak berhak menghapus default variable');

$varId = (int)($_POST['var_id'] ?? 0);
if ($varId <= 0) {
    ezdoc_respond_error(t('response.invalid_id', [], 'Invalid ID'));
}

$repo = new \Ezdoc\DefaultVars\DefaultVarsRepository(Context::default()->db);

// Fetch nama untuk audit log (best-effort — supaya audit context lengkap)
$existing = $repo->findById($varId);
$varName = $existing['var_name'] ?? null;

try {
    $repo->delete($varId);
} catch (\Throwable $e) {
    ezdoc_respond_error(t('response.delete_var_failed', ['error' => $e->getMessage()], 'Failed to delete variable: {error}'));
}

if ($varName) {
    ezdoc_audit_log('default_var.deleted', [
        'target_type' => 'default_var',
        'target_id'   => $varName,
        'metadata'    => ['var_id' => $varId, 'var_name' => $varName],
        'message'     => "Hapus default var '{$varName}'",
    ]);
}

ezdoc_respond_success([], t('response.var_deleted', [], 'Variable deleted'));
