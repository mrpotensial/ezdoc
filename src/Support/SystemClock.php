<?php

declare(strict_types=1);

namespace Ezdoc\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock. Default everywhere a {@see Clock} is optional.
 *
 * ## Timezone
 *
 * Defaults to whatever PHP is configured with, which is what the rest of the
 * library already writes into `DATETIME` columns via `date()`. Passing a zone
 * explicitly is supported for hosts that keep storage in UTC while the request
 * runs in local time.
 *
 * Note this stores wall-clock time, not a monotonic counter. It is the right
 * tool for "expires at 14:32" and the wrong one for measuring elapsed duration
 * across an NTP correction — no deadline in this library is short enough for
 * that distinction to bite.
 */
final class SystemClock implements Clock
{
    private ?DateTimeZone $tz;

    public function __construct(?DateTimeZone $tz = null)
    {
        $this->tz = $tz;
    }

    public function now(): DateTimeImmutable
    {
        return $this->tz !== null
            ? new DateTimeImmutable('now', $this->tz)
            : new DateTimeImmutable('now');
    }
}
