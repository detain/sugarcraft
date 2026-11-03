<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * The library's time source.
 *
 * Monotonic by construction: hrtime() is immune to NTP steps, DST
 * transitions, and manual clock changes, which wall-clock time
 * (microtime) is not. Anything that measures ELAPSED time — cache TTL
 * freshness, retry budgets — must use this and never microtime(true), or a
 * clock jump can instantly expire every entry or freeze every budget.
 *
 * @internal  Library plumbing — not part of the public API. Consumers that
 *            need injectable time pass their own closure instead
 *            (AsyncCache::new(clock:), AsyncOps::retry(now:)).
 */
final class Clock
{
    /**
     * Seconds since an arbitrary fixed point, guaranteed non-decreasing.
     */
    public static function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
