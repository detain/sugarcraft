<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * How one nvidia-smi query ended — the distinction Gpu's memoization
 * hinges on: Absent is permanent (no binary, driver refuses, non-zero
 * exit), Timeout is transient (a GPU busy resetting or a slow driver
 * call) and must be retried, never memoized.
 */
enum GpuOutcome
{
    case Ok;
    case Absent;
    case Timeout;
}
