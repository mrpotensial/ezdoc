<?php
/**
 * ezdoc Role Provider — auth/RBAC abstraction, swappable per consumer app.
 *
 * ## Design (industry-standard DI pattern)
 *   - Default provider wraps consumer's own globals: `hasRole()`, `$author_id`,
 *     `$author_role_array` (backward-compat shim untuk consumer apps yang punya
 *     existing global-function auth pattern).
 *   - Consumer bisa override via `ezdoc_set_role_provider($custom)` untuk framework-
 *     specific auth (Laravel, Symfony, custom).
 *   - Semua helper (`ezdoc_has_role`, `ezdoc_current_user_id`,
 *     `ezdoc_current_user_roles`) route internally via provider.
 *
 * ## Contract
 * Provider = array dengan 3 callable:
 *   ```
 *   [
 *     'has_role'           => function ($rolesStringOrArray): bool { ... },
 *     'current_user_id'    => function (): int { ... },
 *     'current_user_roles' => function (): array { ... },
 *   ]
 *   ```
 *
 * ## Usage
 *
 * Consumer bootstrap (Laravel example):
 * ```php
 * require 'vendor/mrpotensial/ezdoc/bootstrap.php';
 * ezdoc_set_role_provider([
 *   'has_role'           => fn($r) => auth()->user()->hasRole($r),
 *   'current_user_id'    => fn() => auth()->id(),
 *   'current_user_roles' => fn() => auth()->user()->roles->pluck('name')->all(),
 * ]);
 * ```
 *
 * Legacy consumer (with global `hasRole()` function from their own bootstrap):
 * ```php
 * // No setup needed — default provider auto-detects consumer globals.
 * require 'vendor/mrpotensial/ezdoc/bootstrap.php';
 * ```
 */

if (defined('EZDOC_ROLE_PROVIDER_LOADED')) return;
define('EZDOC_ROLE_PROVIDER_LOADED', true);

/**
 * Default provider — wraps consumer's own `hasRole()` global function +
 * `$author_id` + `$author_role_array` globals (assumed set by consumer's own
 * bootstrap file). Backward-compat shim for legacy monolith consumers.
 *
 * Consumer apps using different auth mechanism (Laravel, Symfony, custom)
 * should call `ezdoc_set_role_provider()` in their bootstrap to override.
 *
 * @return array{has_role: callable, current_user_id: callable, current_user_roles: callable}
 */
function ezdoc_default_role_provider(): array
{
    return [
        'has_role' => function ($roles): bool {
            return function_exists('hasRole') ? hasRole($roles) : false;
        },
        'current_user_id' => function (): int {
            return (int)($GLOBALS['author_id'] ?? 0);
        },
        'current_user_roles' => function (): array {
            $roles = $GLOBALS['author_role_array'] ?? [];
            return is_array($roles) ? $roles : [];
        },
    ];
}

/**
 * Get current role provider (memoized).
 * @return array{has_role: callable, current_user_id: callable, current_user_roles: callable}
 */
function ezdoc_get_role_provider(): array
{
    static $provider = null;
    if ($provider === null) {
        $provider = ezdoc_default_role_provider();
    }
    return $provider;
}

/**
 * Override role provider — untuk consumer lain (mis. Laravel, WordPress).
 * Validate structure sebelum simpan.
 *
 * @param array<string,callable> $provider Must have keys: has_role, current_user_id, current_user_roles
 * @throws \InvalidArgumentException kalau provider tidak lengkap
 */
function ezdoc_set_role_provider(array $provider): void
{
    $required = ['has_role', 'current_user_id', 'current_user_roles'];
    foreach ($required as $key) {
        if (!isset($provider[$key]) || !is_callable($provider[$key])) {
            throw new \InvalidArgumentException("Role provider missing/invalid callable: {$key}");
        }
    }
    // Simpan sebagai static di helper via reset + re-memoize
    // Cara paling clean: pakai global variable dengan namespace unik
    $GLOBALS['__ezdoc_role_provider_override'] = $provider;
}

/**
 * Reset ke default (undo ezdoc_set_role_provider). Berguna untuk testing.
 */
function ezdoc_reset_role_provider(): void
{
    unset($GLOBALS['__ezdoc_role_provider_override']);
}

/**
 * Internal: resolve provider (override kalau ada, else default).
 * Dipanggil helper functions saat runtime — supaya override yang di-set setelah
 * initial load tetap ke-pick up.
 *
 * @return array{has_role: callable, current_user_id: callable, current_user_roles: callable}
 */
function ezdoc_resolve_role_provider(): array
{
    if (isset($GLOBALS['__ezdoc_role_provider_override']) && is_array($GLOBALS['__ezdoc_role_provider_override'])) {
        return $GLOBALS['__ezdoc_role_provider_override'];
    }
    return ezdoc_get_role_provider();
}
