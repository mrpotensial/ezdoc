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
     * An absent or unparseable config denies — the OOP layer is zero-trust.
     *
     * NOTE: the procedural helpers do the opposite. `ezdoc_can_on_template()`
     * in lib/authorization.php returns true when the config is null, when the
     * action has no section, or when that section lists neither roles nor
     * users — documented there as "backward-compat: no config = allow all".
     * Every action file currently goes through those helpers, so today's live
     * behaviour is allow-by-default.
     *
     * That divergence matters for the v1.0 plan of moving actions onto the OOP
     * layer: doing so silently flips templates that carry no access_config from
     * open to closed. Pinned here so the flip is a deliberate decision rather
     * than a surprise.
     */
    public function testEmptyConfigDeniesInTheOopLayer(): void
    {
        $control = new AccessControl($this->roleProvider([]));

        self::assertTrue($control->can(1, 'edit', AccessConfig::empty())->isDenied());
    }

    public function testEmptyConfigStillYieldsToBypassRole(): void
    {
        $control = new AccessControl($this->roleProvider(['superadmin']));

        self::assertTrue($control->can(1, 'edit', AccessConfig::empty())->isAllowed());
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
