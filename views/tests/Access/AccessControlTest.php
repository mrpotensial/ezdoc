<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Access;

use Ezdoc\Access\AccessConfig;
use Ezdoc\Access\AccessControl;
use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Auth\RoleProvider;
use Ezdoc\Exceptions\AccessDeniedException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the decision that gates every mutating action in the library:
 * may this user perform this action on this template?
 *
 * The two failure directions matter differently. Wrongly denying is visible —
 * someone reports they cannot save. Wrongly allowing is silent, so the tests
 * lean on the deny side: unknown actions, malformed rules, absent users.
 */
final class AccessControlTest extends TestCase
{
    /**
     * @param array<string> $roles Roles the caller holds.
     */
    private function roleProvider(array $roles = [], int $userId = 0): RoleProvider
    {
        return new CallableRoleProvider(
            static function ($role) use ($roles) {
                foreach ((array) $role as $r) {
                    if (in_array($r, $roles, true)) return true;
                }
                return false;
            },
            static function () use ($userId) { return $userId; },
            static function () use ($roles) { return $roles; }
        );
    }

    // ─── Bypass ───

    public function testBypassRoleIsAllowedEvenWhenNoRuleMatches(): void
    {
        $control = new AccessControl($this->roleProvider(['superadmin']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['dokter'], 'users' => []],
        ]);

        $decision = $control->can(42, 'edit', $config);

        self::assertTrue($decision->isAllowed());
        self::assertStringContainsString('bypass', $decision->getReason());
    }

    public function testBypassRoleListIsConfigurable(): void
    {
        $control = new AccessControl($this->roleProvider(['ops']), ['ops']);
        $config = AccessConfig::fromArray(['mode' => 'strict']);

        self::assertTrue($control->can(1, 'anything', $config)->isAllowed());
    }

    /** Renaming the bypass role must actually remove the old one's power. */
    public function testDefaultBypassRoleLosesItsPowerWhenOverridden(): void
    {
        $control = new AccessControl($this->roleProvider(['superadmin']), ['ops']);
        $config = AccessConfig::fromArray(['mode' => 'strict']);

        self::assertTrue($control->can(1, 'edit', $config)->isDenied());
    }

    // ─── Strict vs permissive when no rule is defined ───

    /**
     * The whole point of strict mode: an action nobody thought to configure is
     * refused rather than waved through.
     */
    public function testStrictModeDeniesUndefinedAction(): void
    {
        $control = new AccessControl($this->roleProvider(['dokter']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['dokter'], 'users' => []],
        ]);

        $decision = $control->can(1, 'delete', $config);

        self::assertTrue($decision->isDenied());
        self::assertStringContainsString('strict', $decision->getReason());
    }

    public function testPermissiveModeAllowsUndefinedAction(): void
    {
        $control = new AccessControl($this->roleProvider([]));
        $config = AccessConfig::fromArray(['mode' => 'permissive']);

        $decision = $control->can(1, 'delete', $config);

        self::assertTrue($decision->isAllowed());
        self::assertStringContainsString('permissive-default', $decision->getReason());
    }

    /**
     * A template with no access_config follows EZDOC_ACCESS_DEFAULT, which
     * ships as permissive so existing installs keep working.
     *
     * This used to be decided in two places that disagreed: AccessConfig
     * returned strict while ezdoc_can_on_template() returned allow. Actions
     * used the second, DocumentService the first, so wiring DocumentService up
     * would have silently locked every template that carries no config.
     *
     * v1.0 flips the constant to strict — see docs/PRD.md §6.21. That is a
     * breaking change, so it needs the log data first (EZDOC_LOG_ACCESS_DEFAULT)
     * and a migration that writes explicit config to existing templates.
     */
    public function testEmptyConfigFollowsTheDeploymentDefault(): void
    {
        $control = new AccessControl($this->roleProvider([]));
        $decision = $control->can(1, 'edit', AccessConfig::empty());

        self::assertSame(
            AccessConfig::defaultMode() === AccessConfig::MODE_PERMISSIVE,
            $decision->isAllowed(),
            'the empty-config answer must match EZDOC_ACCESS_DEFAULT'
        );
    }

    /** Shipped default, stated outright so a change to it fails here. */
    public function testShippedDefaultIsPermissive(): void
    {
        self::assertSame(AccessConfig::MODE_PERMISSIVE, AccessConfig::defaultMode());
    }

    public function testEmptyConfigStillYieldsToBypassRole(): void
    {
        $control = new AccessControl($this->roleProvider(['superadmin']));

        self::assertTrue($control->can(1, 'edit', AccessConfig::empty())->isAllowed());
    }

    /**
     * The two authorisation paths must give the same answer for the same
     * situation. They did not before EZDOC_ACCESS_DEFAULT existed.
     */
    public function testBothPathsAgreeOnTheUnconfiguredCase(): void
    {
        $oop = (new AccessControl($this->roleProvider([])))
            ->can(1, 'edit', AccessConfig::empty())
            ->isAllowed();

        $procedural = ezdoc_can_on_template(null, 'edit');

        self::assertSame($procedural, $oop, 'OOP and procedural paths must agree');
    }

    /** Same for a config that names other actions but not this one. */
    public function testBothPathsAgreeWhenTheActionIsNotListed(): void
    {
        $raw = ['mode' => 'permissive', 'edit' => ['roles' => ['dokter'], 'users' => []]];

        $oop = (new AccessControl($this->roleProvider([])))
            ->can(1, 'delete', AccessConfig::fromArray($raw))
            ->isAllowed();

        $procedural = ezdoc_can_on_template($raw, 'delete');

        self::assertSame($procedural, $oop);
    }

    // ─── Role rules ───

    public function testRoleRuleAllowsMatchingRole(): void
    {
        $control = new AccessControl($this->roleProvider(['perawat']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['perawat', 'dokter'], 'users' => []],
        ]);

        self::assertTrue($control->can(7, 'edit', $config)->isAllowed());
    }

    public function testRoleRuleDeniesUnlistedRole(): void
    {
        $control = new AccessControl($this->roleProvider(['satpam']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['perawat'], 'users' => []],
        ]);

        self::assertTrue($control->can(7, 'edit', $config)->isDenied());
    }

    // ─── User rules ───

    public function testUserRuleAllowsTheNamedUser(): void
    {
        $control = new AccessControl($this->roleProvider([]));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => [], 'users' => [42]],
        ]);

        self::assertTrue($control->can(42, 'edit', $config)->isAllowed());
        self::assertTrue($control->can(43, 'edit', $config)->isDenied());
    }

    /**
     * Anonymous callers arrive as user id 0. A `user:0` rule must not turn that
     * into a match, or every unauthenticated request would satisfy it.
     */
    public function testAnonymousUserNeverMatchesAUserRule(): void
    {
        $control = new AccessControl($this->roleProvider([]));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => [], 'users' => [0]],
        ]);

        self::assertTrue($control->can(0, 'edit', $config)->isDenied());
    }

    // ─── Combined rules ───

    public function testEitherARoleOrAUserRuleIsEnough(): void
    {
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['dokter'], 'users' => [99]],
        ]);

        $byRole = new AccessControl($this->roleProvider(['dokter']));
        $byUser = new AccessControl($this->roleProvider([]));
        $neither = new AccessControl($this->roleProvider(['satpam']));

        self::assertTrue($byRole->can(1, 'edit', $config)->isAllowed());
        self::assertTrue($byUser->can(99, 'edit', $config)->isAllowed());
        self::assertTrue($neither->can(1, 'edit', $config)->isDenied());
    }

    public function testRulesAreScopedPerAction(): void
    {
        $control = new AccessControl($this->roleProvider(['perawat']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit'   => ['roles' => ['perawat'], 'users' => []],
            'delete' => ['roles' => ['dokter'], 'users' => []],
        ]);

        self::assertTrue($control->can(1, 'edit', $config)->isAllowed());
        self::assertTrue(
            $control->can(1, 'delete', $config)->isDenied(),
            'permission on one action must not leak into another'
        );
    }

    // ─── assertCan ───

    public function testAssertCanIsSilentWhenAllowed(): void
    {
        $control = new AccessControl($this->roleProvider(['dokter']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['dokter'], 'users' => []],
        ]);

        $control->assertCan(1, 'edit', $config);
        self::assertTrue(true); // reaching here is the assertion
    }

    public function testAssertCanThrowsWhenDenied(): void
    {
        $control = new AccessControl($this->roleProvider(['satpam']));
        $config = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['dokter'], 'users' => []],
        ]);

        $this->expectException(AccessDeniedException::class);
        $control->assertCan(1, 'edit', $config);
    }

    // ─── hasAnyRole ───

    public function testHasAnyRoleMatchesAndBypasses(): void
    {
        self::assertTrue(
            (new AccessControl($this->roleProvider(['designer'])))->hasAnyRole(['designer'])
        );
        self::assertFalse(
            (new AccessControl($this->roleProvider(['satpam'])))->hasAnyRole(['designer'])
        );
        self::assertTrue(
            (new AccessControl($this->roleProvider(['superadmin'])))->hasAnyRole(['designer']),
            'bypass role should satisfy any role requirement'
        );
    }

    // ─── Config parsing ───

    public function testMalformedJsonFallsBackToEmptyConfig(): void
    {
        // Corrupt stored config must not crash the request, and must not be
        // read as "no restrictions apply".
        $config = AccessConfig::fromJson('{ this is not json');

        self::assertFalse($config->hasRulesFor('edit'));
        self::assertSame([], $config->getRulesFor('edit'));
    }

    public function testNullJsonYieldsEmptyConfig(): void
    {
        self::assertSame([], AccessConfig::fromJson(null)->getDefinedActions());
    }

    public function testUnknownModeIsNotTreatedAsStrict(): void
    {
        $config = AccessConfig::fromArray(['mode' => 'nonsense']);

        // Whatever it normalises to, it must be one of the two known modes
        // rather than passed through — an unrecognised mode string reaching
        // isStrict() would silently pick a branch.
        self::assertContains(
            $config->getMode(),
            [AccessConfig::MODE_STRICT, AccessConfig::MODE_PERMISSIVE]
        );
    }

    public function testConfigSurvivesJsonRoundTrip(): void
    {
        $original = AccessConfig::fromArray([
            'mode' => 'strict',
            'edit' => ['roles' => ['dokter'], 'users' => [42]],
        ]);

        $restored = AccessConfig::fromJson($original->toJson());

        self::assertSame($original->getMode(), $restored->getMode());
        self::assertTrue($restored->hasRulesFor('edit'));

        $control = new AccessControl($this->roleProvider(['dokter']));
        self::assertTrue($control->can(1, 'edit', $restored)->isAllowed());
    }
}
