<?php

declare(strict_types=1);

namespace Ezdoc\Auth;

/**
 * RoleProvider implementation via 3 closures — quick setup tanpa bikin class.
 *
 * @example
 *   $rp = new CallableRoleProvider(
 *     hasRole: fn($roles) => auth()->user()?->hasRole($roles) ?? false,
 *     currentUserId: fn() => auth()->id() ?? 0,
 *     currentUserRoles: fn() => auth()->user()?->getRoleNames()->all() ?? [],
 *   );
 *   $ctx = new Ezdoc\Context($db, $rp);
 */
final class CallableRoleProvider implements RoleProvider
{
    /** @var callable(string|array<string>): bool */
    private $hasRoleFn;

    /** @var callable(): int */
    private $currentUserIdFn;

    /** @var callable(): array<string> */
    private $currentUserRolesFn;

    public function __construct(
        callable $hasRole,
        callable $currentUserId,
        callable $currentUserRoles
    ) {
        $this->hasRoleFn = $hasRole;
        $this->currentUserIdFn = $currentUserId;
        $this->currentUserRolesFn = $currentUserRoles;
    }

    /**
     * @param string|array<string> $roles
     */
    public function hasRole($roles): bool
    {
        return (bool) ($this->hasRoleFn)($roles);
    }

    public function currentUserId(): int
    {
        return (int) ($this->currentUserIdFn)();
    }

    public function currentUserRoles(): array
    {
        $result = ($this->currentUserRolesFn)();
        return is_array($result) ? array_values(array_map('strval', $result)) : [];
    }
}
