<?php

declare(strict_types=1);

namespace Ezdoc\Support;

use DateTimeImmutable;

/**
 * A clock that only moves when told to.
 *
 * Lives in `src/` rather than `tests/` on purpose: hosts write tests against
 * ezdoc too, and a test double they cannot reach is a test double that gets
 * reimplemented badly. Symfony ships `MockClock` in the component itself for
 * the same reason.
 *
 * ```php
 * $clock = new FrozenClock('2026-08-03 10:00:00');
 * $service = new OtpVoucherService(null, $clock);
 * $service->issue('62812...');
 *
 * $clock->advance(601);            // one second past a ten-minute code
 * $service->status('62812...');    // → expired, with no waiting
 * ```
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $at;

    /**
     * @param DateTimeImmutable|string $at Any format `DateTimeImmutable` accepts.
     */
    public function __construct($at = 'now')
    {
        $this->at = $at instanceof DateTimeImmutable ? $at : new DateTimeImmutable((string) $at);
    }

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }

    /**
     * Move forward (or backward, with a negative value) by whole seconds.
     *
     * Returns `$this` so a test can set up and step in one expression.
     */
    public function advance(int $seconds): self
    {
        $this->at = $this->at->modify(($seconds >= 0 ? '+' : '-') . abs($seconds) . ' seconds');

        return $this;
    }

    /**
     * Jump to an absolute moment.
     *
     * @param DateTimeImmutable|string $at
     */
    public function set($at): self
    {
        $this->at = $at instanceof DateTimeImmutable ? $at : new DateTimeImmutable((string) $at);

        return $this;
    }
}
