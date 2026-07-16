<?php

declare(strict_types=1);

namespace Ezdoc\Auth;

/**
 * Default RoleProvider — backward-compat shim yang wrap consumer app's own
 * globals (untuk legacy monolith consumer apps yang punya existing pattern):
 *   - `hasRole()` global function
 *   - `$author_id` global (current user id)
 *   - `$author_role_array` global (current user roles array)
 *
 * Consumer app yang pakai framework auth (Laravel, Symfony, Filament) atau
 * custom auth mechanism → implement {@see RoleProvider} sendiri + inject via
 * `Context::withRoleProvider()` atau `ezdoc_set_role_provider()`.
 *
 * ## Precedent
 * Modeled after Symfony Security voter pattern + Laravel Gate abstraction —
 * decouples authorization logic from underlying auth implementation.
 */
final class HasRoleProvider implements RoleProvider
{
    /**
     * @param string|array<string> $roles
     */
    public function hasRole($roles): bool
    {
        if (!function_exists('hasRole')) {
            return false;
        }
        /** @var callable $fn */
        $fn = 'hasRole';
        return (bool) $fn($roles);
    }

    public function currentUserId(): int
    {
        return (int) ($GLOBALS['author_id'] ?? 0);
    }

    public function currentUserRoles(): array
    {
        $roles = $GLOBALS['author_role_array'] ?? [];
        return is_array($roles) ? array_values(array_map('strval', $roles)) : [];
    }
}
