<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests\Support;

/**
 * A hand-advanced clock for AsyncCache's `clock:` seam.
 *
 * Lives in its own autoloadable file because more than one test file uses it.
 * Declared inside AsyncCacheBoundedTest.php it resolved in AsyncCacheTest only
 * when the bounded test file had already been loaded: always under serial
 * phpunit, which loads every test file before running any, and not in a
 * ParaTest worker, which loads one file per dispatch ("Class not found").
 */
final class FakeClock
{
    public function __construct(private float $now = 1000.0)
    {
    }

    public function __invoke(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
