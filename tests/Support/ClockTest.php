<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Ezdoc\Support\Clock;
use Ezdoc\Support\FrozenClock;
use Ezdoc\Support\PsrClock;
use Ezdoc\Support\SystemClock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function testSystemClockReportsSomethingCloseToNow(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
    }

    public function testSystemClockHonoursAnExplicitTimezone(): void
    {
        $clock = new SystemClock(new DateTimeZone('UTC'));

        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    /**
     * Callers treat the result as a value they may hold on to. If two calls
     * returned the same object, a caller mutating one would move the other.
     */
    public function testEachCallReturnsItsOwnInstance(): void
    {
        $clock = new SystemClock();

        self::assertNotSame($clock->now(), $clock->now());
    }

    public function testFrozenClockDoesNotMoveOnItsOwn(): void
    {
        $clock = new FrozenClock('2026-08-03 10:00:00');

        self::assertSame('2026-08-03 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-03 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testFrozenClockAdvancesAndRewinds(): void
    {
        $clock = new FrozenClock('2026-08-03 10:00:00');

        self::assertSame('2026-08-03 10:10:00', $clock->advance(600)->now()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-03 10:09:00', $clock->advance(-60)->now()->format('Y-m-d H:i:s'));
    }

    public function testFrozenClockJumpsToAnAbsoluteMoment(): void
    {
        $clock = new FrozenClock('2026-08-03 10:00:00');
        $clock->set(new DateTimeImmutable('2027-01-01 00:00:00'));

        self::assertSame('2027-01-01 00:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testFrozenClockIsAClock(): void
    {
        self::assertInstanceOf(Clock::class, new FrozenClock());
        self::assertInstanceOf(Clock::class, new SystemClock());
    }

    // ─── PSR-20 bridge ───

    public function testPsrClockPassesThroughTheWrappedValue(): void
    {
        $psr = new class {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-03 12:34:56');
            }
        };

        self::assertSame(
            '2026-08-03 12:34:56',
            (new PsrClock($psr))->now()->format('Y-m-d H:i:s')
        );
    }

    /**
     * The type hint is `object` on purpose — naming Psr\Clock\ClockInterface
     * would make the file fatal on hosts that never installed the package. The
     * check has to happen at runtime instead, so it needs a test.
     */
    public function testPsrClockRejectsSomethingThatIsNotAClock(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PsrClock(new \stdClass());
    }

    public function testPsrClockRejectsAWrongReturnType(): void
    {
        $liar = new class {
            /** @return string */
            public function now()
            {
                return 'half past four';
            }
        };

        $this->expectException(\UnexpectedValueException::class);

        (new PsrClock($liar))->now();
    }
}
