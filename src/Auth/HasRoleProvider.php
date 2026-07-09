<?php

declare(strict_types=1);

namespace Ezdoc\Auth;

/**
 * Default RoleProvider — wraps koneksi.php globals:
 *   - hasRole() function
 *   - $author_id
 *   - $author_role_array
 *
 * Untuk koneksi.php-based monolith apps, ini adalah default provider.
 * Untuk library consumer di app lain, implement RoleProvider sendiri.
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
