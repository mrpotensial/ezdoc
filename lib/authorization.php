<?php
/**
 * ezdoc authorization — RBAC + per-user permission helpers.
 *
 * Reuse consumer's `hasRole()` global (kalau ada) via role provider abstraction,
 * wrap dengan semantic yang lebih jelas dan tambah per-user check (untuk kasus
 * permission spesifik).
 *
 * Config format untuk `ezdoc_can()` / `ezdoc_require()`:
 *   [
 *     'roles' => ['dokter_dpjp', 'kepala_bidang'],  // list role name (OR logic)
 *     'users' => [42, 99],                          // list id_pegawai (OR logic)
 *   ]
 *
 * Result: allowed jika salah satu role match ATAU salah satu user_id match.
 */

if (defined('EZDOC_AUTHORIZATION_LOADED')) return;
define('EZDOC_AUTHORIZATION_LOADED', true);

/**
 * Cek apakah user sekarang punya role tertentu.
 * Route via role provider (default: consumer's global `hasRole()` if present, else HasRoleProvider).
 * @param string|array<string> $roles Single role atau list (OR logic).
 */
function ezdoc_has_role($roles): bool
{
    // Route via role provider — bisa di-override consumer lain via ezdoc_set_role_provider()
    if (!function_exists('ezdoc_resolve_role_provider')) return false;
    $provider = ezdoc_resolve_role_provider();
    return (bool)($provider['has_role'])($roles);
}

/**
 * Cek apakah user login sekarang adalah user tertentu (by id_pegawai).
 */
function ezdoc_is_user(int $userId): bool
{
    return ezdoc_current_user_id() === $userId;
}

/**
 * Get current user id — route via role provider.
 * Fallback 0 kalau tidak login atau provider tidak ada.
 */
function ezdoc_current_user_id(): int
{
    if (!function_exists('ezdoc_resolve_role_provider')) return 0;
    $provider = ezdoc_resolve_role_provider();
    return (int)($provider['current_user_id'])();
}

/**
 * Get current user's role array — route via role provider.
 * @return array<string>
 */
function ezdoc_current_user_roles(): array
{
    if (!function_exists('ezdoc_resolve_role_provider')) return [];
    $provider = ezdoc_resolve_role_provider();
    $roles = ($provider['current_user_roles'])();
    return is_array($roles) ? $roles : [];
}

/**
 * Cek apakah user boleh melakukan action based on config permission.
 * Match kalau salah satu role match ATAU user_id match.
 *
 * @param array{roles?: array<string>, users?: array<int>} $config
 */
function ezdoc_can(array $config): bool
{
    $roles = $config['roles'] ?? [];
    $users = $config['users'] ?? [];

    if (!empty($roles) && ezdoc_has_role($roles)) return true;

    if (!empty($users)) {
        $currentId = ezdoc_current_user_id();
        $userIds = array_map('intval', $users);
        if (in_array($currentId, $userIds, true)) return true;
    }

    return false;
}

/**
 * Guard: die dengan JSON 403 kalau user tidak punya role.
 * Untuk simple role-only check.
 *
 * @param string|array<string> $roles
 * @param string $message Custom error message
 * @return void (exits kalau tidak allowed)
 */
function ezdoc_require_role($roles, string $message = 'Tidak berhak melakukan action ini'): void
{
    if (!ezdoc_has_role($roles)) {
        $rolesStr = is_array($roles) ? implode(',', $roles) : (string)$roles;
        if (defined('EZDOC_ENFORCE_RBAC') && !EZDOC_ENFORCE_RBAC) {
            // Permissive mode: log tapi tidak block (untuk transisi ke enforcement)
            @error_log(sprintf(
                '[ezdoc] Permissive mode — user %d without role %s bypassed action',
                ezdoc_current_user_id(),
                $rolesStr
            ));
            if (function_exists('ezdoc_audit_log')) {
                ezdoc_audit_log('authz.bypassed', [
                    'result' => 'success',  // technically allowed
                    'metadata' => ['required_roles' => $rolesStr, 'mode' => 'permissive'],
                    'message' => "Permissive bypass — required roles: {$rolesStr}",
                ]);
            }
            return;
        }
        if (function_exists('ezdoc_audit_log')) {
            ezdoc_audit_log('authz.denied', [
                'result' => 'denied',
                'metadata' => ['required_roles' => $rolesStr],
                'message' => $message . " (required: {$rolesStr})",
            ]);
        }
        require_once __DIR__ . '/responses.php';
        ezdoc_respond_error($message, 403);
    }
}

/**
 * Guard: die dengan JSON 403 kalau user tidak match config permission.
 *
 * @param array{roles?: array<string>, users?: array<int>} $config
 * @param string $message
 * @return void (exits kalau tidak allowed)
 */
function ezdoc_require(array $config, string $message = 'Tidak berhak melakukan action ini'): void
{
    if (!ezdoc_can($config)) {
        if (defined('EZDOC_ENFORCE_RBAC') && !EZDOC_ENFORCE_RBAC) {
            @error_log(sprintf(
                '[ezdoc] Permissive mode — user %d bypassed action (config: %s)',
                ezdoc_current_user_id(),
                json_encode($config)
            ));
            if (function_exists('ezdoc_audit_log')) {
                ezdoc_audit_log('authz.bypassed', [
                    'result' => 'success',
                    'metadata' => ['config' => $config, 'mode' => 'permissive'],
                    'message' => "Permissive bypass",
                ]);
            }
            return;
        }
        if (function_exists('ezdoc_audit_log')) {
            ezdoc_audit_log('authz.denied', [
                'result' => 'denied',
                'metadata' => ['config' => $config],
                'message' => $message,
            ]);
        }
        require_once __DIR__ . '/responses.php';
        ezdoc_respond_error($message, 403);
    }
}

// ═══════════════════════════════════════════════════════════════
// Template-level RBAC (per-template access config)
// ═══════════════════════════════════════════════════════════════
//
// access_config format (di surat_template_v2.access_config JSON):
// {
//   "mode": "strict" | "permissive",     // default: strict
//   "create":  { "roles": [...], "users": [...] },   // siapa boleh create dokumen
//   "edit":    { "roles": [...], "users": [...] },   // siapa boleh edit dokumen
//   "lock":    { "roles": [...], "users": [...] },   // siapa boleh lock dokumen
//   "delete":  { "roles": [...], "users": [...] }    // siapa boleh delete (default: superadmin)
// }
//
// Backward compat: kalau access_config NULL / kosong → allow all (v2 behavior).
// Superadmin selalu bypass semua check (via ezdoc_has_role('superadmin')).

/**
 * Parse access_config JSON string dari DB.
 * @return array<string,mixed>|null null kalau kosong/invalid
 */
function ezdoc_parse_access_config(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Ambil mode enforcement dari access_config. Default: 'strict'.
 * @return string 'strict' | 'permissive'
 */
function ezdoc_access_mode(?array $accessConfig): string
{
    if (!$accessConfig) return 'strict';
    $mode = $accessConfig['mode'] ?? 'strict';
    return in_array($mode, ['strict', 'permissive'], true) ? $mode : 'strict';
}

/**
 * Cek apakah user boleh melakukan action `$action` pada template dengan access_config.
 * Backward-compat: kalau config kosong ATAU section action kosong → allow.
 * Superadmin selalu allowed.
 *
 * @param array<string,mixed>|null $accessConfig
 * @param string $action 'create' | 'edit' | 'lock' | 'delete'
 */
function ezdoc_can_on_template(?array $accessConfig, string $action): bool
{
    // Superadmin bypass semua
    if (ezdoc_has_role('superadmin')) return true;

    // Backward-compat: no config = allow all (v2 behavior)
    if (!$accessConfig) return true;

    $sectionConfig = $accessConfig[$action] ?? null;
    if (!is_array($sectionConfig)) return true;

    $roles = $sectionConfig['roles'] ?? [];
    $users = $sectionConfig['users'] ?? [];

    // Kalau section kosong (roles & users kedua-duanya empty) → allow (opt-out)
    if (empty($roles) && empty($users)) return true;

    return ezdoc_can(['roles' => $roles, 'users' => $users]);
}

/**
 * Guard: die dengan JSON 403 kalau user tidak boleh action pada template.
 * Kalau mode = permissive, log audit tapi tidak block.
 */
function ezdoc_require_on_template(?array $accessConfig, string $action, string $message = 'Tidak berhak'): void
{
    if (ezdoc_can_on_template($accessConfig, $action)) return;

    $mode = ezdoc_access_mode($accessConfig);
    if ($mode === 'permissive') {
        @error_log(sprintf(
            '[ezdoc] Permissive: user %d bypassed template %s (roles: %s)',
            ezdoc_current_user_id(),
            $action,
            implode(',', ezdoc_current_user_roles())
        ));
        if (function_exists('ezdoc_audit_log')) {
            ezdoc_audit_log('authz.bypassed', [
                'result' => 'success',
                'metadata' => ['action' => $action, 'config' => $accessConfig, 'mode' => 'permissive'],
                'message' => "Permissive bypass — template action: {$action}",
            ]);
        }
        return;
    }

    if (function_exists('ezdoc_audit_log')) {
        ezdoc_audit_log('authz.denied', [
            'result' => 'denied',
            'metadata' => ['action' => $action, 'config' => $accessConfig],
            'message' => "{$message} (action: {$action})",
        ]);
    }
    require_once __DIR__ . '/responses.php';
    ezdoc_respond_error($message, 403);
}

// ═══════════════════════════════════════════════════════════════
// TTD-level RBAC (per-signature-placeholder access)
// ═══════════════════════════════════════════════════════════════
//
// TTD placeholder HTML sekarang bisa punya:
//   data-allowed-roles="dokter_dpjp,perawat"
//   data-allowed-users="42,99"
//
// Parsing hasil: ['roles' => [...], 'users' => [...]]

/**
 * Parse TTD RBAC config dari data-allowed-roles + data-allowed-users attribute value.
 *
 * @param string $rolesRaw Comma-separated roles ("dokter_dpjp,perawat"). Empty = allow all.
 * @param string $usersRaw Comma-separated user IDs ("42,99"). Empty = allow all.
 * @return array{roles: array<string>, users: array<int>}
 */
function ezdoc_parse_ttd_config(string $rolesRaw, string $usersRaw): array
{
    $roles = array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));
    $users = array_values(array_filter(array_map('intval', explode(',', $usersRaw)), fn($u) => $u > 0));
    return ['roles' => $roles, 'users' => $users];
}

/**
 * Cek apakah user boleh sign TTD placeholder.
 * Kalau config kosong (no roles + no users) → allow (backward compat).
 * Superadmin selalu allowed.
 */
function ezdoc_can_sign_ttd(array $ttdConfig): bool
{
    if (ezdoc_has_role('superadmin')) return true;

    $roles = $ttdConfig['roles'] ?? [];
    $users = $ttdConfig['users'] ?? [];

    // Kalau kedua-duanya empty → allow (TTD open ke semua user)
    if (empty($roles) && empty($users)) return true;

    return ezdoc_can(['roles' => $roles, 'users' => $users]);
}

// ═══════════════════════════════════════════════════════════════
// Template Management RBAC (design-time)
// ═══════════════════════════════════════════════════════════════
//
// Beda dengan access_config per-template (runtime create/edit/lock/delete dokumen),
// ini adalah GLOBAL role check untuk siapa yang boleh MODIFY design template itu sendiri:
//   - Save template (edit design)
//   - Toggle lock template
//   - Copy template
//   - Delete template
//
// Config via EZDOC_TEMPLATE_MANAGER_ROLES constant (di ezdoc/config.php):
//   [] (empty)          → allow all (backward compat v2)
//   ['superadmin']      → superadmin only (recommended production)
//   ['superadmin', ...] → superadmin + additional roles

/**
 * Get list of roles yang boleh manage templates (dari config).
 * @return array<string>
 */
function ezdoc_template_manager_roles(): array
{
    if (!defined('EZDOC_TEMPLATE_MANAGER_ROLES')) return [];
    $raw = EZDOC_TEMPLATE_MANAGER_ROLES;
    if (is_array($raw)) return $raw;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

/**
 * Cek apakah user boleh manage templates (save/lock/copy/delete design-time).
 * Backward compat: kalau EZDOC_TEMPLATE_MANAGER_ROLES kosong → allow all.
 * Superadmin selalu boleh (implicit bypass).
 */
function ezdoc_can_manage_templates(): bool
{
    $roles = ezdoc_template_manager_roles();
    // Kosong = allow all (backward compat)
    if (empty($roles)) return true;
    // Ada roles di config → cek user match
    return ezdoc_has_role($roles);
}

/**
 * Guard: die dengan JSON 403 kalau user tidak boleh manage templates.
 */
function ezdoc_require_manage_templates(string $message = 'Tidak berhak modify template design'): void
{
    if (ezdoc_can_manage_templates()) return;
    if (defined('EZDOC_ENFORCE_RBAC') && !EZDOC_ENFORCE_RBAC) {
        @error_log(sprintf(
            '[ezdoc] Permissive: user %d bypassed template management (roles: %s)',
            ezdoc_current_user_id(),
            implode(',', ezdoc_current_user_roles())
        ));
        if (function_exists('ezdoc_audit_log')) {
            ezdoc_audit_log('authz.bypassed', [
                'result' => 'success',
                'metadata' => ['scope' => 'template_management', 'mode' => 'permissive'],
                'message' => "Permissive bypass — template management",
            ]);
        }
        return;
    }
    if (function_exists('ezdoc_audit_log')) {
        ezdoc_audit_log('authz.denied', [
            'result' => 'denied',
            'metadata' => ['scope' => 'template_management'],
            'message' => $message,
        ]);
    }
    require_once __DIR__ . '/responses.php';
    ezdoc_respond_error($message, 403);
}

// ═══════════════════════════════════════════════════════════════
// OOP adapter — Ezdoc\Access\AccessControl (v0.3+)
// ═══════════════════════════════════════════════════════════════
//
// Backward-compat wrapper: existing procedural functions di atas TETAP jalan.
// Function-function di bawah ini adalah adapter tipis ke OOP AccessControl.
// Kalau consumer library mau OOP-style, ambil via ezdoc_access_control().

/**
 * Get default AccessControl instance untuk global helpers.
 * Cache instance — bypass roles = ['superadmin'] (existing behavior).
 */
function ezdoc_access_control(): \Ezdoc\Access\AccessControl
{
    static $instance = null;
    if ($instance === null) {
        // Build RoleProvider adapter yang wrap fungsi procedural existing
        $provider = new \Ezdoc\Auth\CallableRoleProvider(
            function ($roles) { return ezdoc_has_role($roles); },
            function () { return ezdoc_current_user_id(); },
            function () { return ezdoc_current_user_roles(); }
        );
        $instance = new \Ezdoc\Access\AccessControl($provider, ['superadmin']);
    }
    return $instance;
}

/**
 * OOP-style: check via AccessConfig (JSON access_config di template).
 *
 * @param string|null $accessConfigJson JSON dari ezdoc_templates.access_config
 * @param string $action Nama action ('create', 'edit', 'lock', 'delete', dll)
 * @return \Ezdoc\Access\AccessDecision
 */
function ezdoc_check_access(?string $accessConfigJson, string $action): \Ezdoc\Access\AccessDecision
{
    $config = \Ezdoc\Access\AccessConfig::fromJson($accessConfigJson);
    return ezdoc_access_control()->can(ezdoc_current_user_id(), $action, $config);
}

