<?php

declare(strict_types=1);

namespace Ezdoc\Access;

use Ezdoc\Auth\RoleProvider;
use Ezdoc\Exceptions\AccessDeniedException;

/**
 * Ezdoc\Access\AccessControl — RBAC service testable & swappable.
 *
 * Menerima AccessConfig + action + user context, return AccessDecision.
 * Wrap-around lama `ezdoc_can*` functions — sekarang OOP + testable + composable.
 *
 * PHP 7.4+ compatible.
 *
 * @example
 *   $ac = new AccessControl($roleProvider);
 *   $config = AccessConfig::fromJson($template['access_config']);
 *
 *   $decision = $ac->can($userId, 'edit', $config);
 *   if ($decision->isDenied()) {
 *     $logger->denied('template.edit', $decision->getReason());
 *     return;
 *   }
 *
 *   // Alternative: throw on deny
 *   $ac->assertCan($userId, 'edit', $config);
 */
final class AccessControl
{
    /** @var RoleProvider */
    private $roleProvider;

    /** @var array<string> Roles yang bypass semua check (mis. ['superadmin']). */
    private $bypassRoles;

    /**
     * @param array<string> $bypassRoles Roles yang boleh bypass check (superadmin etc.)
     */
    public function __construct(RoleProvider $roleProvider, array $bypassRoles = ['superadmin'])
    {
        $this->roleProvider = $roleProvider;
        $this->bypassRoles = array_values(array_map('strval', $bypassRoles));
    }

    /**
     * Check apakah user berhak melakukan action.
     * Return AccessDecision — inspect via ->isAllowed() atau ->getReason().
     */
    public function can(int $userId, string $action, AccessConfig $config): AccessDecision
    {
        // 1. Bypass check (superadmin)
        foreach ($this->bypassRoles as $bypassRole) {
            if ($this->roleProvider->hasRole($bypassRole)) {
                return AccessDecision::allow("bypass:role:{$bypassRole}");
            }
        }

        // 2. Kalau config tidak define rules untuk action ini:
        //    - permissive mode → allow (backward compat)
        //    - strict mode     → deny (default zero-trust)
        if (!$config->hasRulesFor($action)) {
            if ($config->isStrict()) {
                return AccessDecision::deny(
                    "No rules defined for action '{$action}' (strict mode)"
                );
            }
            return AccessDecision::allow('permissive-default');
        }

        // 3. Check all rules — first match wins.
        foreach ($config->getRulesFor($action) as $rule) {
            if ($rule->matches($this->roleProvider, $userId)) {
                return AccessDecision::allow($rule->toString());
            }
        }

        // 4. No rule matched — deny.
        $ruleList = array_map(
            function ($r) { return $r->toString(); },
            $config->getRulesFor($action)
        );
        return AccessDecision::deny(
            "User does not match any rule for '{$action}' (rules: " . implode(', ', $ruleList) . ')'
        );
    }

    /**
     * Assert version — throw AccessDeniedException kalau denied.
     *
     * @throws AccessDeniedException
     */
    public function assertCan(int $userId, string $action, AccessConfig $config): void
    {
        $decision = $this->can($userId, $action, $config);
        if ($decision->isDenied()) {
            throw AccessDeniedException::forAction(
                $action,
                $userId,
                $decision->getReason()
            );
        }
    }

    /**
     * Convenience: check hanya berdasarkan required roles (tanpa AccessConfig template-level).
     * Dipakai untuk global RBAC check (mis. "hanya template_designer boleh akses designer").
     *
     * @param array<string> $requiredRoles
     */
    public function hasAnyRole(array $requiredRoles): bool
    {
        // Bypass check
        foreach ($this->bypassRoles as $bypassRole) {
            if ($this->roleProvider->hasRole($bypassRole)) return true;
        }

        // Match any role
        foreach ($requiredRoles as $role) {
            if ($this->roleProvider->hasRole((string) $role)) return true;
        }

        return false;
    }
}
