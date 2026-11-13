<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * Tears down whatever a test armed on the SHARED `Loop::get()`.
 *
 * WHY: product entry points the tests launch for real (`Bootstrap::chat()`
 * and friends, AgentWorkerPool's loop probe, the palette catalog read, the
 * OTLP transport, the accept-storm pause timer) queue future ticks, timers and
 * streams on the facade loop. A test that never runs the loop leaves them
 * armed, which {@see SharedLoopResidueGuard} treats as a failure. Each test
 * owns the loop state it caused, so it disposes of it here rather than the
 * guard being loosened or exempted.
 *
 * Replacing the loop discards every pending timer, tick and stream watcher in
 * one step -- the same disposal the guard performs after recording an
 * offender -- and runs as an #[After] hook so a class's own tearDown() is
 * untouched. Nothing is run: a leftover tick would act on objects the test has
 * already dropped.
 */
trait DrainsSharedLoopTrait
{
    #[After]
    protected function drainSharedLoop(): void
    {
        if (\array_sum(SharedLoopResidue::census()) === 0) {
            return;
        }
        Loop::set(new StreamSelectLoop());
    }
}
