<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * The gate that decides who sees IP addresses and other people's role lists in
 * the document history.
 *
 * ## Why this is worth its own test file
 *
 * The rule it enforces is the *opposite* of its neighbour. An empty role list
 * makes `ezdoc_can_manage_templates()` return true — allow all, for backward
 * compatibility with the versions before template RBAC was split out. Here an
 * empty list returns false.
 *
 * That inversion is the whole point, and it is exactly the kind of thing a
 * later "cleanup" makes consistent with its neighbour and quietly reopens. What
 * is being guarded is not a feature but somebody else's personal data.
 *
 * @see \docs\260804-pengungkapan-identitas.md
 */
final class AuditDetailAccessTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/role_provider.php';
        require_once dirname(__DIR__, 2) . '/lib/authorization.php';
        ezdoc_reset_role_provider();
    }

    protected function tearDown(): void
    {
        ezdoc_reset_role_provider();
    }

    /**
     * @param list<string> $userRoles
     */
    private function actingAs(array $userRoles): void
    {
        ezdoc_set_role_provider([
            'has_role' => static function ($roles) use ($userRoles): bool {
                foreach ((array) $roles as $r) {
                    if (in_array($r, $userRoles, true)) {
                        return true;
                    }
                }

                return false;
            },
            'current_user_id'    => static function (): int { return 7; },
            'current_user_roles' => static function () use ($userRoles): array { return $userRoles; },
        ]);
    }

    /**
     * The one that matters. A privacy control whose default is open protects
     * only the installations that got around to configuring it — and the ones
     * that did not are precisely the ones that needed protecting.
     */
    public function testNobodySeesDetailWhenNothingIsConfigured(): void
    {
        $this->actingAs(['superadmin', 'admin', 'dokter']);

        self::assertFalse(
            ezdoc_can_view_audit_detail([]),
            'an empty role list must deny, not allow — see the class docblock'
        );
    }

    public function testAConfiguredRoleSeesDetail(): void
    {
        $this->actingAs(['auditor']);

        self::assertTrue(ezdoc_can_view_audit_detail(['auditor']));
    }

    public function testEveryoneElseDoesNot(): void
    {
        $this->actingAs(['dokter', 'perawat']);

        self::assertFalse(ezdoc_can_view_audit_detail(['auditor']));
    }

    /** Any one of the listed roles is enough, matching ezdoc_has_role(). */
    public function testAnyOneOfTheListedRolesIsEnough(): void
    {
        $this->actingAs(['kepala_rekam_medis']);

        self::assertTrue(ezdoc_can_view_audit_detail(['superadmin', 'kepala_rekam_medis']));
    }

    /**
     * The library must not assume a role is called "superadmin". Hosts name
     * their own roles, and some are not role-based at all — they point the
     * setting at a permission name and let their own provider interpret it.
     */
    public function testNoRoleNameIsBakedIn(): void
    {
        $this->actingAs(['audit.view_ip']);

        self::assertTrue(ezdoc_can_view_audit_detail(['audit.view_ip']));
        self::assertFalse(ezdoc_can_view_audit_detail(['superadmin']));
    }

    /** A user with no roles at all never qualifies. */
    public function testAnAnonymousUserSeesNothing(): void
    {
        $this->actingAs([]);

        self::assertFalse(ezdoc_can_view_audit_detail(['superadmin']));
    }

    // ─── Reading the setting ───

    public function testTheSettingIsReadFromConfigurationWhenNotPassed(): void
    {
        $this->actingAs(['superadmin']);

        // The constant is absent under the test bootstrap (phpunit boots
        // autoload.php, not config.php), so the configured list is empty — and
        // an empty list denies.
        self::assertSame([], ezdoc_audit_detail_roles());
        self::assertFalse(ezdoc_can_view_audit_detail());
    }
}
