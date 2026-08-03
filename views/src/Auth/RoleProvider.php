<?php

declare(strict_types=1);

namespace Ezdoc\Auth;

/**
 * Role/user context provider — abstraction supaya library bisa dipakai di
 * berbagai framework (Laravel, Symfony, WordPress, plain PHP).
 *
 * Consumer implement interface ini sesuai auth system-nya:
 *
 * @example Laravel:
 *   class LaravelRoleProvider implements RoleProvider {
 *     public function hasRole(string|array $roles): bool {
 *       return auth()->user()?->hasRole($roles) ?? false;
 *     }
 *     public function currentUserId(): int {
 *       return auth()->id() ?? 0;
 *     }
 *     public function currentUserRoles(): array {
 *       return auth()->user()?->roles->pluck('name')->all() ?? [];
 *     }
 *   }
 *
 * @example WordPress:
 *   class WPRoleProvider implements RoleProvider {
 *     public function hasRole(string|array $roles): bool {
 *       $user = wp_get_current_user();
 *       $userRoles = $user->roles ?? [];
 *       $rolesArr = is_array($roles) ? $roles : [$roles];
 *       return !empty(array_intersect($rolesArr, $userRoles));
 *     }
 *     // ...
 *   }
 */
interface RoleProvider
{
    /**
     * Check apakah user current punya salah satu role.
     *
     * Signature TIDAK pakai union type `string|array` supaya PHP 7.4 compatible.
     * Implementor wajib handle both types di implementasi.
     *
     * @param string|array<string> $roles Single role atau array (OR logic).
     */
    public function hasRole($roles): bool;

    /**
     * Get current user's ID. Return 0 kalau tidak login.
     */
    public function currentUserId(): int;

    /**
     * Get current user's roles list.
     * @return array<string>
     */
    public function currentUserRoles(): array;
}
