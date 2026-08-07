<?php

declare(strict_types=1);

namespace Ezdoc\Support;

use DateTimeImmutable;

/**
 * The current time, as a dependency rather than a global fact.
 *
 * ## Why this exists
 *
 * Anything with an expiry can only be tested honestly if the test can move the
 * clock. Calling `time()` inside the logic leaves two options, both bad: sleep
 * through the real duration, or leave the boundary untested and hope.
 *
 * That matters most where a whole feature is made of deadlines — a code that
 * lives ten minutes, a cooldown between requests, a "valid for 7 more minutes"
 * label. Those numbers are the feature. They deserve tests that can actually
 * reach the moment before and the moment after.
 *
 * ## Why not just require psr/clock
 *
 * The signature here is deliberately identical to PSR-20 `ClockInterface`, and
 * {@see PsrClock} adapts one in a single line. Keeping our own declaration means
 * hosts that deploy by copying files — no Composer run on the target — are not
 * blocked on installing a package to use the library at all.
 *
 * When a host does have PSR-20, wrap it:
 *
 * ```php
 * new OtpService($repo, null, new PsrClock($container->get(ClockInterface::class)));
 * ```
 *
 * Prior art for the shape: lcobucci/clock and Symfony's Clock component both
 * settled on exactly this one method before PSR-20 standardised it.
 */
interface Clock
{
    /**
     * The current point in time.
     *
     * Implementations MUST return a new instance on every call — callers treat
     * the result as a value and may hold on to it.
     */
    public function now(): DateTimeImmutable;
}
