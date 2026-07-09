<?php

declare(strict_types=1);

namespace Ezdoc\Tests;

use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use PHPUnit\Framework\TestCase;

/**
 * Test Ezdoc\Context DI container behavior.
 *
 * PHP 7.4+ compatible — pakai positional args (bukan named args yang PHP 8.0+).
 * Arrow functions `fn` OK karena PHP 7.4.
 *
 * Note: Context integration test butuh actual mysqli — dilakukan di tests/integration/.
 * Test unit sini fokus ke Context lifecycle & providers.
 */
final class ContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::resetDefault();
        unset($GLOBALS['conn']);
    }

    public function testCallableRoleProviderWorks(): void
    {
        $rp = new CallableRoleProvider(
            fn($roles) => in_array('admin', is_array($roles) ? $roles : [$roles], true),
            fn() => 42,
            fn() => ['admin', 'user']
        );

        $this->assertTrue($rp->hasRole('admin'));
        $this->assertFalse($rp->hasRole('nobody'));
        $this->assertTrue($rp->hasRole(['nobody', 'admin']));
        $this->assertSame(42, $rp->currentUserId());
        $this->assertSame(['admin', 'user'], $rp->currentUserRoles());
    }

    public function testCallableRoleProviderNormalizesRolesToStrings(): void
    {
        $rp = new CallableRoleProvider(
            fn($r) => true,
            fn() => 1,
            fn() => [42, 'admin', true] // mixed types
        );

        $roles = $rp->currentUserRoles();
        $this->assertContainsOnly('string', $roles);
    }

    public function testFromGlobalsThrowsWithoutConn(): void
    {
        unset($GLOBALS['conn']);
        $this->expectException(\RuntimeException::class);
        Context::fromGlobals();
    }

    public function testResetDefaultClearsInstance(): void
    {
        $mockDb = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $rp = new CallableRoleProvider(
            fn($r) => false,
            fn() => 1,
            fn() => []
        );

        Context::setDefault(new Context($mockDb, $rp));
        $this->assertNotNull(Context::default());

        Context::resetDefault();
        unset($GLOBALS['conn']);

        // Now default() akan try fromGlobals() → throws
        $this->expectException(\RuntimeException::class);
        Context::default();
    }

    public function testContextIsImmutableViaWithRoleProvider(): void
    {
        $mockDb = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $rp1 = new CallableRoleProvider(fn($r) => false, fn() => 1, fn() => []);
        $rp2 = new CallableRoleProvider(fn($r) => true, fn() => 2, fn() => ['admin']);

        $ctx1 = new Context($mockDb, $rp1);
        $ctx2 = $ctx1->withRoleProvider($rp2);

        $this->assertNotSame($ctx1, $ctx2, 'withRoleProvider harus return new instance');
        $this->assertSame($rp1, $ctx1->roleProvider);
        $this->assertSame($rp2, $ctx2->roleProvider);
        $this->assertSame($mockDb, $ctx1->db);
        $this->assertSame($mockDb, $ctx2->db);
    }
}
