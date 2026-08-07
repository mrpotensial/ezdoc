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
 * $service = new ScanOtpService(null, $clock);
 * $issued = $service->issue($templateId, $ttdId, $docId);
 *
 * $clock->advance(301);                      // one second past a five-minute QR
 * $service->resolve($issued['scan_token']);  // → expired, with no waiting
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
