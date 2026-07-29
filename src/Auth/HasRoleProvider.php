<?php

declare(strict_types=1);

namespace Ezdoc\Auth;

/**
 * Default RoleProvider — a shim over conventions a legacy monolith host may
 * already have:
 *   - `hasRole()` global function
 *   - `$author_id` global (current user id)
 *   - `$author_role_array` global (current user roles array)
 *
 * A host with framework auth (Laravel, Symfony, Filament) or any custom
 * mechanism should implement {@see RoleProvider} and inject it via
 * `Context::withRoleProvider()` or `ezdoc_set_role_provider()`, and never touch
 * this class.
 *
 * ## Why it complains
 *
 * Being the default makes this shim the thing a host gets when it wires
 * nothing at all — and it answers "user 0, no roles" to every question,
 * quietly. That silence is a trap: authorization keeps working (everything is
 * simply denied, or allowed by a permissive default), audit entries record an
 * actor nobody can identify, and the misconfiguration surfaces much later
 * somewhere unrelated.
 *
 * So it says something, once per process, when the conventions it depends on
 * are missing. Define `EZDOC_SILENCE_AUTH_WARNING` to turn that off — demo and
 * anonymous deployments legitimately have no user.
 *
 * ## Precedent
 * Modeled after Symfony Security voter pattern + Laravel Gate abstraction —
 * decouples authorization logic from the underlying auth implementation.
 */
final class HasRoleProvider implements RoleProvider
{
    /** @var bool one complaint per process is a signal; every call is noise */
    private static $warned = false;

    /**
     * @param string|array<string> $roles
     */
    public function hasRole($roles): bool
    {
        if (!function_exists('hasRole')) {
            $this->warnOnce(
                'the host application defines no hasRole() function, so every role check '
                . 'answers false'
            );
            return false;
        }
        /** @var callable $fn */
        $fn = 'hasRole';
        return (bool) $fn($roles);
    }

    public function currentUserId(): int
    {
        $id = (int) ($GLOBALS['author_id'] ?? 0);

        if ($id <= 0) {
            $this->warnOnce($this->diagnoseMissingId());
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    public function currentUserRoles(): array
    {
        $roles = $GLOBALS['author_role_array'] ?? [];
        return is_array($roles) ? array_values(array_map('strval', $roles)) : [];
    }

    /**
     * Name the specific shape of the misconfiguration.
     *
     * Roles present but no id is worth calling out separately: it means the
     * host's bootstrap ran and populated one global but not the other, which
     * is a very different problem from auth never having been wired — and it
     * is the one that looks like everything is fine.
     */
    private function diagnoseMissingId(): string
    {
        $roles = $GLOBALS['author_role_array'] ?? null;

        if (is_array($roles) && $roles !== []) {
            return 'roles resolved but the user is unidentified: $GLOBALS[\'author_role_array\'] '
                . 'is populated while $GLOBALS[\'author_id\'] is missing or 0. The host bootstrap '
                . 'sets one but not the other — audit entries will record no actor, and any '
                . 'check that requires an identified user will refuse.';
        }

        return 'no authenticated user: $GLOBALS[\'author_id\'] is missing or 0 and no RoleProvider '
            . 'was injected. Implement Ezdoc\\Auth\\RoleProvider and pass it to '
            . 'Context::withRoleProvider() or ezdoc_set_role_provider().';
    }

    private function warnOnce(string $why): void
    {
        if (self::$warned) {
            return;
        }
        if (defined('EZDOC_SILENCE_AUTH_WARNING') && EZDOC_SILENCE_AUTH_WARNING) {
            return;
        }
        self::$warned = true;
        @error_log('[ezdoc:auth] ' . $why);
    }

    /**
     * Reset the once-per-process latch.
     *
     * For tests, which need each case to observe the warning independently.
     */
    public static function resetWarningState(): void
    {
        self::$warned = false;
    }
}
