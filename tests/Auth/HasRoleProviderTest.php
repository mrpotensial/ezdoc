<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Auth;

use Ezdoc\Auth\HasRoleProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the default RoleProvider — the one a host gets when it wires nothing.
 *
 * Most of these tests are about what it *says*, not what it returns. Returning
 * "user 0, no roles" is the correct answer when there is no user; saying
 * nothing about it is what turns a five-minute wiring mistake into a bug found
 * weeks later somewhere unrelated.
 */
final class HasRoleProviderTest extends TestCase
{
    private HasRoleProvider $provider;

    /** @var array<string,mixed> */
    private array $savedGlobals = [];

    private string $logFile = '';
    private string $previousLogDestination = '';

    protected function setUp(): void
    {
        $this->provider = new HasRoleProvider();
        HasRoleProvider::resetWarningState();

        foreach (['author_id', 'author_role_array'] as $key) {
            $this->savedGlobals[$key] = $GLOBALS[$key] ?? null;
            unset($GLOBALS[$key]);
        }

        // Capture error_log() output so the warnings can be asserted on.
        $this->previousLogDestination = (string) ini_get('error_log');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ezdoc_authlog');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLogDestination);
        if ($this->logFile !== '') {
            @unlink($this->logFile);
        }

        foreach ($this->savedGlobals as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }

        HasRoleProvider::resetWarningState();
    }

    private function loggedOutput(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    // ─── Reading the host's conventions ───

    public function testReadsTheUserIdFromTheHostGlobal(): void
    {
        $GLOBALS['author_id'] = 42;

        self::assertSame(42, $this->provider->currentUserId());
    }

    public function testCoercesAStringIdToAnInteger(): void
    {
        $GLOBALS['author_id'] = '42'; // sessions commonly hold strings

        self::assertSame(42, $this->provider->currentUserId());
    }

    public function testReadsRolesFromTheHostGlobal(): void
    {
        $GLOBALS['author_role_array'] = ['user', 'superadmin'];

        self::assertSame(['user', 'superadmin'], $this->provider->currentUserRoles());
    }

    public function testRolesAreNormalisedToAListOfStrings(): void
    {
        // Some hosts key roles by id; callers expect a plain list.
        $GLOBALS['author_role_array'] = [7 => 'dokter', 9 => 'perawat'];

        self::assertSame(['dokter', 'perawat'], $this->provider->currentUserRoles());
    }

    public function testANonArrayRolesGlobalYieldsNoRolesRatherThanAnError(): void
    {
        $GLOBALS['author_role_array'] = 'superadmin';

        self::assertSame([], $this->provider->currentUserRoles());
    }

    public function testMissingGlobalsYieldAnAnonymousUser(): void
    {
        self::assertSame(0, $this->provider->currentUserId());
        self::assertSame([], $this->provider->currentUserRoles());
    }

    // ─── Saying so ───

    public function testAnUnidentifiedUserIsReported(): void
    {
        $this->provider->currentUserId();

        self::assertStringContainsString('[ezdoc:auth]', $this->loggedOutput());
        self::assertStringContainsString('no authenticated user', $this->loggedOutput());
    }

    /**
     * The case that prompted this: a host bootstrap that populates roles but
     * not the id. Everything looks wired — role checks pass, superadmin
     * bypasses work — right up until something asks who the user is.
     */
    public function testRolesWithoutAnIdGetTheirOwnDiagnosis(): void
    {
        $GLOBALS['author_role_array'] = ['user', 'superadmin'];

        $this->provider->currentUserId();
        $logged = $this->loggedOutput();

        self::assertStringContainsString('roles resolved but the user is unidentified', $logged);
        self::assertStringNotContainsString(
            'no authenticated user',
            $logged,
            'the two situations need different messages — they have different fixes'
        );
    }

    public function testAnIdentifiedUserIsNotReported(): void
    {
        $GLOBALS['author_id'] = 42;

        $this->provider->currentUserId();

        self::assertSame('', $this->loggedOutput());
    }

    /** A log line per call would bury the signal it is trying to raise. */
    public function testTheWarningIsEmittedOncePerProcess(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->provider->currentUserId();
        }

        self::assertSame(1, substr_count($this->loggedOutput(), '[ezdoc:auth]'));
    }

    public function testAMissingHasRoleFunctionIsReported(): void
    {
        if (function_exists('hasRole')) {
            self::markTestSkipped('The host under test defines hasRole().');
        }

        self::assertFalse($this->provider->hasRole('superadmin'));
        self::assertStringContainsString('defines no hasRole() function', $this->loggedOutput());
    }

    /**
     * Demo and anonymous deployments have no user by design; warning on every
     * request would train people to ignore the channel.
     */
    public function testTheWarningCanBeSilenced(): void
    {
        if (!defined('EZDOC_SILENCE_AUTH_WARNING')) {
            define('EZDOC_SILENCE_AUTH_WARNING', true);
        }
        if (!EZDOC_SILENCE_AUTH_WARNING) {
            self::markTestSkipped('EZDOC_SILENCE_AUTH_WARNING already defined as false.');
        }

        HasRoleProvider::resetWarningState();
        $this->provider->currentUserId();

        self::assertSame('', $this->loggedOutput());
    }
}
