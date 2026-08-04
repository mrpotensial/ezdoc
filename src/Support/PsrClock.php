<?php

declare(strict_types=1);

namespace Ezdoc\Support;

use DateTimeImmutable;

/**
 * Adapts a PSR-20 `Psr\Clock\ClockInterface` to {@see Clock}.
 *
 * The two contracts are the same single method, so this is a pass-through. It
 * exists so a host that already has a clock in its container — Symfony, or
 * anything using `psr/clock` — injects that one instead of ezdoc running on a
 * second, independent notion of "now".
 *
 * Deliberately untyped against `Psr\Clock\ClockInterface`: naming the class in a
 * type hint would make this file fatal on hosts that never installed the
 * package, which is the exact coupling {@see Clock} exists to avoid. The
 * constructor checks for the method instead.
 */
final class PsrClock implements Clock
{
    /** @var object PSR-20 clock — anything exposing `now(): DateTimeImmutable`. */
    private object $inner;

    /**
     * @param object $psrClock Typically `Psr\Clock\ClockInterface`.
     */
    public function __construct(object $psrClock)
    {
        if (!method_exists($psrClock, 'now')) {
            throw new \InvalidArgumentException(
                'Expected a PSR-20 clock (an object with a now() method), got ' . get_class($psrClock)
            );
        }

        $this->inner = $psrClock;
    }

    public function now(): DateTimeImmutable
    {
        /** @var mixed $now */
        $now = $this->inner->now();

        if (!$now instanceof DateTimeImmutable) {
            throw new \UnexpectedValueException(
                'The wrapped clock returned ' . (is_object($now) ? get_class($now) : gettype($now))
                . ' instead of DateTimeImmutable.'
            );
        }

        return $now;
    }
}
